<?php

namespace BankImport\Tests\Unit;

use BankImport\StatementSummary;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of StatementSummary::parse().
 *
 * Test 1 anchors the happy-path return shape against the sanitized Revolut
 * fixture committed in tests/fixtures/. Tests 2-5 cover edge cases that the
 * external review (2026-05-29) flagged as easy to miss: TxsSummry absent,
 * multi-Stmt per currency, negative balance with DBIT sign, and entries
 * without AcctSvcrRef. Each artificial fixture is the minimum XML required
 * to exercise the specific branch — no shared fixture builder beyond the
 * envelope helper, per [[feedback-test-factories-yagni]].
 */
class StatementSummaryParseTest extends TestCase
{
    /**
     * Load a CAMT.053 file from tests/fixtures/ the same way the live import
     * path does: strip the default namespace before passing to SimpleXML so
     * that subsequent element access via property syntax works.
     */
    private function loadFixture(string $name): \SimpleXMLElement
    {
        $content = file_get_contents(__DIR__ . '/../fixtures/' . $name);
        $content = preg_replace('/\sxmlns="[^"]+"/', '', $content, 1);
        return simplexml_load_string($content);
    }

    /**
     * Wrap one or more <Stmt> XML fragments in a minimal CAMT.053 envelope
     * (Document + BkToCstmrStmt + GrpHdr) and return a parsed SimpleXMLElement.
     * Used by edge-case tests to keep their artificial XML small.
     */
    private function wrapStmts(string $stmtsXml): \SimpleXMLElement
    {
        $content = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">'
            .   '<BkToCstmrStmt>'
            .     '<GrpHdr>'
            .       '<MsgId>test-msg</MsgId>'
            .       '<CreDtTm>2026-01-01T00:00:00Z</CreDtTm>'
            .     '</GrpHdr>'
            .     $stmtsXml
            .   '</BkToCstmrStmt>'
            . '</Document>';
        $content = preg_replace('/\sxmlns="[^"]+"/', '', $content, 1);
        return simplexml_load_string($content);
    }

    /**
     * Happy path: parse the real (sanitized) Revolut Jan-May 2026 statement
     * and assert the top-level Stmt structure, the TxsSummry-derived counts
     * and sums, both Bal blocks (OPBD/CLBD), and a spot-checked entry that
     * carries Chrgs (will be the canonical FeeSplitter case in v0.0.14).
     */
    public function test_parse_revolut_fixture_extracts_one_stmt_with_all_summary_fields(): void
    {
        $xml = $this->loadFixture('revolut_jan_may_2026.xml');

        $result = StatementSummary::parse($xml);

        $this->assertCount(1, $result, 'Revolut statement has exactly one <Stmt> block.');
        $stmt = $result[0];

        $this->assertSame('fc3b2d4c9e234852841712ff90fc333f', $stmt['id']);
        $this->assertSame('1780061445049', $stmt['electronic_seq_nb']);
        $this->assertSame('CHF', $stmt['currency']);

        $this->assertEqualsWithDelta(235.58, $stmt['opbd'], 0.005, 'OPBD is signed positive (CRDT).');
        $this->assertEqualsWithDelta(1453.66, $stmt['clbd'], 0.005, 'CLBD is signed positive (CRDT).');

        $this->assertTrue($stmt['summary_available'], 'Fixture has <TxsSummry>.');
        $this->assertSame(27, $stmt['count']);
        $this->assertSame(8, $stmt['credit_count']);
        $this->assertSame(19, $stmt['debit_count']);
        $this->assertEqualsWithDelta(4184.48, $stmt['credit_sum'], 0.005, 'TtlCdtNtries.Sum is unsigned.');
        $this->assertEqualsWithDelta(2966.40, $stmt['debit_sum'], 0.005, 'TtlDbtNtries.Sum is unsigned.');
        $this->assertEqualsWithDelta(1218.08, $stmt['net_entry'], 0.005, 'TtlNetNtry.Amt with CRDT sign applied.');

        $this->assertCount(27, $stmt['entries'], 'Every fixture Ntry has an AcctSvcrRef.');
        $this->assertSame([], $stmt['unaddressable_entries']);

        // Spot check: the FX inflow with embedded Chrgs (2026-03-25).
        $this->assertArrayHasKey('69c3f27bc99ea57298c66630d7daaeab', $stmt['entries']);
        $fx = $stmt['entries']['69c3f27bc99ea57298c66630d7daaeab'];
        $this->assertEqualsWithDelta(2434.48, $fx['signed'], 0.005, 'CRDT keeps positive sign.');
        $this->assertSame('CHF', $fx['currency']);

        // Spot check: a DBIT entry has its sign flipped.
        $this->assertArrayHasKey('69c3f2bf7ef8af98a77c6ba85de1eab9', $stmt['entries']);
        $dbit = $stmt['entries']['69c3f2bf7ef8af98a77c6ba85de1eab9'];
        $this->assertEqualsWithDelta(-1300.00, $dbit['signed'], 0.005, 'DBIT (-Amt): vehicle purchase 1300 CHF.');
    }

    /**
     * <TxsSummry> is an optional block in the CAMT.053 schema. When absent
     * (some banks omit it entirely), parse() must NOT fabricate zeros — it
     * sets summary_available=false and leaves the count/sum fields as null
     * so verify() can gracefully skip checks #1 and #2 for this Stmt and
     * still run the per-entry check (#3) on the Bal/Ntry blocks that ARE there.
     */
    public function test_parse_summary_absent_marks_unavailable_and_nulls_count_fields(): void
    {
        $xml = $this->wrapStmts(<<<'XML'
            <Stmt>
              <Id>stmt-no-summary</Id>
              <ElctrncSeqNb>1</ElctrncSeqNb>
              <Acct><Id><IBAN>LT000000000000000001</IBAN></Id><Ccy>CHF</Ccy></Acct>
              <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-01T00:00:00Z</DtTm></Dt>
              </Bal>
              <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">150.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-31T23:59:59Z</DtTm></Dt>
              </Bal>
              <Ntry>
                <Amt Ccy="CHF">50.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <BookgDt><DtTm>2026-01-15T00:00:00Z</DtTm></BookgDt>
                <ValDt><DtTm>2026-01-15T00:00:00Z</DtTm></ValDt>
                <AcctSvcrRef>entry-1</AcctSvcrRef>
              </Ntry>
            </Stmt>
            XML);

        $result = StatementSummary::parse($xml);

        $this->assertCount(1, $result);
        $stmt = $result[0];

        $this->assertFalse($stmt['summary_available']);
        $this->assertNull($stmt['count']);
        $this->assertNull($stmt['credit_count']);
        $this->assertNull($stmt['debit_count']);
        $this->assertNull($stmt['credit_sum']);
        $this->assertNull($stmt['debit_sum']);
        $this->assertNull($stmt['net_entry']);

        // Bal and entries still parsed despite missing summary.
        $this->assertEqualsWithDelta(100.00, $stmt['opbd'], 0.005);
        $this->assertEqualsWithDelta(150.00, $stmt['clbd'], 0.005);
        $this->assertCount(1, $stmt['entries']);
        $this->assertEqualsWithDelta(50.00, $stmt['entries']['entry-1']['signed'], 0.005);
    }

    /**
     * Multi-currency accounts (Revolut Business with several sub-accounts)
     * can emit one <Stmt> per currency in a single CAMT.053 file. parse()
     * must return one array element per Stmt, each carrying its own
     * currency / balances / entries. verify() will iterate and check each
     * independently.
     */
    public function test_parse_multi_stmt_returns_one_entry_per_stmt_block(): void
    {
        $chf = <<<'XML'
            <Stmt>
              <Id>stmt-chf</Id>
              <Acct><Id><IBAN>LT000000000000000001</IBAN></Id><Ccy>CHF</Ccy></Acct>
              <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-01T00:00:00Z</DtTm></Dt></Bal>
              <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-31T23:59:59Z</DtTm></Dt></Bal>
            </Stmt>
            XML;
        $eur = <<<'XML'
            <Stmt>
              <Id>stmt-eur</Id>
              <Acct><Id><IBAN>LT000000000000000002</IBAN></Id><Ccy>EUR</Ccy></Acct>
              <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-01T00:00:00Z</DtTm></Dt></Bal>
              <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-31T23:59:59Z</DtTm></Dt></Bal>
            </Stmt>
            XML;

        $result = StatementSummary::parse($this->wrapStmts($chf . $eur));

        $this->assertCount(2, $result);
        $this->assertSame('stmt-chf', $result[0]['id']);
        $this->assertSame('CHF', $result[0]['currency']);
        $this->assertEqualsWithDelta(100.00, $result[0]['opbd'], 0.005);
        $this->assertSame('stmt-eur', $result[1]['id']);
        $this->assertSame('EUR', $result[1]['currency']);
        $this->assertEqualsWithDelta(200.00, $result[1]['opbd'], 0.005);
    }

    /**
     * A <Bal> with CdtDbtInd=DBIT represents a negative balance (overdraft);
     * the <Amt> is still emitted as a positive value and the sign is carried
     * by CdtDbtInd. parse() must apply the sign so that downstream comparison
     * with Dolibarr's signed balance does not silently flip.
     */
    public function test_parse_negative_balance_with_dbit_sign_returns_negative_opbd(): void
    {
        $xml = $this->wrapStmts(<<<'XML'
            <Stmt>
              <Id>stmt-overdraft</Id>
              <Acct><Id><IBAN>LT000000000000000001</IBAN></Id><Ccy>CHF</Ccy></Acct>
              <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">50.25</Amt><CdtDbtInd>DBIT</CdtDbtInd>
                <Dt><DtTm>2026-01-01T00:00:00Z</DtTm></Dt>
              </Bal>
              <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">10.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-31T23:59:59Z</DtTm></Dt>
              </Bal>
            </Stmt>
            XML);

        $result = StatementSummary::parse($xml);

        $this->assertEqualsWithDelta(-50.25, $result[0]['opbd'], 0.005, 'DBIT negates the Amt.');
        $this->assertEqualsWithDelta(10.00, $result[0]['clbd'], 0.005, 'CRDT keeps positive.');
    }

    /**
     * AcctSvcrRef is technically optional in CAMT.053 (Revolut always emits it,
     * but other banks may not). For such entries we cannot key the per-entry
     * map by ref — they land in a separate "unaddressable_entries" bucket so
     * verify() can still tally their sum but skip per-entry comparison.
     */
    public function test_parse_ntry_without_acctsvcrref_lands_in_unaddressable_bucket(): void
    {
        $xml = $this->wrapStmts(<<<'XML'
            <Stmt>
              <Id>stmt-with-anonymous</Id>
              <Acct><Id><IBAN>LT000000000000000001</IBAN></Id><Ccy>CHF</Ccy></Acct>
              <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">0</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-01T00:00:00Z</DtTm></Dt></Bal>
              <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">75.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-31T23:59:59Z</DtTm></Dt></Bal>
              <Ntry>
                <Amt Ccy="CHF">25.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <BookgDt><DtTm>2026-01-10T00:00:00Z</DtTm></BookgDt>
                <ValDt><DtTm>2026-01-10T00:00:00Z</DtTm></ValDt>
                <AcctSvcrRef>has-ref</AcctSvcrRef>
              </Ntry>
              <Ntry>
                <Amt Ccy="CHF">50.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <BookgDt><DtTm>2026-01-20T00:00:00Z</DtTm></BookgDt>
                <ValDt><DtTm>2026-01-20T00:00:00Z</DtTm></ValDt>
              </Ntry>
            </Stmt>
            XML);

        $result = StatementSummary::parse($xml);
        $stmt = $result[0];

        $this->assertCount(1, $stmt['entries'], 'Only the entry with a ref is keyed.');
        $this->assertEqualsWithDelta(25.00, $stmt['entries']['has-ref']['signed'], 0.005);

        $this->assertCount(1, $stmt['unaddressable_entries']);
        $this->assertEqualsWithDelta(50.00, $stmt['unaddressable_entries'][0]['signed'], 0.005);
        $this->assertSame('CHF', $stmt['unaddressable_entries'][0]['currency']);
    }

    /**
     * <TxsSummry>'s four sub-blocks (TtlNtries, TtlCdtNtries, TtlDbtNtries,
     * TtlNetNtry inside TtlNtries) are each independently optional per the
     * ISO 20022 schema. A bank that emits only a subset must NOT cause us
     * to fabricate zeros for the missing ones — verify() would then false-
     * positive against the bank's real (non-zero) sums in DB. Each missing
     * sub-block degrades the corresponding result field to null so that
     * verify() can skip just that specific check.
     */
    public function test_parse_partial_summary_nulls_only_missing_subblocks(): void
    {
        $xml = $this->wrapStmts(<<<'XML'
            <Stmt>
              <Id>stmt-partial-summary</Id>
              <Acct><Id><IBAN>LT000000000000000001</IBAN></Id><Ccy>CHF</Ccy></Acct>
              <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">0</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-01T00:00:00Z</DtTm></Dt></Bal>
              <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">0</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-31T23:59:59Z</DtTm></Dt></Bal>
              <TxsSummry>
                <TtlNtries>
                  <NbOfNtries>5</NbOfNtries>
                </TtlNtries>
              </TxsSummry>
            </Stmt>
            XML);

        $result = StatementSummary::parse($xml);
        $stmt = $result[0];

        $this->assertTrue($stmt['summary_available'], 'TxsSummry block itself is present.');
        $this->assertSame(5, $stmt['count'], 'TtlNtries/NbOfNtries is present.');

        $this->assertNull($stmt['credit_count'], 'TtlCdtNtries sub-block absent.');
        $this->assertNull($stmt['debit_count'], 'TtlDbtNtries sub-block absent.');
        $this->assertNull($stmt['credit_sum'], 'TtlCdtNtries sub-block absent.');
        $this->assertNull($stmt['debit_sum'], 'TtlDbtNtries sub-block absent.');
        $this->assertNull($stmt['net_entry'], 'TtlNetNtry inside TtlNtries absent.');
    }

    /**
     * A pathological CAMT.053 source may emit two <Ntry> elements with the
     * same <AcctSvcrRef> (bank bug or malformed export). Silently letting
     * the second one overwrite the first in entries[ref] would hide
     * exactly the kind of under-count the verification feature exists to
     * catch. Instead we aggregate: the signed amount of every Ntry sharing
     * a ref is summed under that ref. The downstream per-entry check then
     * compares the aggregate expected vs the aggregate from DB (which is
     * also a sum of all bank lines carrying that ref in num_releve), so a
     * false-skip of the duplicate still surfaces as a mismatch.
     */
    public function test_parse_duplicate_acctsvcrref_aggregates_signed_amounts(): void
    {
        $xml = $this->wrapStmts(<<<'XML'
            <Stmt>
              <Id>stmt-duplicate-ref</Id>
              <Acct><Id><IBAN>LT000000000000000001</IBAN></Id><Ccy>CHF</Ccy></Acct>
              <Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">0</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-01T00:00:00Z</DtTm></Dt></Bal>
              <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="CHF">150.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><DtTm>2026-01-31T23:59:59Z</DtTm></Dt></Bal>
              <Ntry>
                <Amt Ccy="CHF">100.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <BookgDt><DtTm>2026-01-10T00:00:00Z</DtTm></BookgDt>
                <ValDt><DtTm>2026-01-10T00:00:00Z</DtTm></ValDt>
                <AcctSvcrRef>dup-ref</AcctSvcrRef>
              </Ntry>
              <Ntry>
                <Amt Ccy="CHF">50.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <BookgDt><DtTm>2026-01-20T00:00:00Z</DtTm></BookgDt>
                <ValDt><DtTm>2026-01-20T00:00:00Z</DtTm></ValDt>
                <AcctSvcrRef>dup-ref</AcctSvcrRef>
              </Ntry>
            </Stmt>
            XML);

        $result = StatementSummary::parse($xml);
        $stmt = $result[0];

        $this->assertCount(1, $stmt['entries'],
            'Both entries collapse to one map key (the shared ref).');
        $this->assertEqualsWithDelta(150.00, $stmt['entries']['dup-ref']['signed'], 0.005,
            'Signed amounts of the two duplicate-ref entries must be summed (100 + 50 = 150).');
        $this->assertSame('CHF', $stmt['entries']['dup-ref']['currency']);
        $this->assertSame([], $stmt['unaddressable_entries']);
    }
}
