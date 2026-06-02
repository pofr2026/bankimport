<?php

namespace BankImport\Tests\Unit;

use BankImport\FeeSplitter;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of BankImport\FeeSplitter::extract().
 *
 * extract() takes a single namespace-stripped CAMT.053 <Ntry> and decides whether it
 * carries an embedded fee that should be broken out into its own bank line. It returns
 * null (leave the entry as one line) or a split descriptor
 * ['main_amount', 'fee_amount', 'fee_currency'] — i18n-agnostic, zero Dolibarr coupling,
 * mirroring StatementSummary. The human-readable fee label is composed by the Dolibarr
 * integration layer, not here.
 *
 * The load-bearing numbers come from three real Revolut 2025 statements (CHF/EUR/PLN,
 * camt.053.001.08) the maintainer provided. Two facts those files established, and that
 * these tests lock in:
 *
 *   1. Entry <Amt> is fee-INCLUSIVE (it is the posted account movement). Proven by
 *      summing all 33 EUR debit entries to 8474.34, exactly the file's TtlDbtNtries.Sum.
 *      A split must therefore satisfy the invariant
 *          main_amount + fee_amount == signed(Amt)
 *      so the per-ref aggregate still equals the original Amt and the v0.0.13 verify()
 *      per-entry check stays green after splitting.
 *
 *   2. For FX between the holder's own accounts Revolut emits the SAME AcctSvcrRef on
 *      both legs and charges the fee in the TARGET currency. The source-currency leg
 *      then shows a fee in a DIFFERENT currency than its own <Amt>; that fee was not
 *      taken from this account and must NOT be split here (it is split on the other leg,
 *      in its own statement). Hence the same-currency guard. Crucially, verify() cannot
 *      catch a wrong cross-currency split — the two sub-lines still net to Amt — so the
 *      guard's correctness is enforced ONLY by these unit tests.
 */
class FeeSplitterTest extends TestCase
{
    /** Half a cent, the same tolerance StatementSummary uses for amount comparisons. */
    private const TOLERANCE = 0.005;

    /**
     * Load a CAMT.053 fixture the way the live import path does: strip the default
     * namespace so element access via property syntax works without an xpath dance.
     */
    private function loadFixture(string $name): \SimpleXMLElement
    {
        $content = file_get_contents(__DIR__ . '/../fixtures/' . $name);
        $content = preg_replace('/\sxmlns="[^"]+"/', '', $content, 1);
        return simplexml_load_string($content);
    }

    /** Pull a single <Ntry> out of the first <Stmt> of a loaded fixture by its AcctSvcrRef. */
    private function ntryByRef(\SimpleXMLElement $doc, string $ref): \SimpleXMLElement
    {
        foreach ($doc->BkToCstmrStmt->Stmt->Ntry as $ntry) {
            if ((string) $ntry->AcctSvcrRef === $ref) {
                return $ntry;
            }
        }
        $this->fail("Fixture has no <Ntry> with AcctSvcrRef={$ref}");
    }

    /**
     * Parse a bare, namespace-free <Ntry> fragment. Mirrors exactly what the stripped
     * live document yields per entry, so inline edge-case XML stays small and explicit.
     */
    private function makeNtry(string $ntryXml): \SimpleXMLElement
    {
        return simplexml_load_string($ntryXml);
    }

    /**
     * An entry with no <Chrgs> block carries no embedded fee. The vast majority of
     * entries are like this (1 of 27 had a fee in the Jan-May fixture), so the cheap
     * negative path must short-circuit to a single line.
     */
    public function test_extract_returns_null_when_entry_has_no_chrgs_block(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">212.41</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>no-chrgs-card-payment</AcctSvcrRef>
            </Ntry>
            XML);

        $this->assertNull(
            FeeSplitter::extract($ntry),
            'An entry without a <Chrgs> block must stay a single line.'
        );
    }

    /**
     * A <Chrgs> block whose total is zero must not split: a 0.00 fee line is noise that
     * would also break the "fee is strictly a cost" contract the rest of the code relies on.
     */
    public function test_extract_returns_null_when_charge_amount_is_zero(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">100.00</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>zero-fee</AcctSvcrRef>
              <Chrgs><TtlChrgsAndTaxAmt Ccy="EUR">0.00</TtlChrgsAndTaxAmt></Chrgs>
            </Ntry>
            XML);

        $this->assertNull(
            FeeSplitter::extract($ntry),
            'A present-but-zero charge produces no second line.'
        );
    }

    /**
     * The cross-currency guard — the single most important behaviour of this class.
     *
     * The fixture's EUR 200.00 debit carries a 1.12 CHF fee: it is an own-account
     * EUR->CHF transfer whose fee was charged on the CHF leg (same AcctSvcrRef, other
     * statement). Splitting it out of the EUR amount would both double-count the fee
     * (the CHF leg splits it too) and invent a 1.12 EUR charge that never happened.
     * verify() would NOT flag this (the sub-lines still net to -200.00), so the guard
     * is the only line of defence.
     */
    public function test_extract_returns_null_when_fee_currency_differs_from_entry_currency(): void
    {
        $doc = $this->loadFixture('revolut_fees_2025.xml');
        $ntry = $this->ntryByRef($doc, '6822efa8c099a49ea31cce7efd2d4f7e');

        $this->assertNull(
            FeeSplitter::extract($ntry),
            'A CHF fee on a EUR entry belongs to the other FX leg and must not be split here.'
        );
    }

    /**
     * A split is only safe when the entry has a stable AcctSvcrRef. Both resulting lines
     * are stored under it (num_chq), so post-import verification folds them back into one
     * logical entry and the dedup key derives from it. An entry that carries an embedded
     * same-currency fee but NO AcctSvcrRef must therefore stay a single line — splitting it
     * would make it count as two logical entries (breaking the verify count against
     * NbOfNtries) and let a re-import duplicate it (with no ref the dedup key is the
     * amount-based composite, which differs between the split principal and the unsplit
     * amount). CAMT.053 allows a missing AcctSvcrRef even though Revolut always emits one.
     */
    public function test_extract_returns_null_when_entry_has_no_acctsvcrref(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">77.05</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <Chrgs><TtlChrgsAndTaxAmt Ccy="EUR">0.76</TtlChrgsAndTaxAmt></Chrgs>
            </Ntry>
            XML);

        $this->assertNull(
            FeeSplitter::extract($ntry),
            'A same-currency fee with no AcctSvcrRef must not split: the two lines need a shared, non-empty reference for verification and dedup.'
        );
    }

    /**
     * Same-currency fee on a DEBIT entry. The account lost the full posted Amt (77.05);
     * of that, 0.76 was the fee and 76.29 the principal. Both sub-lines are negative
     * because money left the account and the fee is itself a cost.
     */
    public function test_extract_splits_debit_entry_with_same_currency_fee(): void
    {
        $doc = $this->loadFixture('revolut_fees_2025.xml');
        $ntry = $this->ntryByRef($doc, '679e4513bfaca9b7807d446a40ad862c');

        $split = FeeSplitter::extract($ntry);

        $this->assertIsArray($split, 'A same-currency fee on a debit entry must produce a split.');
        $this->assertEqualsWithDelta(-76.29, $split['main_amount'], self::TOLERANCE,
            'Principal = signed Amt (-77.05) minus the fee line (-0.76) = -76.29.');
        $this->assertEqualsWithDelta(-0.76, $split['fee_amount'], self::TOLERANCE,
            'Fee is always a cost: negative regardless of entry direction.');
        $this->assertSame('EUR', $split['fee_currency']);
    }

    /**
     * Same-currency fee on a CREDIT entry (real CHF FX inflow: 185.99 CHF landed net of
     * a 1.12 CHF fee). The fee is added back to recover the gross proceeds (187.11) and
     * still booked as its own negative cost line.
     */
    public function test_extract_splits_credit_entry_with_same_currency_fee(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="CHF">185.99</Amt>
              <CdtDbtInd>CRDT</CdtDbtInd>
              <AcctSvcrRef>6822efa8c099a49ea31cce7efd2d4f7e</AcctSvcrRef>
              <Chrgs><TtlChrgsAndTaxAmt Ccy="CHF">1.12</TtlChrgsAndTaxAmt></Chrgs>
            </Ntry>
            XML);

        $split = FeeSplitter::extract($ntry);

        $this->assertIsArray($split);
        $this->assertEqualsWithDelta(187.11, $split['main_amount'], self::TOLERANCE,
            'Gross = signed Amt (+185.99) minus the fee line (-1.12) = +187.11.');
        $this->assertEqualsWithDelta(-1.12, $split['fee_amount'], self::TOLERANCE);
        $this->assertSame('CHF', $split['fee_currency']);
    }

    /**
     * The reconstruction invariant, exercised on both directions: main_amount plus
     * fee_amount must equal the original signed Amt. This is what keeps the v0.0.13
     * per-ref aggregate (and therefore verify()) green after a split — the two rows
     * stored under one AcctSvcrRef sum back to exactly what parse() expects.
     */
    public function test_extract_main_plus_fee_always_equals_signed_amount(): void
    {
        // [Amt, CdtDbtInd, fee, expected signed Amt]
        $cases = [
            ['77.05', 'DBIT', '0.76', -77.05],
            ['185.99', 'CRDT', '1.12', 185.99],
        ];

        foreach ($cases as [$amt, $dir, $fee, $signed]) {
            $ntry = $this->makeNtry(
                "<Ntry><Amt Ccy=\"EUR\">{$amt}</Amt><CdtDbtInd>{$dir}</CdtDbtInd>"
                . "<AcctSvcrRef>inv-{$dir}</AcctSvcrRef>"
                . "<Chrgs><TtlChrgsAndTaxAmt Ccy=\"EUR\">{$fee}</TtlChrgsAndTaxAmt></Chrgs></Ntry>"
            );

            $split = FeeSplitter::extract($ntry);

            $this->assertEqualsWithDelta(
                $signed,
                $split['main_amount'] + $split['fee_amount'],
                self::TOLERANCE,
                "main + fee must reconstruct the original signed Amt for a {$dir} entry."
            );
        }
    }

    /**
     * Defensive: Revolut emits a pre-summed <TtlChrgsAndTaxAmt>, but the CAMT.053 schema
     * also allows itemised <Rcrd> charge records with no total. When only those are
     * present we sum them, so a multi-fee entry is not silently left unsplit. (When a
     * total IS present it wins, to avoid double counting — covered by the cases above.)
     */
    public function test_extract_aggregates_multiple_charge_records_when_total_absent(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">100.00</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>multi-rcrd</AcctSvcrRef>
              <Chrgs>
                <Rcrd><Amt Ccy="EUR">0.50</Amt><CdtDbtInd>DBIT</CdtDbtInd></Rcrd>
                <Rcrd><Amt Ccy="EUR">0.30</Amt><CdtDbtInd>DBIT</CdtDbtInd></Rcrd>
              </Chrgs>
            </Ntry>
            XML);

        $split = FeeSplitter::extract($ntry);

        $this->assertIsArray($split, 'Itemised Rcrd charges with no total must still split.');
        $this->assertEqualsWithDelta(-0.80, $split['fee_amount'], self::TOLERANCE,
            'Two charge records (0.50 + 0.30) aggregate into a single 0.80 fee line.');
        $this->assertEqualsWithDelta(-99.20, $split['main_amount'], self::TOLERANCE);
        $this->assertSame('EUR', $split['fee_currency']);
    }
}
