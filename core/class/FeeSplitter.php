<?php

namespace BankImport;

/**
 * Pure helper that decides whether a CAMT.053 <Ntry> carrying an embedded fee
 * (<Chrgs>) should be broken out into two bank lines — the principal and the
 * fee — and computes those two signed amounts.
 *
 * Zero Dolibarr coupling: it reads a SimpleXMLElement and returns a plain array
 * (or null), so it is unit-testable without the runtime, mirroring
 * StatementSummary. The human-readable fee label is intentionally NOT produced
 * here; the Dolibarr integration layer composes it from a translation string so
 * this class stays i18n-agnostic.
 *
 * Two facts, established from three real Revolut 2025 statements (CHF/EUR/PLN,
 * camt.053.001.08), shape the whole design:
 *
 *  1. The entry <Amt> is fee-INCLUSIVE — it is the amount actually posted to the
 *     account. (Summing all 33 EUR debit entries reproduced the file's
 *     TtlDbtNtries.Sum to the cent.) A split must therefore preserve
 *
 *         main_amount + fee_amount == signed(Amt)
 *
 *     so that the two rows stored under one AcctSvcrRef aggregate back to the
 *     original Amt and the v0.0.13 verify() per-entry check stays green.
 *
 *  2. For FX between the holder's own accounts, Revolut emits the SAME
 *     AcctSvcrRef on both legs and charges the fee in the TARGET currency. The
 *     source-currency leg then advertises a fee in a DIFFERENT currency from its
 *     own <Amt>; that fee was not deducted from this account (it is deducted, and
 *     split, on the other leg in its own statement). Splitting it here would both
 *     double-count the fee and invent a charge in the wrong currency. Hence the
 *     same-currency guard below. verify() cannot catch such a mistake — the two
 *     sub-lines still net to Amt — so the guard is the only safeguard.
 *
 * Sign convention: a fee is always a cost, so fee_amount is always <= 0
 * regardless of whether the entry itself is a credit or a debit. main_amount
 * carries the remainder needed to satisfy the invariant (for a credit inflow it
 * is the gross proceeds before the fee; for a debit it is the principal).
 */
class FeeSplitter
{
    /**
     * Smallest fee worth a dedicated line — half a cent, matching the tolerance
     * StatementSummary uses elsewhere. A <Chrgs> total that rounds below this is
     * treated as "no fee" so we never emit a 0.00 line (and the
     * fee-is-strictly-a-cost contract holds).
     */
    private const MIN_FEE = 0.005;

    /**
     * Inspect one <Ntry> and return how to split it, or null to leave it whole.
     *
     * Returns null when ANY of the following holds:
     *   - the entry has no <Chrgs> block;
     *   - the charge resolves to (near) zero;
     *   - the charge currency cannot be confirmed equal to the entry currency
     *     (cross-currency fee — belongs to the other FX leg; see class docblock).
     *
     * Otherwise returns:
     *   [
     *     'main_amount'  => float, // signed principal / gross (invariant partner of fee)
     *     'fee_amount'   => float, // signed, always <= 0 (a fee is a cost)
     *     'fee_currency' => string // == entry currency (guard guarantees this)
     *   ]
     *
     * @param \SimpleXMLElement $ntry A namespace-stripped CAMT.053 <Ntry> element.
     * @return array{main_amount: float, fee_amount: float, fee_currency: string}|null
     */
    public static function extract(\SimpleXMLElement $ntry): ?array
    {
        if (!isset($ntry->Chrgs)) {
            return null;
        }

        // A split is only safe when the entry has a stable per-entry reference (AcctSvcrRef).
        // Both resulting lines are stored under it (num_chq), so post-import verification
        // folds them back into one logical entry and the dedup key derives from it. Without
        // a ref the two lines would be counted as two logical entries (breaking the verify
        // count against NbOfNtries) and a re-import could duplicate them (the amount-based
        // composite dedup key differs between the split principal and the unsplit amount), so
        // we decline to split and leave the entry whole. Revolut always emits AcctSvcrRef;
        // CAMT.053 allows omitting it. Trimmed to agree with how the caller derives num_chq.
        if (trim((string) $ntry->AcctSvcrRef) === '') {
            return null;
        }

        $fee = self::resolveCharge($ntry->Chrgs);
        if ($fee === null) {
            return null;
        }
        [$feeMagnitude, $feeCurrency] = $fee;

        if ($feeMagnitude < self::MIN_FEE) {
            return null;
        }

        // Same-currency guard. Require a POSITIVE match (both present and equal):
        // when in doubt we must NOT split, because a wrong cross-currency split
        // invents a fee that verify() cannot detect, whereas declining to split
        // only leaves the fee embedded in Amt — financially harmless.
        $entryCurrency = (string) $ntry->Amt['Ccy'];
        if ($entryCurrency === '' || $feeCurrency === '' || $entryCurrency !== $feeCurrency) {
            return null;
        }

        $signedAmount = self::signedAmount($ntry);
        $feeAmount = -$feeMagnitude;            // a fee is always a cost
        $mainAmount = $signedAmount - $feeAmount; // => signedAmount + feeMagnitude; preserves the invariant

        return [
            'main_amount'  => $mainAmount,
            'fee_amount'   => $feeAmount,
            'fee_currency' => $feeCurrency,
        ];
    }

    /**
     * Resolve a <Chrgs> block to a [magnitude, currency] pair, or null when it
     * carries no usable amount.
     *
     * Revolut emits a pre-summed <TtlChrgsAndTaxAmt>, which we prefer when present
     * (using the itemised records as well would double count). The CAMT.053 schema
     * also allows itemised <Rcrd> charge records with no total; when only those are
     * present we sum their magnitudes so a multi-fee entry is not silently left
     * unsplit. Currency is taken from the total, or from the first record.
     *
     * @return array{0: float, 1: string}|null [magnitude (>= 0), currency]
     */
    private static function resolveCharge(\SimpleXMLElement $chrgs): ?array
    {
        if (isset($chrgs->TtlChrgsAndTaxAmt)) {
            $total = $chrgs->TtlChrgsAndTaxAmt;
            return [abs((float) $total), (string) $total['Ccy']];
        }

        if (isset($chrgs->Rcrd)) {
            $magnitude = 0.0;
            $currency = '';
            foreach ($chrgs->Rcrd as $rcrd) {
                if (!isset($rcrd->Amt)) {
                    continue;
                }
                $magnitude += abs((float) $rcrd->Amt);
                if ($currency === '') {
                    $currency = (string) $rcrd->Amt['Ccy'];
                }
            }
            return [$magnitude, $currency];
        }

        return null;
    }

    /**
     * Read an <Amt> + <CdtDbtInd> pair into a signed float: CRDT stays positive,
     * DBIT is negated. Same convention as StatementSummary so the two helpers
     * agree on sign when their outputs are later compared.
     */
    private static function signedAmount(\SimpleXMLElement $ntry): float
    {
        $amount = (float) $ntry->Amt;
        return (string) $ntry->CdtDbtInd === 'DBIT' ? -$amount : $amount;
    }
}
