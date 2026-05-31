<?php

namespace BankImport\Tests\Unit;

use BankImport\StatementSummary;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of StatementSummary::aggregate().
 *
 * aggregate() takes minimal pre-projected rows (['ref' => ?string, 'amount' => float])
 * and folds them into the same shape parse() emits, so verify() can pair the
 * two element-for-element. The tests lock the contract end-to-end: empty input
 * shape, per-ref grouping with signed totals, multi-row-per-ref aggregation
 * (forward-compat with v0.0.14 splits), float-drift preservation, refless
 * routing to the unaddressable bucket, and the net = credit − debit identity
 * with debit_sum kept unsigned-positive.
 */
class StatementSummaryAggregateTest extends TestCase
{
    /**
     * Empty input must produce a fully populated, zeroed shape (not just a
     * partial array). verify() will read every key unconditionally and would
     * trigger PHP 8 "undefined array key" warnings on a sparse result.
     */
    public function test_aggregate_empty_input_returns_zeroed_summary(): void
    {
        $result = StatementSummary::aggregate([]);

        $this->assertSame(0, $result['count']);
        $this->assertSame(0, $result['credit_count']);
        $this->assertSame(0, $result['debit_count']);
        $this->assertSame(0.0, $result['credit_sum']);
        $this->assertSame(0.0, $result['debit_sum']);
        $this->assertSame(0.0, $result['net_entry']);
        $this->assertSame([], $result['entries']);
        $this->assertSame([], $result['unaddressable_entries']);
    }

    /**
     * Each ref becomes one entries[ref] with its signed total. credit_sum
     * sums positive entries, debit_sum is the UNSIGNED absolute value of
     * negative entries (so the bank's TtlDbtNtries.Sum can be compared
     * directly without juggling signs in verify()). Direction counts mirror
     * the sums.
     */
    public function test_aggregate_sums_amounts_per_acctsvcrref(): void
    {
        $rows = [
            ['ref' => 'r-credit-1', 'amount' => 100.00],
            ['ref' => 'r-debit-1',  'amount' => -50.00],
            ['ref' => 'r-credit-2', 'amount' => 25.00],
        ];

        $result = StatementSummary::aggregate($rows);

        $this->assertSame(3, $result['count']);
        $this->assertSame(2, $result['credit_count']);
        $this->assertSame(1, $result['debit_count']);
        $this->assertEqualsWithDelta(125.00, $result['credit_sum'], 0.005);
        $this->assertEqualsWithDelta(50.00,  $result['debit_sum'],  0.005,
            'AGG-5: debit_sum must be unsigned positive (|sum of negatives|), not the negative sum itself.');
        $this->assertGreaterThan(0, $result['debit_sum'],
            'AGG-5: debit_sum unsigned guard.');
        $this->assertEqualsWithDelta(75.00, $result['net_entry'], 0.005);

        $this->assertEqualsWithDelta(100.00, $result['entries']['r-credit-1']['signed'], 0.005);
        $this->assertEqualsWithDelta(-50.00, $result['entries']['r-debit-1']['signed'],  0.005);
        $this->assertEqualsWithDelta(25.00,  $result['entries']['r-credit-2']['signed'], 0.005);
    }

    /**
     * Forward-compat with v0.0.14 FeeSplitter: a single CAMT.053 Ntry may be
     * stored as two llx_bank rows under the same ref (gross + fee). They must
     * aggregate to one entries[ref] whose signed equals the original Amt,
     * and total_count (AGG-4) counts that ref as ONE logical entry — not two
     * rows — so the count check stays consistent with parse()['count'] which
     * comes from NbOfNtries (Ntry count, not row count).
     */
    public function test_aggregate_split_rows_with_same_ref_combine_into_one_per_ref_entry(): void
    {
        $rows = [
            ['ref' => 'fx-with-fee', 'amount' => 2441.63],  // gross row from FeeSplitter
            ['ref' => 'fx-with-fee', 'amount' => -7.15],    // fee row from FeeSplitter
            ['ref' => 'simple',      'amount' => 100.00],   // ordinary unsplit entry
        ];

        $result = StatementSummary::aggregate($rows);

        $this->assertSame(2, $result['count'],
            'AGG-4: 2 logical entries (2 refs), NOT 3 rows. Counting rows would mismatch parse()[count]=NbOfNtries after splits.');

        $this->assertEqualsWithDelta(2434.48, $result['entries']['fx-with-fee']['signed'], 0.005,
            'Gross (+2441.63) + fee (-7.15) must aggregate back to the original Amt (+2434.48).');
        $this->assertEqualsWithDelta(100.00, $result['entries']['simple']['signed'], 0.005);

        $this->assertSame(2, $result['credit_count'], 'Both refs net to positive.');
        $this->assertSame(0, $result['debit_count']);
        $this->assertEqualsWithDelta(2534.48, $result['credit_sum'], 0.005);
    }

    /**
     * aggregate() must NOT round — float drift is preserved verbatim and the
     * half-cent tolerance lives exclusively in verify() (AGG-6). One source
     * of truth about numeric equality. The classic 0.1 + 0.2 ≠ 0.3 IEEE 754
     * case proves drift is left intact.
     */
    public function test_aggregate_float_drift_within_half_cent_tolerance(): void
    {
        $rows = [
            ['ref' => 'drift', 'amount' => 0.1],
            ['ref' => 'drift', 'amount' => 0.2],
        ];

        $result = StatementSummary::aggregate($rows);

        $this->assertEqualsWithDelta(0.3, $result['entries']['drift']['signed'], 0.005,
            'AGG-6: drift is within half-cent tolerance, verify() will accept it.');
        $this->assertNotSame(0.3, $result['entries']['drift']['signed'],
            'AGG-6 guard: 0.1+0.2 must NOT equal exactly 0.3 (IEEE 754 drift).' .
            ' If this passes, aggregate() is silently rounding — tolerance must live only in verify().');
    }

    /**
     * Rows whose ref is null or empty string (CAMT.053 entries that lacked
     * AcctSvcrRef in source) cannot be keyed in the per-ref map. They land
     * in unaddressable_entries as a list (insertion order preserved) so
     * verify()'s per-ref check can skip them while the sum checks still
     * tally them. Refless rows count as one logical entry each (they don't
     * aggregate with anything — every row is its own entry).
     */
    public function test_aggregate_refless_rows_go_to_unaddressable_not_per_ref(): void
    {
        $rows = [
            ['ref' => 'addressable', 'amount' => 100.00],
            ['ref' => null,          'amount' => 50.00],   // null ref
            ['ref' => '',            'amount' => 25.00],   // empty string also counts as refless
        ];

        $result = StatementSummary::aggregate($rows);

        $this->assertCount(1, $result['entries']);
        $this->assertEqualsWithDelta(100.00, $result['entries']['addressable']['signed'], 0.005);

        $this->assertCount(2, $result['unaddressable_entries']);
        $this->assertEqualsWithDelta(50.00, $result['unaddressable_entries'][0]['signed'], 0.005,
            'Insertion order preserved: null-ref row appears first.');
        $this->assertEqualsWithDelta(25.00, $result['unaddressable_entries'][1]['signed'], 0.005);

        $this->assertSame(3, $result['count'],
            'Refless rows each count as one logical entry: 1 addressable + 2 unaddressable = 3.');
    }

    /**
     * Guard for AGG-5: net_entry must equal credit_sum − debit_sum exactly
     * (within float-comparison delta). Catches a class of bugs where the
     * implementation accidentally derives net from a different path (e.g.
     * row-level sum) and silently disagrees with the sum components.
     */
    public function test_aggregate_mixed_credit_debit_net_equals_credit_minus_debit(): void
    {
        $rows = [
            ['ref' => 'r1', 'amount' => 500.00],
            ['ref' => 'r2', 'amount' => -300.00],
            ['ref' => 'r3', 'amount' => -50.00],
            ['ref' => 'r4', 'amount' => 75.00],
        ];

        $result = StatementSummary::aggregate($rows);

        $this->assertSame(2, $result['credit_count']);
        $this->assertSame(2, $result['debit_count']);
        $this->assertEqualsWithDelta(575.00, $result['credit_sum'], 0.005);
        $this->assertEqualsWithDelta(350.00, $result['debit_sum'],  0.005, 'AGG-5 unsigned.');
        $this->assertEqualsWithDelta(225.00, $result['net_entry'],  0.005);
        $this->assertEqualsWithDelta(
            $result['credit_sum'] - $result['debit_sum'],
            $result['net_entry'],
            0.005,
            'AGG-5 invariant: net_entry must equal credit_sum - debit_sum.'
        );
    }
}
