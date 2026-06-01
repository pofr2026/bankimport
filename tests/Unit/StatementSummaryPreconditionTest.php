<?php

namespace BankImport\Tests\Unit;

use BankImport\StatementSummary;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving GREEN implementation of StatementSummary::verificationPrecondition().
 *
 * Background (reviewer findings v0.0.13):
 *
 *  #1 (CRITICAL) — the read-back of imported rows swallowed DB query errors: a
 *     failed SELECT produced an empty $rows, aggregate([]) returned zeros, and verify()
 *     then emitted a 'mismatch' storm. The user saw "N checks failed" instead of
 *     "database error". The query failure must surface as status='error', not as
 *     a verification mismatch.
 *
 *  #2 (CRITICAL) — re-importing a statement that was first imported BEFORE v0.0.13
 *     (rows stored with num_releve = NULL/'') yields ZERO rows under the new
 *     WHERE num_releve = '<Stmt><Id>' scope. verify() then flags every expected
 *     entry as MISSING. With zero rows found there is nothing to meaningfully
 *     verify, so the honest disposition is status='skipped', not a per-entry storm.
 *
 *  #4 (NICE-TO-HAVE) — a CAMT.053 file violating the mandatory <Stmt><Id> yields
 *     num_releve = '', whose WHERE clause matches EVERY prior empty-id row across
 *     statements/imports. The scope is unreliable, so verification must be skipped
 *     regardless of how many rows came back.
 *
 * verificationPrecondition() is the single pure decision point: given the runtime
 * facts the coupled caller observed while reading actuals back, it returns either
 *   - a single stmt-level disposition record (status 'error' or 'skipped') that
 *     REPLACES the normal per-check output for that Stmt, or
 *   - null, meaning "preconditions are fine, run verify() normally".
 *
 * Decision priority (highest first): queryFailed → scopeEmpty → rowsFound===0.
 *
 * The returned record carries the same 8 fields as every verify() record so the
 * UI renders it in the same table:
 *   ['check' => 'verification', 'stmt' => <id>, 'status' => 'error'|'skipped',
 *    'ref' => null, 'expected' => null, 'actual' => null, 'detail' => <string>]
 */
class StatementSummaryPreconditionTest extends TestCase
{
    /**
     * Minimal expected-Stmt shape. By default the statement DOES expect rows
     * (one ref entry), so the rowsFound===0 guard (#2) can fire. Individual
     * tests override 'entries'/'unaddressable_entries'/'count' to model an
     * empty statement that legitimately expects nothing.
     */
    private function expectedStmt(string $id = 'stmt-fixture'): array
    {
        return [
            'id'                    => $id,
            'count'                 => 1,
            'entries'               => ['r1' => ['signed' => 100.00, 'currency' => 'CHF']],
            'unaddressable_entries' => [],
        ];
    }

    /**
     * #1 — A failed DB query (queryFailed=true) must produce a single 'error'
     * disposition, NOT a mismatch storm. This is the highest-priority branch.
     */
    public function test_query_failure_yields_error_disposition(): void
    {
        $record = StatementSummary::verificationPrecondition(
            $this->expectedStmt(),
            true,   // queryFailed
            false,  // scopeEmpty
            0       // rowsFound (irrelevant — query failed)
        );

        $this->assertNotNull($record, 'A DB query failure must short-circuit into a disposition record.');
        $this->assertSame('verification', $record['check']);
        $this->assertSame('stmt-fixture', $record['stmt']);
        $this->assertSame('error', $record['status']);
        $this->assertNull($record['ref']);
        $this->assertNotSame('', (string) $record['detail'], 'An error disposition must carry a diagnostic detail.');
    }

    /**
     * #4 — An empty scope (num_releve === '') makes the WHERE clause match
     * unrelated rows, so verification is unreliable and must be skipped — even
     * when rows came back.
     */
    public function test_empty_scope_yields_skipped_even_with_rows(): void
    {
        $record = StatementSummary::verificationPrecondition(
            $this->expectedStmt(''),
            false,  // queryFailed
            true,   // scopeEmpty
            5       // rowsFound > 0, but scope is untrustworthy
        );

        $this->assertNotNull($record, 'An empty scope must short-circuit into a disposition record.');
        $this->assertSame('verification', $record['check']);
        $this->assertSame('skipped', $record['status']);
        $this->assertNotSame('', (string) $record['detail']);
    }

    /**
     * #2 — Zero rows found while the statement DID expect entries → skipped
     * (likely a re-import of pre-v0.0.13 data), not a per-entry "missing" storm.
     */
    public function test_zero_rows_with_expected_entries_yields_skipped(): void
    {
        $record = StatementSummary::verificationPrecondition(
            $this->expectedStmt(),
            false,  // queryFailed
            false,  // scopeEmpty
            0       // rowsFound — nothing under this num_releve
        );

        $this->assertNotNull($record, 'Zero rows under a non-empty scope must skip verification, not run it.');
        $this->assertSame('verification', $record['check']);
        $this->assertSame('skipped', $record['status']);
        $this->assertNotSame('', (string) $record['detail']);
    }

    /**
     * Boundary of #2: a statement that legitimately expects NO rows (no entries,
     * no unaddressable, count 0) and finds 0 rows is NOT an anomaly — verify()
     * would pass cleanly, so the precondition must return null (proceed).
     */
    public function test_zero_rows_but_statement_expects_nothing_proceeds(): void
    {
        $empty = $this->expectedStmt();
        $empty['count'] = 0;
        $empty['entries'] = [];
        $empty['unaddressable_entries'] = [];

        $record = StatementSummary::verificationPrecondition($empty, false, false, 0);

        $this->assertNull($record, 'An empty statement matching zero rows is consistent — do not skip.');
    }

    /**
     * Healthy path: query ok, scope non-empty, rows found → null (run verify()).
     */
    public function test_healthy_inputs_proceed(): void
    {
        $record = StatementSummary::verificationPrecondition(
            $this->expectedStmt(),
            false,  // queryFailed
            false,  // scopeEmpty
            3       // rowsFound
        );

        $this->assertNull($record, 'With a successful query, real scope and rows present, verify() must run.');
    }

    /**
     * Priority: a failed query overrides every other condition. Even with an
     * empty scope and zero rows, the disposition is 'error' (the actionable
     * root cause), never 'skipped'.
     */
    public function test_query_failure_takes_priority_over_scope_and_rows(): void
    {
        $record = StatementSummary::verificationPrecondition(
            $this->expectedStmt(''),
            true,   // queryFailed
            true,   // scopeEmpty
            0       // rowsFound
        );

        $this->assertNotNull($record);
        $this->assertSame('error', $record['status'], 'A query failure must win over scope/row conditions.');
    }
}
