<?php

namespace BankImport;

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
}
