<?php

namespace BankImport;

// Shared half-cent tolerance lives in Amount. Required explicitly so this helper
// is self-contained under Dolibarr's runtime, which does not register the module's
// composer autoloader (BankImport.class.php loads each helper with a flat require).
require_once __DIR__ . '/Amount.php';

/**
 * Pure helper that parses the verification-relevant blocks of a CAMT.053 document
 * (<Bal>, <TxsSummry>, <Ntry>) and aggregates per-statement state for comparison
 * with what was actually written to llx_bank.
 *
 * Zero Dolibarr coupling — the class operates only on SimpleXMLElement input and
 * plain arrays, so it is unit-testable without the runtime. See
 * tests/Unit/StatementSummaryParseTest (AggregateTest and VerifyTest are planned
 * follow-ups in the same v0.0.13 cycle).
 */
class StatementSummary
{
    /**
     * Compare two amounts with the half-cent tolerance. Thin wrapper kept so the
     * many call sites below read naturally; the tolerance and the comparison both
     * live in the shared Amount helper (AGG-6 / VERIFY-5), so verification and
     * reconciliation share one source of truth. Inline abs() comparisons remain
     * forbidden; every amount check routes through here or Amount::match() directly.
     */
    private static function amountsMatch(float $a, float $b): bool
    {
        return Amount::match($a, $b);
    }

    /**
     * Parse a CAMT.053 document into one entry per <Stmt> block.
     *
     * The caller must pass a SimpleXMLElement whose default namespace has been
     * stripped (mirrors what BankImport::processFileXml does on the live import
     * path), so element access via property syntax works without an xpath dance.
     *
     * Each returned array element corresponds to one <Stmt> and has the shape:
     *
     *   [
     *     'id'                    => string,     // <Stmt><Id>
     *     'electronic_seq_nb'     => string,     // <Stmt><ElctrncSeqNb>
     *     'currency'              => string,     // <Stmt><Acct><Ccy>
     *     'opbd'                  => float|null, // signed (CdtDbtInd applied)
     *     'clbd'                  => float|null, // signed
     *     'summary_available'     => bool,       // true iff <TxsSummry> present
     *     'count'                 => int|null,   // NbOfNtries; null if no summary
     *     'credit_count'          => int|null,
     *     'debit_count'           => int|null,
     *     'credit_sum'            => float|null, // unsigned (bank emits positive)
     *     'debit_sum'             => float|null, // unsigned
     *     'net_entry'             => float|null, // signed (NetEntry CdtDbtInd applied)
     *     'entries'               => array<string, array{signed: float, currency: string}>,
     *                                            //   keyed by AcctSvcrRef
     *     'unaddressable_entries' => list<array{signed: float, currency: string}>,
     *                                            //   entries with no AcctSvcrRef
     *   ]
     *
     * Sign convention throughout:
     *  - CRDT in the source XML becomes a positive number.
     *  - DBIT becomes a negative number.
     *  - Credit/debit SUMS in TxsSummry are emitted by the bank as positive
     *    gross sums per direction; the +/- semantics is implied by which
     *    bucket they live in, so we leave them unsigned in the result.
     *
     * @param \SimpleXMLElement $document Root <Document> element of a CAMT.053 file
     * @return list<array<string, mixed>> One entry per <Stmt>.
     */
    public static function parse(\SimpleXMLElement $document): array
    {
        $result = [];
        if (!isset($document->BkToCstmrStmt)) {
            return $result;
        }
        foreach ($document->BkToCstmrStmt->Stmt as $stmt) {
            $result[] = self::parseStmt($stmt);
        }
        return $result;
    }

    /**
     * Parse a single <Stmt> block. Split from parse() so multi-Stmt files
     * (e.g. one Stmt per currency in a multi-currency Revolut account) are
     * handled by iteration in the caller, not by branching here.
     */
    private static function parseStmt(\SimpleXMLElement $stmt): array
    {
        $currency = (string) $stmt->Acct->Ccy;

        [$opbd, $clbd] = self::extractBalances($stmt);
        $summary = self::extractSummary($stmt);
        [$entries, $unaddressable] = self::extractEntries($stmt, $currency);

        return [
            'id'                    => (string) $stmt->Id,
            'electronic_seq_nb'     => (string) $stmt->ElctrncSeqNb,
            'currency'              => $currency,
            'opbd'                  => $opbd,
            'clbd'                  => $clbd,
            'summary_available'     => $summary['summary_available'],
            'count'                 => $summary['count'],
            'credit_count'          => $summary['credit_count'],
            'debit_count'           => $summary['debit_count'],
            'credit_sum'            => $summary['credit_sum'],
            'debit_sum'             => $summary['debit_sum'],
            'net_entry'             => $summary['net_entry'],
            'entries'               => $entries,
            'unaddressable_entries' => $unaddressable,
        ];
    }

    /**
     * Find OPBD and CLBD Bal blocks. A Stmt may emit several Bal blocks
     * (OPBD, CLBD, plus optional PRCD/CLAV/ITBD/FWAV variants); we pick
     * exactly the opening and closing booking balances. Each Bal carries
     * its own CdtDbtInd — an overdraft account has DBIT here with a
     * positive Amt that we must negate.
     *
     * @return array{0: float|null, 1: float|null} [opbd, clbd] signed
     */
    private static function extractBalances(\SimpleXMLElement $stmt): array
    {
        $opbd = null;
        $clbd = null;
        foreach ($stmt->Bal as $bal) {
            $code = (string) $bal->Tp->CdOrPrtry->Cd;
            $amount = self::signedAmount($bal);
            if ($code === 'OPBD') {
                $opbd = $amount;
            } elseif ($code === 'CLBD') {
                $clbd = $amount;
            }
        }
        return [$opbd, $clbd];
    }

    /**
     * Extract TxsSummry totals. Two layers of optionality, both honored:
     *
     *  1. The whole <TxsSummry> block may be absent (some banks omit it
     *     even though CAMT.053 strongly recommends it). Then every count/sum
     *     field is null and summary_available=false; verify() skips all
     *     count/sum checks for this Stmt.
     *  2. When the block IS present, the four sub-blocks (TtlNtries,
     *     TtlCdtNtries, TtlDbtNtries, plus TtlNetNtry inside TtlNtries)
     *     are each independently optional. Each missing one degrades the
     *     corresponding result field to null so verify() can skip just
     *     that specific check instead of false-positiving against a
     *     fabricated zero. summary_available stays true to indicate that
     *     the outer block is parseable; per-field nullability is the
     *     finer-grained signal.
     *
     * @return array{summary_available: bool, count: int|null, credit_count: int|null,
     *               debit_count: int|null, credit_sum: float|null,
     *               debit_sum: float|null, net_entry: float|null}
     */
    private static function extractSummary(\SimpleXMLElement $stmt): array
    {
        $nulled = [
            'summary_available' => false,
            'count'             => null,
            'credit_count'      => null,
            'debit_count'       => null,
            'credit_sum'        => null,
            'debit_sum'         => null,
            'net_entry'         => null,
        ];
        if (!isset($stmt->TxsSummry)) {
            return $nulled;
        }
        $summary = $stmt->TxsSummry;

        $count = null;
        $netEntry = null;
        if (isset($summary->TtlNtries)) {
            $count = self::intOrNull($summary->TtlNtries, 'NbOfNtries');
            if (isset($summary->TtlNtries->TtlNetNtry)) {
                $netEntry = self::signedAmount($summary->TtlNtries->TtlNetNtry);
            }
        }

        $creditCount = null;
        $creditSum = null;
        if (isset($summary->TtlCdtNtries)) {
            $creditCount = self::intOrNull($summary->TtlCdtNtries, 'NbOfNtries');
            $creditSum = self::floatOrNull($summary->TtlCdtNtries, 'Sum');
        }

        $debitCount = null;
        $debitSum = null;
        if (isset($summary->TtlDbtNtries)) {
            $debitCount = self::intOrNull($summary->TtlDbtNtries, 'NbOfNtries');
            $debitSum = self::floatOrNull($summary->TtlDbtNtries, 'Sum');
        }

        return [
            'summary_available' => true,
            'count'             => $count,
            'credit_count'      => $creditCount,
            'debit_count'       => $debitCount,
            'credit_sum'        => $creditSum,
            'debit_sum'         => $debitSum,
            'net_entry'         => $netEntry,
        ];
    }

    /**
     * Cast an optional SimpleXML child to int, or return null when absent.
     * Lifts the `isset() ? (int) : null` pattern used six times across
     * extractSummary() into a single readable expression.
     */
    private static function intOrNull(\SimpleXMLElement $parent, string $child): ?int
    {
        return isset($parent->{$child}) ? (int) $parent->{$child} : null;
    }

    /** Float counterpart of intOrNull(). */
    private static function floatOrNull(\SimpleXMLElement $parent, string $child): ?float
    {
        return isset($parent->{$child}) ? (float) $parent->{$child} : null;
    }

    /**
     * Walk every <Ntry>, key those carrying an <AcctSvcrRef> into the
     * `entries` map and drop the rest into `unaddressable_entries`. The
     * separation matters because verify()'s per-entry check (#3) can only
     * compare expected-vs-actual for refs it can address; anonymous entries
     * can still be tallied into the sum check (#2) but not pinpointed if
     * a discrepancy surfaces.
     *
     * @return array{0: array<string, array{signed: float, currency: string}>,
     *               1: list<array{signed: float, currency: string}>}
     */
    private static function extractEntries(\SimpleXMLElement $stmt, string $stmtCurrency): array
    {
        $byRef = [];
        $unaddressable = [];
        foreach ($stmt->Ntry as $ntry) {
            $signed = self::signedAmount($ntry);
            // <Amt> attribute Ccy overrides the Stmt-level currency when present
            // (rare in practice but allowed by the schema).
            $currency = (string) $ntry->Amt['Ccy'];
            if ($currency === '') {
                $currency = $stmtCurrency;
            }
            $ref = (string) $ntry->AcctSvcrRef;
            if ($ref === '') {
                $unaddressable[] = ['signed' => $signed, 'currency' => $currency];
            } elseif (isset($byRef[$ref])) {
                // Duplicate AcctSvcrRef in source (bank bug or malformed export):
                // aggregate signed amounts under the shared key so a false-skip
                // of any duplicate later surfaces as a per-entry sum mismatch
                // against DB rather than getting hidden by a silent overwrite.
                // Currency from the first occurrence is kept.
                $byRef[$ref]['signed'] += $signed;
            } else {
                $byRef[$ref] = ['signed' => $signed, 'currency' => $currency];
            }
        }
        return [$byRef, $unaddressable];
    }

    /**
     * Read an <Amt> + <CdtDbtInd> pair (the standard CAMT.053 way of carrying
     * a signed monetary value) and return a signed float. CRDT keeps the
     * positive Amt as-is, DBIT negates it.
     */
    private static function signedAmount(\SimpleXMLElement $node): float
    {
        $amount = (float) $node->Amt;
        $direction = (string) $node->CdtDbtInd;
        return $direction === 'DBIT' ? -$amount : $amount;
    }

    /**
     * Stmt-level gate that runs BEFORE verify(), deciding whether per-check
     * verification can produce trustworthy results at all. It exists because
     * three distinct runtime conditions otherwise collapse into the same
     * misleading symptom: an empty (or untrustworthy) actual-state fed to
     * verify() yields a 'mismatch' storm — every expected entry reported
     * MISSING — that hides the real cause from the user.
     *
     * The coupled caller (BankImport::verifyImport) supplies the runtime facts
     * it observed while reading actuals back out of llx_bank; this method stays
     * pure so the policy is unit-testable without a database.
     *
     * Returns either:
     *   - a single disposition record that REPLACES the normal verify() output
     *     for this Stmt (status 'error' or 'skipped'), or
     *   - null, meaning the preconditions are sound — the caller runs verify().
     *
     * Priority (highest first), each mapping to a reviewer finding:
     *   1. $queryFailed (#1) → 'error'. A failed SELECT read back no rows;
     *      reporting mismatches would blame the data for an infrastructure
     *      fault. The concrete DB error text is the caller's to attach (only it
     *      owns $this->db->lasterror()).
     *   2. $scopeEmpty (#4) → 'skipped'. num_releve = '' (a CAMT.053 file missing
     *      the mandatory <Stmt><Id>) makes WHERE num_releve = '' match every
     *      prior empty-id row, so even a non-empty result set is untrustworthy.
     *   3. $rowsFound === 0 while the Stmt expected rows (#2) → 'skipped'. Almost
     *      always a re-import of data stored before v0.0.13 added per-statement
     *      scoping (old rows have num_releve NULL/''), so nothing matches the new
     *      scope. One honest "could not locate imported rows" beats N per-entry
     *      MISSING records. This fires ONLY at zero rows: when SOME rows are
     *      present, verify()'s per_entry check still surfaces partial false-skips,
     *      which is the headline reason verification exists.
     *
     * A Stmt that legitimately expected nothing (no entries, no unaddressable,
     * count 0/null) and found zero rows is consistent, not anomalous: returns
     * null so verify() confirms the clean empty-vs-empty match.
     *
     * @param array<string, mixed> $expectedStmt One element of parse()'s output list.
     * @param bool $queryFailed Whether the caller's read-back SELECT returned false.
     * @param bool $scopeEmpty  Whether the scoping key (num_releve = <Stmt><Id>) was ''.
     * @param int  $rowsFound   Number of llx_bank rows the caller read for this Stmt.
     * @return array<string, mixed>|null Disposition record, or null to run verify().
     */
    public static function verificationPrecondition(
        array $expectedStmt,
        bool $queryFailed,
        bool $scopeEmpty,
        int $rowsFound
    ): ?array {
        $stmtId = (string) ($expectedStmt['id'] ?? '');

        if ($queryFailed) {
            return self::disposition(
                $stmtId,
                'error',
                'Could not read back the imported rows for this statement (database error); verification did not run.'
            );
        }

        if ($scopeEmpty) {
            return self::disposition(
                $stmtId,
                'skipped',
                'Statement has no <Id>, so imported rows cannot be scoped reliably (num_releve would match unrelated rows); verification skipped.'
            );
        }

        if ($rowsFound === 0 && self::stmtExpectsRows($expectedStmt)) {
            return self::disposition(
                $stmtId,
                'skipped',
                'No imported rows found for this statement id (num_releve). Likely a re-import of data stored before per-statement scoping (v0.0.13); verification skipped.'
            );
        }

        return null;
    }

    /** Build a stmt-level disposition record in the shared 8-field verify() shape. */
    private static function disposition(string $stmtId, string $status, string $detail): array
    {
        return [
            'check'    => 'verification',
            'stmt'     => $stmtId,
            'status'   => $status,
            'ref'      => null,
            'expected' => null,
            'actual'   => null,
            'detail'   => $detail,
        ];
    }

    /**
     * Whether a parsed Stmt expected any rows to be imported — true if it has
     * any ref entries, any unaddressable entries, or a positive TxsSummry count.
     * Distinguishes "zero rows is an anomaly" (#2) from "zero rows is correct
     * for an empty statement".
     */
    private static function stmtExpectsRows(array $expectedStmt): bool
    {
        if (!empty($expectedStmt['entries'])) {
            return true;
        }
        if (!empty($expectedStmt['unaddressable_entries'])) {
            return true;
        }
        return (int) ($expectedStmt['count'] ?? 0) > 0;
    }

    /**
     * Pair a parse() result for one <Stmt> with the matching aggregate() result
     * (from llx_bank rows WHERE num_releve = $expectedStmt['id']) and emit a
     * flat list of check-result records driving the UI / audit log.
     *
     * One record per check type per Stmt, except per_entry which emits ZERO
     * records when every expected ref matches and ONE record per mismatch
     * (carrying 'ref') otherwise. Order matches the VERIFY-3 contract:
     * count → credit_sum → debit_sum → net_entry → per_entry → unaddressable_sum.
     *
     * Each record:
     *   ['check'    => string,    // 'count' | 'credit_sum' | 'debit_sum' | 'net_entry' | 'per_entry' | 'unaddressable_sum'
     *    'stmt'     => string,    // $expectedStmt['id']
     *    'status'   => 'ok' | 'mismatch' | 'skipped',
     *    'ref'      => ?string,   // set only for per_entry detail records
     *    'expected' => mixed,
     *    'actual'   => mixed,
     *    'detail'   => string]    // human-readable reason (especially for 'skipped' and per_entry)
     *
     * Degradation: when the corresponding expected field is null (PARSE-1 payoff —
     * either the whole <TxsSummry> was absent, or a sub-block within it), the
     * scalar check is emitted as status='skipped' instead of comparing against
     * a fabricated zero. per_entry and unaddressable_sum always run because they
     * derive from <Ntry> data, which is independent of <TxsSummry> presence.
     *
     * AGG-8: NO credit_count + debit_count == count cross-check is emitted —
     * zero-net entries would break the identity by construction.
     * AGG-10: per_entry compares ONLY ['signed'] from both sides; currency lives
     * in parse() only and is paired at Stmt level by the caller.
     *
     * Single source of tolerance: every amount comparison goes through
     * amountsMatch(), which delegates to Amount::match() (the shared half-cent
     * tolerance). No inline abs() anywhere.
     *
     * @param array<string, mixed> $expectedStmt One element of parse()'s output list.
     * @param array<string, mixed> $actual       aggregate() output for the same Stmt.
     * @return list<array<string, mixed>> Check-result records; empty if both inputs
     *                                    are empty (i.e. no checks ran).
     */
    public static function verify(array $expectedStmt, array $actual): array
    {
        $stmtId = (string) ($expectedStmt['id'] ?? '');
        $checks = [];

        // Check 1: count — int equality, no tolerance.
        $checks[] = self::checkScalar(
            'count',
            $stmtId,
            $expectedStmt['count'] ?? null,
            $actual['count'] ?? null,
            false
        );

        // Checks 2-4: float sums — tolerance compare.
        foreach (['credit_sum', 'debit_sum', 'net_entry'] as $field) {
            $checks[] = self::checkScalar(
                $field,
                $stmtId,
                $expectedStmt[$field] ?? null,
                $actual[$field] ?? null,
                true
            );
        }

        // Check 5: per_entry — emit one record per discrepant ref; no records
        // when every expected ref matches the corresponding actual ref. Reads
        // only ['signed'] from both sides per AGG-10 — currency lives on parse()
        // entries but is intentionally absent from aggregate() entries.
        foreach (self::checkPerEntries(
            $stmtId,
            $expectedStmt['entries'] ?? [],
            $actual['entries'] ?? []
        ) as $perEntry) {
            $checks[] = $perEntry;
        }

        // Check 6: unaddressable_sum — refless entries cannot be addressed
        // individually, so we compare their summed signed amounts only.
        $expectedSum = self::sumSigned($expectedStmt['unaddressable_entries'] ?? []);
        $actualSum   = self::sumSigned($actual['unaddressable_entries'] ?? []);
        $matches = self::amountsMatch($expectedSum, $actualSum);
        $checks[] = [
            'check'    => 'unaddressable_sum',
            'stmt'     => $stmtId,
            'status'   => $matches ? 'ok' : 'mismatch',
            'ref'      => null,
            'expected' => $expectedSum,
            'actual'   => $actualSum,
            'detail'   => $matches
                ? 'Refless entries sum matches within tolerance.'
                : sprintf('Refless entries sum: expected %s, got %s.', self::fmt($expectedSum), self::fmt($actualSum)),
        ];

        return $checks;
    }

    /**
     * Emit one scalar check record. When the expected value is null (parse()
     * could not extract it because the relevant <TxsSummry> sub-block was
     * absent — PARSE-1 payoff) we report status='skipped' so verify() does
     * not fabricate a complaint against missing oracle data.
     *
     * @param mixed $expected
     * @param mixed $actual
     */
    private static function checkScalar(
        string $name,
        string $stmtId,
        $expected,
        $actual,
        bool $compareUsingTolerance
    ): array {
        if ($expected === null) {
            return [
                'check'    => $name,
                'stmt'     => $stmtId,
                'status'   => 'skipped',
                'ref'      => null,
                'expected' => null,
                'actual'   => $actual,
                'detail'   => "Skipped: expected value not available in parse() output (source XML omitted it).",
            ];
        }

        $matches = $compareUsingTolerance
            ? self::amountsMatch((float) $expected, (float) ($actual ?? 0))
            : $expected === $actual;

        return [
            'check'    => $name,
            'stmt'     => $stmtId,
            'status'   => $matches ? 'ok' : 'mismatch',
            'ref'      => null,
            'expected' => $expected,
            'actual'   => $actual,
            'detail'   => $matches
                ? 'Match.'
                : sprintf("Expected %s, got %s.", self::fmt($expected), self::fmt($actual)),
        ];
    }

    /**
     * Compare expected entries map against actual entries map and yield one
     * mismatch record per discrepant ref. Three kinds of discrepancy:
     *   - ref in expected but missing in actual (possible false-skip on import)
     *   - ref in both but signed amount differs (sign flip / wrong amount)
     *   - ref in actual but missing in expected (duplicate / manual entry / wrong num_releve)
     *
     * Refs that match within tolerance emit no record at all (keeps the
     * happy-path output short and lets the caller infer per_entry success
     * from the absence of mismatch records).
     *
     * @return list<array<string, mixed>>
     */
    private static function checkPerEntries(string $stmtId, array $expectedEntries, array $actualEntries): array
    {
        $mismatches = [];

        foreach ($expectedEntries as $ref => $expEntry) {
            $expectedSigned = (float) $expEntry['signed'];
            if (!isset($actualEntries[$ref])) {
                $mismatches[] = [
                    'check'    => 'per_entry',
                    'stmt'     => $stmtId,
                    'status'   => 'mismatch',
                    'ref'      => (string) $ref,
                    'expected' => $expectedSigned,
                    'actual'   => null,
                    'detail'   => 'Entry missing in actual (possible false-skip on import).',
                ];
                continue;
            }
            $actualSigned = (float) $actualEntries[$ref]['signed'];
            if (!self::amountsMatch($expectedSigned, $actualSigned)) {
                $mismatches[] = [
                    'check'    => 'per_entry',
                    'stmt'     => $stmtId,
                    'status'   => 'mismatch',
                    'ref'      => (string) $ref,
                    'expected' => $expectedSigned,
                    'actual'   => $actualSigned,
                    'detail'   => sprintf(
                        'Amount mismatch: expected %s, got %s.',
                        self::fmt($expectedSigned),
                        self::fmt($actualSigned)
                    ),
                ];
            }
        }

        foreach ($actualEntries as $ref => $actEntry) {
            if (!isset($expectedEntries[$ref])) {
                $mismatches[] = [
                    'check'    => 'per_entry',
                    'stmt'     => $stmtId,
                    'status'   => 'mismatch',
                    'ref'      => (string) $ref,
                    'expected' => null,
                    'actual'   => (float) $actEntry['signed'],
                    'detail'   => 'Entry in actual not present in expected source (duplicate, manual, or wrong num_releve).',
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Sum the 'signed' field across a list of unaddressable entries. Pulled
     * out so both expected and actual sides go through the same path and
     * the verify() body stays readable.
     */
    private static function sumSigned(array $entries): float
    {
        $sum = 0.0;
        foreach ($entries as $entry) {
            $sum += (float) ($entry['signed'] ?? 0);
        }
        return $sum;
    }

    /** Lightweight value-to-string for diagnostic 'detail' messages. */
    private static function fmt($value): string
    {
        if ($value === null) return 'null';
        if (is_float($value)) return sprintf('%.4f', $value);
        return (string) $value;
    }

    /**
     * Aggregate the per-statement rows we have actually stored in llx_bank
     * for a given <Stmt> into the same shape as parse() emits, so verify()
     * can pair the two element-for-element.
     *
     * Input is intentionally minimal — a list of plain rows already projected
     * by the caller out of SQL: each row is ['ref' => ?string, 'amount' => float].
     * Storage shape (note text, num_chq, num_releve, table prefix, currency
     * column, …) deliberately stays out of this method so it remains a pure,
     * Dolibarr-free unit testable against fabricated inputs. The caller is
     * responsible for the SELECT and for projecting each row into this shape.
     *
     * Sign convention mirrors parse():
     *  - Row 'amount' is signed as stored in llx_bank (positive credit, negative debit).
     *  - credit_sum is the unsigned positive sum of per-entry signed totals > 0.
     *  - debit_sum is the unsigned positive absolute value of per-entry totals < 0.
     *  - net_entry = credit_sum − debit_sum, signed.
     *  - entries[ref]['signed'] holds the SIGNED per-ref aggregate (so v0.0.14
     *    splits that store gross + fee under one ref combine to the original Amt).
     *
     * Logical counts (count / credit_count / debit_count) are taken over the
     * RESULT of aggregation, not the raw row list — a ref carrying two
     * v0.0.14 split rows still counts as one logical entry. Rows that aggregate
     * to exactly zero are included in count but excluded from credit/debit
     * direction counts and sums (they net to nothing).
     *
     * Rows with null / empty 'ref' (CAMT.053 entries that lacked AcctSvcrRef
     * in the source and were therefore stored without a per-entry handle) go
     * to unaddressable_entries as a list — they still contribute to count and
     * to sums but verify()'s per-ref check cannot address them individually.
     *
     * No rounding here: float drift is preserved and the half-cent tolerance
     * lives exclusively in verify(), so we have one source of truth about
     * numeric equality.
     *
     * @param list<array{ref: ?string, amount: float|int|string}> $rows
     * @return array{count: int, credit_count: int, debit_count: int,
     *               credit_sum: float, debit_sum: float, net_entry: float,
     *               entries: array<string, array{signed: float}>,
     *               unaddressable_entries: list<array{signed: float}>}
     */
    public static function aggregate(array $rows): array
    {
        // First pass: route each row into per-ref aggregation or the
        // unaddressable bucket, summing signed amounts under shared refs.
        $byRef = [];
        $unaddressable = [];
        foreach ($rows as $row) {
            // Symmetric null-coalesce on both keys: caller contract (AGG-1) requires
            // both, but if a future SELECT misprojects, fall back silently to 0/null
            // rather than emit an "Undefined array key" warning that PHPUnit's
            // failOnWarning would surface as a test failure for an arguably benign
            // caller mistake. Aggregate stays pure regardless.
            $signed = (float) ($row['amount'] ?? 0);
            $ref = $row['ref'] ?? null;
            if ($ref === null || $ref === '') {
                $unaddressable[] = ['signed' => $signed];
            } elseif (isset($byRef[$ref])) {
                $byRef[$ref]['signed'] += $signed;
            } else {
                $byRef[$ref] = ['signed' => $signed];
            }
        }

        // Second pass: derive direction counts and sums from the AGGREGATED
        // entries (so v0.0.14 splits that net to one logical entry count
        // and sum once, not per row). Zero-net entries are still part of
        // count but contribute to neither direction.
        $creditSum = 0.0;
        $debitSum = 0.0;
        $creditCount = 0;
        $debitCount = 0;
        $logicalEntries = array_merge(array_values($byRef), $unaddressable);
        foreach ($logicalEntries as $entry) {
            $signed = $entry['signed'];
            if ($signed > 0) {
                $creditSum += $signed;
                $creditCount++;
            } elseif ($signed < 0) {
                $debitSum += -$signed;  // unsigned positive per AGG-5
                $debitCount++;
            }
        }

        return [
            'count'                 => count($byRef) + count($unaddressable),
            'credit_count'          => $creditCount,
            'debit_count'           => $debitCount,
            'credit_sum'            => $creditSum,
            'debit_sum'             => $debitSum,
            'net_entry'             => $creditSum - $debitSum,
            'entries'               => $byRef,
            'unaddressable_entries' => $unaddressable,
        ];
    }
}
