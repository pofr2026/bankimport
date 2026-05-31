<?php

namespace BankImport\Tests\Unit;

use BankImport\StatementSummary;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of StatementSummary::verify().
 *
 * verify() pairs one parse() Stmt result with the matching aggregate() result
 * and emits a flat list of check-result records. The contract was locked by
 * reviewer's VERIFY-1..7 brief; these tests pin down every detail of it:
 *
 *  - VERIFY-3: six check types, per-field null in expected → status='skipped'.
 *  - VERIFY-4: output is option (B) — full check-result list with status, so
 *    UI can render ✓/❌/— per check. per_entry emits 0 records on full match
 *    and one mismatch record per discrepant ref (carrying 'ref').
 *  - VERIFY-5: single AMOUNT_TOLERANCE = 0.005 (half-cent). Drift below is 'ok'.
 *  - VERIFY-6 / PARSE-1 payoff: partial <TxsSummry> degrades per sub-block.
 *  - AGG-8: count check uses raw count, not credit_count + debit_count.
 *  - AGG-10: per_entry compares only ['signed']; currency is parse-only.
 */
class StatementSummaryVerifyTest extends TestCase
{
    /**
     * Build a fully-populated, internally consistent expected-Stmt shape with
     * the given entries and (optional) unaddressable list. Individual tests
     * tweak specific fields to create the scenario they exercise — but the
     * baseline matches between expected and actual so that any mismatch the
     * test forces is the ONLY one verify() reports.
     *
     * @param array<string, float> $entrySignedByRef map ref => signed amount
     * @param list<float> $unaddressableSignedAmounts  list of refless signed amounts
     */
    private function buildExpected(array $entrySignedByRef, array $unaddressableSignedAmounts = []): array
    {
        $creditCount = 0;
        $debitCount = 0;
        $creditSum = 0.0;
        $debitSum = 0.0;
        foreach ([...array_values($entrySignedByRef), ...$unaddressableSignedAmounts] as $signed) {
            if ($signed > 0) {
                $creditCount++;
                $creditSum += $signed;
            } elseif ($signed < 0) {
                $debitCount++;
                $debitSum += -$signed;
            }
        }

        $entries = [];
        foreach ($entrySignedByRef as $ref => $signed) {
            $entries[$ref] = ['signed' => $signed, 'currency' => 'CHF'];
        }
        $unaddressable = array_map(
            fn(float $s) => ['signed' => $s, 'currency' => 'CHF'],
            $unaddressableSignedAmounts
        );

        return [
            'id' => 'stmt-fixture',
            'electronic_seq_nb' => '1',
            'currency' => 'CHF',
            'opbd' => 0.0,
            'clbd' => $creditSum - $debitSum,
            'summary_available' => true,
            'count' => count($entries) + count($unaddressable),
            'credit_count' => $creditCount,
            'debit_count' => $debitCount,
            'credit_sum' => $creditSum,
            'debit_sum' => $debitSum,
            'net_entry' => $creditSum - $debitSum,
            'entries' => $entries,
            'unaddressable_entries' => $unaddressable,
        ];
    }

    /**
     * Mirror of buildExpected but in aggregate()'s reduced shape: no id, no
     * currency at top level, no opbd/clbd, entries have no 'currency' (AGG-7),
     * unaddressable have no 'currency'.
     */
    private function buildActual(array $entrySignedByRef, array $unaddressableSignedAmounts = []): array
    {
        $creditCount = 0;
        $debitCount = 0;
        $creditSum = 0.0;
        $debitSum = 0.0;
        foreach ([...array_values($entrySignedByRef), ...$unaddressableSignedAmounts] as $signed) {
            if ($signed > 0) {
                $creditCount++;
                $creditSum += $signed;
            } elseif ($signed < 0) {
                $debitCount++;
                $debitSum += -$signed;
            }
        }

        $entries = [];
        foreach ($entrySignedByRef as $ref => $signed) {
            $entries[$ref] = ['signed' => $signed];
        }
        $unaddressable = array_map(fn(float $s) => ['signed' => $s], $unaddressableSignedAmounts);

        return [
            'count' => count($entries) + count($unaddressable),
            'credit_count' => $creditCount,
            'debit_count' => $debitCount,
            'credit_sum' => $creditSum,
            'debit_sum' => $debitSum,
            'net_entry' => $creditSum - $debitSum,
            'entries' => $entries,
            'unaddressable_entries' => $unaddressable,
        ];
    }

    /**
     * Find the first check record matching the given type and (optionally) ref.
     * Per VERIFY-4 each (check, ref) pair is unique within one verify() call,
     * so "first" === "only". Returns null when no record matches.
     */
    private function findCheck(array $checks, string $checkName, ?string $ref = null): ?array
    {
        foreach ($checks as $c) {
            if ($c['check'] === $checkName && ($c['ref'] ?? null) === $ref) {
                return $c;
            }
        }
        return null;
    }

    /** Happy path: expected and actual match exactly. No 'mismatch' records emitted. */
    public function test_verify_clean_match_returns_no_mismatches(): void
    {
        $expected = $this->buildExpected(['r1' => 60.00, 'r2' => 40.00]);
        $actual   = $this->buildActual(  ['r1' => 60.00, 'r2' => 40.00]);

        $result = StatementSummary::verify($expected, $actual);

        $mismatches = array_filter($result, fn($c) => $c['status'] === 'mismatch');
        $this->assertSame([], $mismatches, 'Clean match must emit zero mismatch records.');

        foreach (['count', 'credit_sum', 'debit_sum', 'net_entry', 'unaddressable_sum'] as $name) {
            $check = $this->findCheck($result, $name);
            $this->assertNotNull($check, "Always-emitted check '$name' is missing from output.");
            $this->assertSame('ok', $check['status'], "'$name' should be 'ok' on clean match.");
        }
        $this->assertNull($this->findCheck($result, 'per_entry'),
            'per_entry emits zero records when every ref matches.');
    }

    /** Count differs → 'count' check is 'mismatch' with expected/actual carried. */
    public function test_verify_count_mismatch_flagged(): void
    {
        $expected = $this->buildExpected(['r1' => 50.00, 'r2' => 50.00]);
        $actual   = $this->buildActual(  ['r1' => 50.00]);  // r2 missing → count differs

        $result = StatementSummary::verify($expected, $actual);

        $check = $this->findCheck($result, 'count');
        $this->assertSame('mismatch', $check['status']);
        $this->assertSame(2, $check['expected']);
        $this->assertSame(1, $check['actual']);
    }

    /** Sum differs by more than tolerance → 'credit_sum' is 'mismatch'. */
    public function test_verify_credit_sum_mismatch_beyond_tolerance(): void
    {
        $expected = $this->buildExpected(['r1' => 100.00]);
        // Build actual with the same shape, then tweak the credit_sum scalar
        // directly (the harness keeps everything else consistent; we want
        // ONLY the sum field to diverge so the test attributes the mismatch
        // to the credit_sum check, not to per_entry).
        $actual = $this->buildActual(['r1' => 100.00]);
        $actual['credit_sum'] = 100.50;  // 50 cents off — beyond 0.005 tolerance

        $result = StatementSummary::verify($expected, $actual);

        $check = $this->findCheck($result, 'credit_sum');
        $this->assertSame('mismatch', $check['status']);
        $this->assertEqualsWithDelta(100.00, $check['expected'], 0.005);
        $this->assertEqualsWithDelta(100.50, $check['actual'], 0.005);
    }

    /**
     * Drift within tolerance → 'credit_sum' stays 'ok'. Lock VERIFY-5: every
     * amount comparison uses AMOUNT_TOLERANCE = 0.005, so a 0.004 difference
     * (under half a cent) does not fail the check.
     */
    public function test_verify_sum_within_tolerance_is_ok(): void
    {
        $expected = $this->buildExpected(['r1' => 100.00]);
        $actual = $this->buildActual(['r1' => 100.00]);
        $actual['credit_sum'] = 100.004;  // 0.4 cents off — within tolerance

        $result = StatementSummary::verify($expected, $actual);

        $check = $this->findCheck($result, 'credit_sum');
        $this->assertSame('ok', $check['status'], 'Drift under half-cent must not fail credit_sum.');
    }

    /**
     * Sign-flipped per-entry amount (e.g. CRDT misread as DBIT during import)
     * → 'per_entry' mismatch record for that specific ref with both values
     * carried so UI can show "expected +100, got -100".
     */
    public function test_verify_per_entry_amount_mismatch_flagged(): void
    {
        $expected = $this->buildExpected(['r-flipped' => 100.00]);
        $actual = $this->buildActual(['r-flipped' => -100.00]);  // sign flipped

        $result = StatementSummary::verify($expected, $actual);

        $check = $this->findCheck($result, 'per_entry', 'r-flipped');
        $this->assertNotNull($check, 'A per_entry record must exist for the discrepant ref.');
        $this->assertSame('mismatch', $check['status']);
        $this->assertEqualsWithDelta(100.00, $check['expected'], 0.005);
        $this->assertEqualsWithDelta(-100.00, $check['actual'], 0.005);
    }

    /**
     * Ref present in expected but missing in actual → false-skip in import →
     * 'per_entry' mismatch with actual=null. The headline scenario verification
     * exists for in the first place.
     */
    public function test_verify_expected_ref_missing_from_actual_flagged(): void
    {
        $expected = $this->buildExpected(['r-imported' => 50.00, 'r-skipped' => 30.00]);
        $actual = $this->buildActual(['r-imported' => 50.00]);  // r-skipped missing

        $result = StatementSummary::verify($expected, $actual);

        $check = $this->findCheck($result, 'per_entry', 'r-skipped');
        $this->assertNotNull($check, 'A per_entry mismatch record must point at the false-skipped ref.');
        $this->assertSame('mismatch', $check['status']);
        $this->assertEqualsWithDelta(30.00, $check['expected'], 0.005);
        $this->assertNull($check['actual']);
    }

    /**
     * Ref present in actual but not in expected → an extra bank row not from
     * this statement (manual entry, duplicate import, or wrong num_releve) →
     * 'per_entry' mismatch with expected=null.
     */
    public function test_verify_actual_ref_not_in_expected_flagged(): void
    {
        $expected = $this->buildExpected(['r-from-file' => 100.00]);
        $actual = $this->buildActual(['r-from-file' => 100.00, 'r-extra' => 25.00]);

        $result = StatementSummary::verify($expected, $actual);

        $check = $this->findCheck($result, 'per_entry', 'r-extra');
        $this->assertNotNull($check, 'A per_entry mismatch record must point at the unexpected ref.');
        $this->assertSame('mismatch', $check['status']);
        $this->assertNull($check['expected']);
        $this->assertEqualsWithDelta(25.00, $check['actual'], 0.005);
    }

    /**
     * <TxsSummry> absent (summary_available=false) → count and the three sum
     * checks emit status='skipped' (not 'mismatch'), so verify() does not
     * fabricate a complaint against missing oracle data. per_entry and
     * unaddressable_sum still run.
     */
    public function test_verify_summary_unavailable_skips_count_and_sums(): void
    {
        $expected = $this->buildExpected(['r1' => 100.00]);
        // Force the "<TxsSummry> absent" shape that parse() emits.
        $expected['summary_available'] = false;
        $expected['count'] = null;
        $expected['credit_count'] = null;
        $expected['debit_count'] = null;
        $expected['credit_sum'] = null;
        $expected['debit_sum'] = null;
        $expected['net_entry'] = null;

        $actual = $this->buildActual(['r1' => 100.00]);

        $result = StatementSummary::verify($expected, $actual);

        foreach (['count', 'credit_sum', 'debit_sum', 'net_entry'] as $name) {
            $check = $this->findCheck($result, $name);
            $this->assertNotNull($check, "Check '$name' must still be present (as 'skipped'), not omitted.");
            $this->assertSame('skipped', $check['status'],
                "When expected['$name'] === null, verify() must skip the check, not flag mismatch.");
        }
        // per_entry and unaddressable_sum still meaningful — they derive from Ntry, not TxsSummry.
        $this->assertSame('ok', $this->findCheck($result, 'unaddressable_sum')['status']);
        $this->assertNull($this->findCheck($result, 'per_entry'),
            'No per_entry mismatch records when refs match.');
    }

    /**
     * Partial <TxsSummry> (TtlDbtNtries present, TtlCdtNtries absent) →
     * mixed: debit_sum still checked (status 'ok'), credit_sum skipped.
     * This is the direct payoff of PARSE-1 fix in commit 1082ac9 — without
     * per-subblock nullability, credit_sum=0 would false-positive against a
     * real non-zero DB sum.
     */
    public function test_verify_partial_summary_checks_present_skips_null(): void
    {
        $expected = $this->buildExpected(['r-debit' => -50.00]);
        // Simulate parse() output for a partial TxsSummry: TtlCdtNtries absent
        // (so credit_count + credit_sum are null) while TtlDbtNtries is present.
        $expected['credit_count'] = null;
        $expected['credit_sum'] = null;
        // count comes from TtlNtries which we keep present:
        // expected['count'] stays at the buildExpected default (= 1 here).
        // net_entry comes from TtlNetNtry which is independently optional:
        $expected['net_entry'] = null;

        $actual = $this->buildActual(['r-debit' => -50.00]);

        $result = StatementSummary::verify($expected, $actual);

        $this->assertSame('ok', $this->findCheck($result, 'count')['status'],
            'count is present in expected, must be checked.');
        $this->assertSame('skipped', $this->findCheck($result, 'credit_sum')['status'],
            "PARSE-1 payoff: credit_sum=null → skipped, not 'mismatch' against actual's 0.");
        $this->assertSame('ok', $this->findCheck($result, 'debit_sum')['status'],
            'debit_sum is present in expected, must be checked normally.');
        $this->assertSame('skipped', $this->findCheck($result, 'net_entry')['status']);
    }

    /**
     * Unaddressable entries (rows with num_releve but no ref) cannot be
     * compared per-ref, so verify() compares the SUM of signed amounts from
     * both lists with the standard tolerance.
     */
    public function test_verify_unaddressable_bucket_sum_compared(): void
    {
        $expected = $this->buildExpected(['r1' => 100.00], unaddressableSignedAmounts: [50.00, 25.00]);
        $actual = $this->buildActual(['r1' => 100.00], unaddressableSignedAmounts: [60.00]);  // 60 vs 75

        $result = StatementSummary::verify($expected, $actual);

        $check = $this->findCheck($result, 'unaddressable_sum');
        $this->assertSame('mismatch', $check['status']);
        $this->assertEqualsWithDelta(75.00, $check['expected'], 0.005);
        $this->assertEqualsWithDelta(60.00, $check['actual'], 0.005);
    }

    /**
     * AGG-8 guard: a ref whose actual signed amount aggregates to 0 (e.g. a
     * split that exactly cancels) makes credit_count + debit_count != count
     * on the actual side. verify()'s count check uses raw count, not the
     * credit_count + debit_count derivation, so this scenario must NOT emit
     * a phantom structural-mismatch record. count check should be 'ok' when
     * raw counts match.
     */
    public function test_verify_zero_net_actual_does_not_break_count(): void
    {
        $expected = $this->buildExpected(['r-zero' => 100.00]);
        $actual = $this->buildActual(['r-zero' => 100.00]);
        // Force the actual into a zero-net inconsistency: keep count=1 but
        // pretend credit_count + debit_count = 0 (one ref aggregating to 0).
        $actual['credit_count'] = 0;
        $actual['debit_count'] = 0;

        $result = StatementSummary::verify($expected, $actual);

        $countCheck = $this->findCheck($result, 'count');
        $this->assertSame('ok', $countCheck['status'],
            'AGG-8: count check must NOT depend on credit_count + debit_count consistency.');

        // No spurious 'structural'/'count_consistency' record should ever appear.
        foreach ($result as $rec) {
            $this->assertNotSame('count_consistency', $rec['check'],
                'AGG-8: there must be no count_consistency cross-check.');
            $this->assertNotSame('structural', $rec['check']);
        }
    }
}
