<?php

namespace BankImport\Tests\Unit;

use BankImport\RemittanceRef;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of RemittanceRef::parse().
 *
 * The keystone (spec §3) needs, per imported CAMT entry, the structured keys that survive ingestion
 * only if we extract them now: the structured creditor reference (QRR / SCOR) and the Dolibarr Swico
 * S1 billing-info invoice-ref token (/10/). parse() is a pure SimpleXML→array helper (zero Dolibarr
 * coupling), mirroring StatementSummary. The counterparty IBAN is NOT this class's concern — EntryPlan
 * already derives it (direction-aware), and EntryPlanTest already covers that, so re-extracting it here
 * would duplicate the logic (DRY).
 *
 * Minimum XML per branch, per [[feedback-test-factories-yagni]].
 */
class RemittanceRefParseTest extends TestCase
{
    /**
     * Load a single <Ntry> fragment the way the live import path reads CAMT: strip the
     * default namespace before SimpleXML so element access via property syntax works.
     */
    private function loadNtry(string $xml): \SimpleXMLElement
    {
        $xml = preg_replace('/\sxmlns="[^"]+"/', '', $xml, 1);
        return simplexml_load_string($xml);
    }

    /**
     * Happy path: a Swiss QR-bill payment. The structured QRR reference sits in Strd/CdtrRefInf, and
     * the Dolibarr billing-info (/10/ = invoice ref) in Strd/AddtlRmtInf.
     */
    public function testParsesQrrAndSwicoToken(): void
    {
        $ntry = $this->loadNtry(
            '<Ntry xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">'
            . '<Amt Ccy="CHF">15.02</Amt>'
            . '<CdtDbtInd>CRDT</CdtDbtInd>'
            . '<NtryDtls><TxDtls><RmtInf><Strd>'
            .   '<CdtrRefInf>'
            .     '<Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp>'
            .     '<Ref>210000000003139471430009017</Ref>'
            .   '</CdtrRefInf>'
            .   '<AddtlRmtInf>//S1/10/TC1-2605-0158/11/260528/30/CHE-106.017.086</AddtlRmtInf>'
            . '</Strd></RmtInf></TxDtls></NtryDtls>'
            . '</Ntry>'
        );

        $result = RemittanceRef::parse($ntry);

        $this->assertSame('210000000003139471430009017', $result['structured_ref']);
        $this->assertSame('QRR', $result['structured_ref_type']);
        $this->assertSame('TC1-2605-0158', $result['invoice_ref_token']);
    }

    /**
     * SCOR / ISO 11649 (RF) reference: the type comes from Tp/CdOrPrtry/Cd (an ISO code) instead of
     * Prtry. This is the structured key a foreign issuer using a creditor reference rather than the
     * Swiss-domestic QRR would send.
     */
    public function testParsesScorReferenceFromCode(): void
    {
        $ntry = $this->loadNtry(
            '<Ntry xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">'
            . '<Amt Ccy="CHF">200.00</Amt>'
            . '<CdtDbtInd>CRDT</CdtDbtInd>'
            . '<NtryDtls><TxDtls><RmtInf><Strd><CdtrRefInf>'
            .   '<Tp><CdOrPrtry><Cd>SCOR</Cd></CdOrPrtry></Tp>'
            .   '<Ref>RF18539007547034</Ref>'
            . '</CdtrRefInf></Strd></RmtInf></TxDtls></NtryDtls>'
            . '</Ntry>'
        );

        $result = RemittanceRef::parse($ntry);

        $this->assertSame('RF18539007547034', $result['structured_ref']);
        $this->assertSame('SCOR', $result['structured_ref_type']);
    }

    /**
     * The Swico billing info is not always in Strd/AddtlRmtInf; some banks place it in the
     * unstructured RmtInf/Ustrd. The /10/ token must still be recovered from there.
     */
    public function testParsesSwicoTokenFromUnstructuredRemittance(): void
    {
        $ntry = $this->loadNtry(
            '<Ntry xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">'
            . '<Amt Ccy="CHF">42.00</Amt>'
            . '<CdtDbtInd>CRDT</CdtDbtInd>'
            . '<NtryDtls><TxDtls><RmtInf>'
            .   '<Ustrd>//S1/10/INV-2026-0099/11/260601/30/CHE-106.017.086</Ustrd>'
            . '</RmtInf></TxDtls></NtryDtls>'
            . '</Ntry>'
        );

        $result = RemittanceRef::parse($ntry);

        $this->assertSame('INV-2026-0099', $result['invoice_ref_token']);
    }

    /**
     * Fallthrough: the first Swico candidate carries an EMPTY /10/, so the extractor must skip it and
     * take the /10/ from the next candidate rather than returning an empty string. This is the only
     * non-obvious branch in the file.
     */
    public function testSkipsEmptySwicoTokenAndTakesNextCandidate(): void
    {
        $ntry = $this->loadNtry(
            '<Ntry xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">'
            . '<Amt Ccy="CHF">12.00</Amt>'
            . '<CdtDbtInd>CRDT</CdtDbtInd>'
            . '<NtryDtls><TxDtls><RmtInf>'
            .   '<Strd><AddtlRmtInf>//S1/10//11/260601</AddtlRmtInf></Strd>'
            .   '<Ustrd>//S1/10/INV-REAL-0007/11/260601</Ustrd>'
            . '</RmtInf></TxDtls></NtryDtls>'
            . '</Ntry>'
        );

        $result = RemittanceRef::parse($ntry);

        $this->assertSame('INV-REAL-0007', $result['invoice_ref_token']);
    }

    /**
     * A booked entry with no transaction details at all (the !isset early return) must yield three
     * nulls without a PHP warning — failOnWarning is on, so a stray notice would fail the suite.
     */
    public function testReturnsAllNullWhenNtryDtlsAbsent(): void
    {
        $ntry = $this->loadNtry(
            '<Ntry xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">'
            . '<Amt Ccy="CHF">99.00</Amt>'
            . '<CdtDbtInd>CRDT</CdtDbtInd>'
            . '</Ntry>'
        );

        $result = RemittanceRef::parse($ntry);

        $this->assertNull($result['structured_ref']);
        $this->assertNull($result['structured_ref_type']);
        $this->assertNull($result['invoice_ref_token']);
    }

    /**
     * TxDtls present but carrying neither a structured reference nor Swico billing info (e.g. a bank
     * fee line) must also yield three nulls.
     */
    public function testReturnsAllNullWhenNoReferenceBlocks(): void
    {
        $ntry = $this->loadNtry(
            '<Ntry xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">'
            . '<Amt Ccy="CHF">10.00</Amt>'
            . '<CdtDbtInd>DBIT</CdtDbtInd>'
            . '<NtryDtls><TxDtls></TxDtls></NtryDtls>'
            . '</Ntry>'
        );

        $result = RemittanceRef::parse($ntry);

        $this->assertNull($result['structured_ref']);
        $this->assertNull($result['structured_ref_type']);
        $this->assertNull($result['invoice_ref_token']);
    }
}
