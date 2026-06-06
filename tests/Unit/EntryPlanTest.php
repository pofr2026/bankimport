<?php

namespace BankImport\Tests\Unit;

use BankImport\EntryPlan;
use BankImport\ImportKey;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of BankImport\EntryPlan.
 *
 * EntryPlan is the PURE core extracted from BankImport::processXmlEntry()/processRow():
 * given a parsed bank entry it returns the list of bank line(s) to write — WITHOUT any
 * Dolibarr coupling and WITHOUT touching the database. The same plan feeds both the new
 * import preview (dry-run, no writes) and the actual commit, so preview == commit by
 * construction. Mirrors FeeSplitter / StatementSummary: zero Dolibarr dependency, so it
 * is unit-testable per tests/bootstrap.php.
 *
 * Deliberate seam (why dates/amounts are passed in, not parsed here):
 *   - ISO/CSV date parsing uses dol_mktime() and CSV amount uses price2num() — both are
 *     Dolibarr functions, unavailable in unit tests. More importantly, the import_key
 *     hashes the booking-date timestamp, so reproducing dol_mktime()'s exact output is
 *     required to keep dedup compatible with already-stored rows. Date parsing therefore
 *     stays in the Dolibarr glue (BankImport, E2E-tested) and the resulting timestamps
 *     are passed into the planner. The planner stays pure and the import_key it derives
 *     is byte-identical to the one the current write path produces.
 *
 * Plan shape returned by planXmlEntry()/planCsvRow():
 *   [
 *     'dateo' => int, 'datev' => int,
 *     'num_chq' => string,                 // AcctSvcrRef (XML) / mandate ref (CSV); shared by both split lines
 *     'owner_other' => string, 'bank_other' => string, 'note' => string,
 *     'label' => string,                   // principal label
 *     'is_split' => bool,                  // true only when an embedded fee was broken out
 *     'lines' => [
 *        ['amount' => float, 'label' => string, 'import_key' => string, 'is_fee' => bool],
 *        ...                               // 1 line normally, 2 when split (principal then fee)
 *     ],
 *   ]
 *
 * The fee label is composed from a base string PASSED IN (i18n-agnostic, exactly like
 * FeeSplitter leaves labelling to the caller); the glue passes $langs->trans('BANKIMPORT_FeeLineLabel').
 */
class EntryPlanTest extends TestCase
{
    private const TOLERANCE = 0.005;

    /** Fixed timestamps standing in for what the glue's dol_mktime() would produce. */
    private const DATEO = 1714300800; // 2024-04-28 00:00:00 UTC
    private const DATEV = 1714300800;

    /** Base text the glue would obtain from the lang file for a fee line. */
    private const FEE_BASE = 'Bank fee';

    /** Parse a bare, namespace-free <Ntry> fragment, as the stripped live document yields. */
    private function makeNtry(string $ntryXml): \SimpleXMLElement
    {
        return simplexml_load_string($ntryXml);
    }

    /**
     * A plain DEBIT entry with no <Chrgs>: one line, no split. Asserts sign (DBIT → negative),
     * label/owner/counterparty extraction, the note tags, num_chq = AcctSvcrRef, and that the
     * import_key is wired from exactly (ref, iban, owner, amount, label, '', dateo) — the same
     * call the current single-line path makes.
     */
    public function test_plan_xml_single_debit_entry_no_fee(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">212.41</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>ref-dbit-1</AcctSvcrRef>
              <NtryDtls><TxDtls>
                <RltdPties>
                  <Cdtr><Nm>Acme GmbH</Nm></Cdtr>
                  <CdtrAcct><Id><IBAN>DE00123456780000000001</IBAN></Id></CdtrAcct>
                </RltdPties>
                <RmtInf><Ustrd>Invoice 2026-001</Ustrd></RmtInf>
              </TxDtls></NtryDtls>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, self::DATEV, true, self::FEE_BASE);

        $this->assertFalse($plan['is_split']);
        $this->assertCount(1, $plan['lines']);
        $this->assertSame('ref-dbit-1', $plan['num_chq']);
        $this->assertSame('Acme GmbH', $plan['owner_other']);
        $this->assertStringContainsString('AcctSvcrRef=ref-dbit-1', $plan['note']);
        $this->assertStringContainsString('CounterpartyIBAN=DE00123456780000000001', $plan['note']);

        $line = $plan['lines'][0];
        $this->assertFalse($line['is_fee']);
        $this->assertEqualsWithDelta(-212.41, $line['amount'], self::TOLERANCE, 'DBIT must be negative.');
        $this->assertSame('Invoice 2026-001', $line['label']);
        $this->assertSame(
            ImportKey::build('ref-dbit-1', 'DE00123456780000000001', 'Acme GmbH', -212.41, 'Invoice 2026-001', '', self::DATEO),
            $line['import_key'],
            'Single-line import_key must be wired from (ref, iban, owner, amount, label, "", dateo).'
        );
    }

    /**
     * A CREDIT entry: sign stays positive and the counterparty is taken from the DEBTOR side
     * (incoming money → the other party is the debtor), the mirror of the debit case above.
     */
    public function test_plan_xml_single_credit_entry_uses_debtor_party(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">500.00</Amt>
              <CdtDbtInd>CRDT</CdtDbtInd>
              <AcctSvcrRef>ref-crdt-1</AcctSvcrRef>
              <NtryDtls><TxDtls>
                <RltdPties>
                  <Dbtr><Nm>Client SARL</Nm></Dbtr>
                  <DbtrAcct><Id><IBAN>FR0000000000000000000001</IBAN></Id></DbtrAcct>
                </RltdPties>
                <RmtInf><Ustrd>Payment</Ustrd></RmtInf>
              </TxDtls></NtryDtls>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, self::DATEV, true, self::FEE_BASE);

        $this->assertFalse($plan['is_split']);
        $this->assertCount(1, $plan['lines']);
        $this->assertSame('Client SARL', $plan['owner_other'], 'CRDT → counterparty is the debtor.');
        $this->assertEqualsWithDelta(500.00, $plan['lines'][0]['amount'], self::TOLERANCE, 'CRDT must be positive.');
    }

    /**
     * Split ON + same-currency fee on a DEBIT entry: two lines (principal then fee).
     *  - principal is NOT a fee line; fee line IS, carries the composed "Bank fee: <label>" label;
     *  - the two amounts reconstruct the original signed Amt (the v0.0.13 verify invariant);
     *  - the principal key equals the would-be single-line key (stable ref hash → idempotent
     *    re-import even if the setting is toggled), while the fee key differs (ref salted ':fee').
     */
    public function test_plan_xml_splits_same_currency_fee_on_debit(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">77.05</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>ref-split</AcctSvcrRef>
              <NtryDtls><TxDtls><RmtInf><Ustrd>FX swap</Ustrd></RmtInf></TxDtls></NtryDtls>
              <Chrgs><TtlChrgsAndTaxAmt Ccy="EUR">0.76</TtlChrgsAndTaxAmt></Chrgs>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, self::DATEV, true, self::FEE_BASE);

        $this->assertTrue($plan['is_split']);
        $this->assertCount(2, $plan['lines']);

        [$principal, $fee] = $plan['lines'];
        $this->assertFalse($principal['is_fee']);
        $this->assertTrue($fee['is_fee']);

        $this->assertEqualsWithDelta(-76.29, $principal['amount'], self::TOLERANCE);
        $this->assertEqualsWithDelta(-0.76, $fee['amount'], self::TOLERANCE);
        $this->assertEqualsWithDelta(
            -77.05,
            $principal['amount'] + $fee['amount'],
            self::TOLERANCE,
            'Principal + fee must reconstruct the original signed Amt.'
        );

        $this->assertSame('FX swap', $principal['label']);
        $this->assertSame('Bank fee: FX swap', $fee['label'], 'Fee label = base ": " principal label.');

        // Principal key == the key the single-line path would produce (bare ref hash).
        $this->assertSame(
            ImportKey::build('ref-split', '', '', -76.29, 'FX swap', '', self::DATEO),
            $principal['import_key']
        );
        // Fee key is salted with ':fee' so dedup keeps the two lines distinct.
        $this->assertSame(
            ImportKey::build('ref-split:fee', '', '', -0.76, 'Bank fee: FX swap', '', self::DATEO),
            $fee['import_key']
        );
        $this->assertNotSame($principal['import_key'], $fee['import_key']);
    }

    /**
     * Split ON but the fee is in a DIFFERENT currency than the entry (cross-currency FX leg):
     * FeeSplitter declines, so the planner must leave it a single line (is_split=false).
     */
    public function test_plan_xml_does_not_split_cross_currency_fee(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">200.00</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>ref-xccy</AcctSvcrRef>
              <Chrgs><TtlChrgsAndTaxAmt Ccy="CHF">1.12</TtlChrgsAndTaxAmt></Chrgs>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, self::DATEV, true, self::FEE_BASE);

        $this->assertFalse($plan['is_split']);
        $this->assertCount(1, $plan['lines']);
        $this->assertEqualsWithDelta(-200.00, $plan['lines'][0]['amount'], self::TOLERANCE);
    }

    /**
     * Split OFF (per-import override unchecked) must keep a single line even when a splittable
     * same-currency fee is present — exercising the toggle the preview screen exposes.
     */
    public function test_plan_xml_split_disabled_keeps_single_line(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">77.05</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>ref-nosplit</AcctSvcrRef>
              <Chrgs><TtlChrgsAndTaxAmt Ccy="EUR">0.76</TtlChrgsAndTaxAmt></Chrgs>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, self::DATEV, false, self::FEE_BASE);

        $this->assertFalse($plan['is_split']);
        $this->assertCount(1, $plan['lines']);
        $this->assertEqualsWithDelta(-77.05, $plan['lines'][0]['amount'], self::TOLERANCE,
            'With split off the whole amount stays on one line (fee embedded).');
    }

    /**
     * Multiple <TxDtls> remittance pieces are joined with " | " into the line label, and the
     * label falls back to <AddtlNtryInf> only when no usable TxDtls text exists. Locks the
     * label-composition the current path performs.
     */
    public function test_plan_xml_joins_multiple_remittance_pieces(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">10.00</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>ref-multi</AcctSvcrRef>
              <NtryDtls>
                <TxDtls><RmtInf><Ustrd>Part A</Ustrd></RmtInf></TxDtls>
                <TxDtls><RmtInf><Ustrd>Part B</Ustrd></RmtInf></TxDtls>
              </NtryDtls>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, self::DATEV, true, self::FEE_BASE);

        $this->assertSame('Part A | Part B', $plan['lines'][0]['label']);
    }

    /**
     * CSV path: planCsvRow assembles a single, non-fee line from already-parsed primitives
     * (amount via price2num and dates via dol_mktime happen in the glue). Asserts the line
     * maps through and the import_key is wired from the CSV fields the current processRow uses.
     */
    public function test_plan_csv_row_single_line(): void
    {
        $fieldMapping = [
            'booking_date'     => 0,
            'value_date'       => 1,
            'payment_purpose'  => 2,
            'amount'           => 3,
            'mandate_reference' => 4,
            'counterparty_bic' => 5,
            'counterparty_iban' => 6,
            'counterparty_name' => 7,
            'collector_reference' => 8,
            'creditor_id'      => 9,
        ];
        $data = [
            '28.04.26', '28.04.26', 'Office supplies', '-42,50', 'MND-7',
            'BICDE00', 'DE00123456780000000099', 'Stationers Ltd', '', '',
        ];

        $plan = EntryPlan::planCsvRow($data, $fieldMapping, -42.50, self::DATEO, self::DATEV);

        $this->assertFalse($plan['is_split']);
        $this->assertCount(1, $plan['lines']);
        $line = $plan['lines'][0];
        $this->assertFalse($line['is_fee']);
        $this->assertEqualsWithDelta(-42.50, $line['amount'], self::TOLERANCE);
        $this->assertSame('Office supplies', $line['label']);
        $this->assertSame('Stationers Ltd', $plan['owner_other']);
        $this->assertSame(
            ImportKey::build(null, 'DE00123456780000000099', 'Stationers Ltd', -42.50, 'Office supplies', 'MND-7', self::DATEO),
            $line['import_key'],
            'CSV import_key must match the current processRow wiring (transaction_id null, mandate ref as $ref).'
        );
    }

    /**
     * L1: a missing CSV value date must default to the booking date. validateRow() requires only
     * booking_date + amount, so a blank Valutadatum column reaches the planner as datev = 0 (the
     * glue's dol_mktime('') yields a falsy value). The planner must substitute the booking date so
     * the stored line carries a sensible value date — mirroring the XML path below.
     */
    public function test_plan_csv_row_defaults_missing_value_date_to_booking_date(): void
    {
        $fieldMapping = [
            'booking_date'     => 0,
            'value_date'       => 1,
            'payment_purpose'  => 2,
            'amount'           => 3,
            'mandate_reference' => 4,
            'counterparty_bic' => 5,
            'counterparty_iban' => 6,
            'counterparty_name' => 7,
            'collector_reference' => 8,
            'creditor_id'      => 9,
        ];
        $data = [
            '28.04.26', '', 'Office supplies', '-42,50', 'MND-7',
            'BICDE00', 'DE00123456780000000099', 'Stationers Ltd', '', '',
        ];

        // datev = 0 represents an empty Valutadatum column.
        $plan = EntryPlan::planCsvRow($data, $fieldMapping, -42.50, self::DATEO, 0);

        $this->assertSame(
            self::DATEO,
            $plan['datev'],
            'A missing CSV value date must default to the booking date (datev = dateo).'
        );
    }

    /**
     * L1 (symmetric): the same value-date fallback applies to the XML planner, so the
     * datev = dateo rule lives in one place — the pure planner — for both formats, rather
     * than being duplicated in the Dolibarr glue.
     */
    public function test_plan_xml_defaults_missing_value_date_to_booking_date(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="EUR">10.00</Amt>
              <CdtDbtInd>DBIT</CdtDbtInd>
              <AcctSvcrRef>ref-novaldt</AcctSvcrRef>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, 0, true, self::FEE_BASE);

        $this->assertSame(
            self::DATEO,
            $plan['datev'],
            'A missing XML value date must default to the booking date (datev = dateo).'
        );
    }

    /**
     * Keystone (spec §3): the plan carries a 'line_ref' holding the structured keys RemittanceRef pulls
     * from the entry (QRR/SCOR reference + Swico /10/ token) PLUS the counterparty IBAN — reusing the
     * IBAN EntryPlan already derives (raw here; the HMAC is applied later by the write glue, §9).
     */
    public function test_plan_xml_attaches_line_ref(): void
    {
        $ntry = $this->makeNtry(<<<'XML'
            <Ntry>
              <Amt Ccy="CHF">15.02</Amt>
              <CdtDbtInd>CRDT</CdtDbtInd>
              <AcctSvcrRef>ref-qr-1</AcctSvcrRef>
              <NtryDtls><TxDtls>
                <RltdPties>
                  <Dbtr><Nm>Client</Nm></Dbtr>
                  <DbtrAcct><Id><IBAN>CH9300762011623852957</IBAN></Id></DbtrAcct>
                </RltdPties>
                <RmtInf><Strd>
                  <CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>210000000003139471430009017</Ref></CdtrRefInf>
                  <AddtlRmtInf>//S1/10/TC1-2605-0158/11/260528</AddtlRmtInf>
                </Strd></RmtInf>
              </TxDtls></NtryDtls>
            </Ntry>
            XML);

        $plan = EntryPlan::planXmlEntry($ntry, self::DATEO, self::DATEV, true, self::FEE_BASE);

        $this->assertSame([
            'structured_ref'      => '210000000003139471430009017',
            'structured_ref_type' => 'QRR',
            'invoice_ref_token'   => 'TC1-2605-0158',
            'counterparty_iban'   => 'CH9300762011623852957',
        ], $plan['line_ref']);
    }

    /**
     * The CSV path has no structured/QR reference, but it does carry the counterparty IBAN — so its
     * line_ref holds the IBAN only (structured fields null), keeping the own-transfer filter and L1
     * IBAN-match uniform across import sources (spec §10).
     */
    public function test_plan_csv_attaches_line_ref_with_iban_only(): void
    {
        $fieldMapping = [
            'booking_date' => 0, 'value_date' => 1, 'payment_purpose' => 2, 'amount' => 3,
            'mandate_reference' => 4, 'counterparty_bic' => 5, 'counterparty_iban' => 6,
            'counterparty_name' => 7, 'collector_reference' => 8, 'creditor_id' => 9,
        ];
        $data = [
            '28.04.26', '28.04.26', 'Office supplies', '-42,50', 'MND-7',
            'BICDE00', 'DE00123456780000000099', 'Stationers Ltd', '', '',
        ];

        $plan = EntryPlan::planCsvRow($data, $fieldMapping, -42.50, self::DATEO, self::DATEV);

        $this->assertSame([
            'structured_ref'      => null,
            'structured_ref_type' => null,
            'invoice_ref_token'   => null,
            'counterparty_iban'   => 'DE00123456780000000099',
        ], $plan['line_ref']);
    }
}
