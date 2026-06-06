<?php

namespace BankImport;

/**
 * Pure helper that extracts the invoice-matching keys from a single CAMT.053 <Ntry>.
 *
 * These keys survive ingestion only if pulled out at import time (the keystone, spec §3):
 * Dolibarr's import flattens an entry into one llx_bank row whose structured reference and
 * counterparty IBAN would otherwise be lost or buried in the free-text note. parse() reads
 * only a SimpleXMLElement and returns a plain array, so it is unit-testable without the
 * Dolibarr runtime (mirrors StatementSummary / FeeSplitter).
 *
 * What it recovers, per spec §12.4:
 *  - structured_ref / structured_ref_type — the creditor reference from RmtInf/Strd/CdtrRefInf.
 *    A real QRR (proprietary, Tp/CdOrPrtry/Prtry) is what foreign/supplier QR-bills carry; an
 *    ISO 11649 reference is SCOR (code, Tp/CdOrPrtry/Cd). This is the deterministic key for the
 *    purchase / third-party direction.
 *  - invoice_ref_token — for our OWN sales QR-bills Dolibarr emits reference type NON and instead
 *    puts the invoice ref into the Swico S1 billing information (//S1/10/<ref>/11/<date>/...). The
 *    /10/ field is the invoice ref; recovering it gives a direct llx_facture.ref lookup.
 *  - counterparty_iban — the OTHER party's IBAN, picked by direction: a credit (CRDT, money in)
 *    means the debtor is the counterparty; a debit (DBIT, money out) means the creditor is. Returned
 *    RAW — pseudonymisation (HMAC, spec §9/§11) is a separate concern applied by the caller, not here,
 *    so the parser stays pure and side-effect-free.
 *
 * Every field is best-effort: a missing block yields null rather than an error, because real CAMT
 * files omit whichever blocks do not apply to a given entry.
 */
class RemittanceRef
{
    /** Marker that identifies a Swico S1 structured billing-info string inside remittance text. */
    private const SWICO_S1_MARKER = '//S1';

    /** Swico S1 field tag carrying the invoice reference (the /10/ field). */
    private const SWICO_TAG_INVOICE_REF = '10';

    /**
     * Extract the matching keys from one <Ntry>.
     *
     * @param  \SimpleXMLElement $ntry  A CAMT.053 <Ntry> element with the default namespace already
     *                                  stripped (the live import strips it before SimpleXML, so a
     *                                  caller can use property access).
     * @return array{structured_ref: ?string, structured_ref_type: ?string, invoice_ref_token: ?string, counterparty_iban: ?string}
     */
    public static function parse(\SimpleXMLElement $ntry): array
    {
        $result = array(
            'structured_ref'      => null,
            'structured_ref_type' => null,
            'invoice_ref_token'   => null,
            'counterparty_iban'   => null,
        );

        // CAMT may split one booked entry into several transaction details; v0.1 reads the first
        // (a single-invoice payment, which is the case the keystone targets — N:1 splits are v0.2).
        if (!isset($ntry->NtryDtls->TxDtls)) {
            return $result;
        }
        $txDtls = $ntry->NtryDtls->TxDtls;

        // --- Structured creditor reference (QRR / SCOR) ---
        // Read the first Strd block that carries a CdtrRefInf. We iterate over all Strd (rather than
        // index the first) for the same reason extractSwicoInvoiceRef does — a bank may emit several
        // Strd blocks — so both walks of RmtInf/Strd are consistent.
        if (isset($txDtls->RmtInf)) {
            foreach ($txDtls->RmtInf->Strd as $strd) {
                if (!isset($strd->CdtrRefInf)) {
                    continue;
                }
                $cdtrRefInf = $strd->CdtrRefInf;

                $ref = isset($cdtrRefInf->Ref) ? trim((string) $cdtrRefInf->Ref) : '';
                if ($ref !== '') {
                    $result['structured_ref'] = $ref;
                }

                // The type is captured RAW and NOT validated: a proprietary type (typically QRR) lives
                // under Prtry, an ISO code (e.g. SCOR) under Cd — but a foreign issuer may use another
                // proprietary string, so a consumer must not assume the value equals 'QRR'.
                if (isset($cdtrRefInf->Tp->CdOrPrtry->Prtry)) {
                    $type = trim((string) $cdtrRefInf->Tp->CdOrPrtry->Prtry);
                } elseif (isset($cdtrRefInf->Tp->CdOrPrtry->Cd)) {
                    $type = trim((string) $cdtrRefInf->Tp->CdOrPrtry->Cd);
                } else {
                    $type = '';
                }
                if ($type !== '') {
                    $result['structured_ref_type'] = $type;
                }

                break; // first Strd with a CdtrRefInf wins
            }
        }

        // --- Swico S1 billing-info invoice-ref token (/10/) ---
        $result['invoice_ref_token'] = self::extractSwicoInvoiceRef($txDtls);

        // --- Counterparty IBAN, chosen by direction ---
        $result['counterparty_iban'] = self::extractCounterpartyIban($ntry, $txDtls);

        return $result;
    }

    /**
     * Find the Swico S1 billing-info string (in Strd/AddtlRmtInf or, as a fallback, RmtInf/Ustrd)
     * and return the value of its /10/ field — the invoice reference Dolibarr writes there.
     *
     * The reference itself never contains '/' (the generator strips it), so the value is everything
     * after /10/ up to the next '/'. We only trust a candidate that carries the //S1 marker, to avoid
     * matching a stray "/10/" in unrelated free text.
     */
    private static function extractSwicoInvoiceRef(\SimpleXMLElement $txDtls): ?string
    {
        if (!isset($txDtls->RmtInf)) {
            return null;
        }

        $candidates = array();
        foreach ($txDtls->RmtInf->Strd as $strd) {
            foreach ($strd->AddtlRmtInf as $addtl) {
                $candidates[] = (string) $addtl;
            }
        }
        foreach ($txDtls->RmtInf->Ustrd as $ustrd) {
            $candidates[] = (string) $ustrd;
        }

        // The /10/ field is searched only after confirming the Swico marker, so a stray "/10/" in
        // unrelated free text is ignored. We do NOT anchor /10/ to the marker, because Swico S1 fields
        // have no guaranteed order — /10/ need not be the first field after //S1.
        $tagPattern = '#/' . self::SWICO_TAG_INVOICE_REF . '/([^/]*)#';
        foreach ($candidates as $text) {
            if (strpos($text, self::SWICO_S1_MARKER) === false) {
                continue;
            }
            if (preg_match($tagPattern, $text, $m)) {
                $value = trim($m[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Pick the counterparty IBAN from RltdPties based on the entry direction: on a credit the money
     * comes FROM the debtor, on a debit it goes TO the creditor. Returns null when the direction is
     * unknown or the relevant account block is absent.
     *
     * Direction is read from TxDtls/CdtDbtInd when present (a reversal/storno can flip the transaction
     * direction relative to the booked Ntry), falling back to Ntry/CdtDbtInd.
     */
    private static function extractCounterpartyIban(\SimpleXMLElement $ntry, \SimpleXMLElement $txDtls): ?string
    {
        if (!isset($txDtls->RltdPties)) {
            return null;
        }
        $rltd = $txDtls->RltdPties;
        $direction = (isset($txDtls->CdtDbtInd) && trim((string) $txDtls->CdtDbtInd) !== '')
            ? trim((string) $txDtls->CdtDbtInd)
            : trim((string) $ntry->CdtDbtInd);

        if ($direction === 'CRDT') {
            $iban = isset($rltd->DbtrAcct->Id->IBAN) ? trim((string) $rltd->DbtrAcct->Id->IBAN) : '';
        } elseif ($direction === 'DBIT') {
            $iban = isset($rltd->CdtrAcct->Id->IBAN) ? trim((string) $rltd->CdtrAcct->Id->IBAN) : '';
        } else {
            $iban = '';
        }

        return $iban !== '' ? $iban : null;
    }
}
