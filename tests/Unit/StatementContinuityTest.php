<?php

namespace BankImport\Tests\Unit;

use BankImport\StatementContinuity;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of BankImport\StatementContinuity.
 *
 * StatementContinuity is the PURE core of the Reconciliation / Re-verify feature
 * (the goal cut out of v0.0.13): cross-statement continuity. Inline per-import
 * verification (StatementSummary) only ever sees a single statement, so it cannot
 * notice that a whole statement FILE is missing from a chain. This helper takes the
 * declared opening/closing booking balances (OPBD/CLBD) of several statements and
 * checks the ledger invariant that each statement's closing balance equals the next
 * statement's opening balance:  CLBD_N == OPBD_(N+1).
 *
 * A break in that chain means the bank itself signals a gap — most often a statement
 * file the user never imported between two files they did. llx_bank running totals
 * cannot reveal this (the missing rows simply are not there and the total stays
 * internally consistent over whatever WAS imported), which is exactly why the
 * bank-declared balances must be compared across statements.
 *
 * Zero Dolibarr coupling — operates only on plain arrays — so it is unit-testable
 * per tests/bootstrap.php, mirroring StatementSummary / FeeSplitter / EntryPlan.
 *
 * Input: a list of statement records, each:
 *   ['seq' => string,        // <Stmt><ElctrncSeqNb>, the chain order key
 *    'currency' => string,   // each currency is an independent chain (multi-currency Revolut)
 *    'opbd' => float,        // signed opening booking balance
 *    'clbd' => float,        // signed closing booking balance
 *    'id' => string]         // <Stmt><Id>, for human-readable reporting
 *
 * Output: a list of presentation-free gap records, each:
 *   ['currency' => string,
 *    'from_seq' => string, 'to_seq' => string,   // chain-order keys
 *    'from_id'  => string, 'to_id'  => string,   // statement identifiers (num_releve), for display
 *    'expected_opbd' => float,   // the prior statement's CLBD
 *    'actual_opbd'   => float]   // the next statement's declared OPBD
 */
class StatementContinuityTest extends TestCase
{
    /**
     * Two consecutive CHF statements whose closing/opening balances disagree
     * (250.00 closed, 240.00 opened) — a 10.00 break in the chain — must yield
     * exactly one gap record pinpointing the two statements and both balances.
     */
    public function test_clbd_not_matching_next_opbd_reports_one_gap(): void
    {
        $statements = [
            ['seq' => '1', 'currency' => 'CHF', 'opbd' => 100.00, 'clbd' => 250.00, 'id' => 'A'],
            ['seq' => '2', 'currency' => 'CHF', 'opbd' => 240.00, 'clbd' => 300.00, 'id' => 'B'],
        ];

        $gaps = StatementContinuity::check($statements);

        $this->assertCount(1, $gaps);
        $this->assertSame('CHF', $gaps[0]['currency']);
        $this->assertSame('1', $gaps[0]['from_seq']);
        $this->assertSame('2', $gaps[0]['to_seq']);
        $this->assertSame('A', $gaps[0]['from_id']);
        $this->assertSame('B', $gaps[0]['to_id']);
        $this->assertEqualsWithDelta(250.00, $gaps[0]['expected_opbd'], 0.005);
        $this->assertEqualsWithDelta(240.00, $gaps[0]['actual_opbd'], 0.005);
        $this->assertArrayNotHasKey('detail', $gaps[0]);
    }

    /**
     * A continuous chain (every CLBD equals the next OPBD) is the healthy case
     * and must report zero gaps — the signal the UI uses to show a green tick.
     */
    public function test_continuous_chain_reports_no_gap(): void
    {
        $statements = [
            ['seq' => '1', 'currency' => 'CHF', 'opbd' => 100.00, 'clbd' => 250.00, 'id' => 'A'],
            ['seq' => '2', 'currency' => 'CHF', 'opbd' => 250.00, 'clbd' => 300.00, 'id' => 'B'],
            ['seq' => '3', 'currency' => 'CHF', 'opbd' => 300.00, 'clbd' => 275.50, 'id' => 'C'],
        ];

        $this->assertSame([], StatementContinuity::check($statements));
    }

    /**
     * Each currency is its own chain: a CHF closing balance must never be
     * compared against a EUR opening balance. Here both per-currency chains are
     * internally continuous, so despite CHF.clbd (250) differing from EUR.opbd
     * (10) there must be no gap — the two currencies are simply unrelated.
     */
    public function test_currencies_are_independent_chains(): void
    {
        $statements = [
            ['seq' => '1', 'currency' => 'CHF', 'opbd' => 100.00, 'clbd' => 250.00, 'id' => 'CHF-1'],
            ['seq' => '2', 'currency' => 'CHF', 'opbd' => 250.00, 'clbd' => 260.00, 'id' => 'CHF-2'],
            ['seq' => '1', 'currency' => 'EUR', 'opbd' => 10.00,  'clbd' => 80.00,  'id' => 'EUR-1'],
            ['seq' => '2', 'currency' => 'EUR', 'opbd' => 80.00,  'clbd' => 95.00,  'id' => 'EUR-2'],
        ];

        $this->assertSame([], StatementContinuity::check($statements));
    }

    /**
     * A gap in one currency must be reported without contaminating another
     * currency whose chain is intact: EUR breaks (80 -> 70), CHF is continuous.
     */
    public function test_gap_in_one_currency_does_not_affect_another(): void
    {
        $statements = [
            ['seq' => '1', 'currency' => 'CHF', 'opbd' => 100.00, 'clbd' => 250.00, 'id' => 'CHF-1'],
            ['seq' => '2', 'currency' => 'CHF', 'opbd' => 250.00, 'clbd' => 260.00, 'id' => 'CHF-2'],
            ['seq' => '1', 'currency' => 'EUR', 'opbd' => 10.00,  'clbd' => 80.00,  'id' => 'EUR-1'],
            ['seq' => '2', 'currency' => 'EUR', 'opbd' => 70.00,  'clbd' => 95.00,  'id' => 'EUR-2'],
        ];

        $gaps = StatementContinuity::check($statements);

        $this->assertCount(1, $gaps);
        $this->assertSame('EUR', $gaps[0]['currency']);
        $this->assertSame('1', $gaps[0]['from_seq']);
        $this->assertSame('2', $gaps[0]['to_seq']);
    }

    /**
     * Statements may be stored/imported out of order; the chain is defined by
     * the electronic sequence number, not by input order. Sequence numbers must
     * also sort numerically (10 after 9, not after 1). Fed seq 10, 9, 11 shuffled,
     * the continuous chain must still read as continuous.
     */
    public function test_statements_are_ordered_by_sequence_not_input_order(): void
    {
        $statements = [
            ['seq' => '11', 'currency' => 'CHF', 'opbd' => 300.00, 'clbd' => 275.50, 'id' => 'C'],
            ['seq' => '9',  'currency' => 'CHF', 'opbd' => 100.00, 'clbd' => 250.00, 'id' => 'A'],
            ['seq' => '10', 'currency' => 'CHF', 'opbd' => 250.00, 'clbd' => 300.00, 'id' => 'B'],
        ];

        $this->assertSame([], StatementContinuity::check($statements));
    }

    /**
     * A sub-half-cent difference between CLBD and the next OPBD is float drift,
     * not a real discrepancy, and must not be reported — the tolerance lives in
     * the shared Amount helper.
     */
    public function test_balances_within_half_cent_tolerance_report_no_gap(): void
    {
        $statements = [
            ['seq' => '1', 'currency' => 'CHF', 'opbd' => 100.00,  'clbd' => 250.004, 'id' => 'A'],
            ['seq' => '2', 'currency' => 'CHF', 'opbd' => 250.000, 'clbd' => 300.00,  'id' => 'B'],
        ];

        $this->assertSame([], StatementContinuity::check($statements));
    }

    /**
     * Two independent breaks in a single currency chain must each surface as
     * their own gap record, in chain order.
     */
    public function test_multiple_breaks_in_one_chain_report_one_gap_each(): void
    {
        $statements = [
            ['seq' => '1', 'currency' => 'CHF', 'opbd' => 100.00, 'clbd' => 250.00, 'id' => 'A'],
            ['seq' => '2', 'currency' => 'CHF', 'opbd' => 240.00, 'clbd' => 300.00, 'id' => 'B'], // break 1
            ['seq' => '3', 'currency' => 'CHF', 'opbd' => 280.00, 'clbd' => 290.00, 'id' => 'C'], // break 2
        ];

        $gaps = StatementContinuity::check($statements);

        $this->assertCount(2, $gaps);
        $this->assertSame('2', $gaps[0]['to_seq']);
        $this->assertSame('3', $gaps[1]['to_seq']);
    }

    /**
     * A single statement has no successor to compare against, so it can never
     * produce a gap. Guards the adjacency loop's lower bound.
     */
    public function test_single_statement_reports_no_gap(): void
    {
        $statements = [
            ['seq' => '1', 'currency' => 'CHF', 'opbd' => 100.00, 'clbd' => 250.00, 'id' => 'A'],
        ];

        $this->assertSame([], StatementContinuity::check($statements));
    }
}
