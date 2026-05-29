<?php

namespace BankImport;

/**
 * Pure helper for generating the 14-char import_key used to dedupe bank transactions.
 *
 * The column llx_bank.import_key is varchar(14); all keys produced here are SHA-1 hashed
 * and truncated to fit. This class has zero Dolibarr coupling and is the unit-test entry
 * point for dedup logic — see tests/Unit/ImportKeyTest.
 *
 * Strategies:
 *  - transaction_id provided (CAMT.053 AcctSvcrRef): hash(transaction_id) — the bank-
 *    assigned reference is globally unique, this is the strong path.
 *  - Otherwise (typical CSV row): hash of booking_date|iban|owner|amount|label|ref.
 *    The date is included so that identical recurring transactions on different days
 *    (e.g. a fixed monthly fee with empty mandate reference) produce distinct keys
 *    and are not falsely deduplicated.
 */
class ImportKey
{
    /**
     * Width of the produced key in characters. Matches the width of the
     * llx_bank.import_key column in the Dolibarr schema (varchar(14)). If
     * Dolibarr ever widens the column, change this constant AND the DDL
     * migration together.
     */
    public const KEY_LENGTH = 14;

    /**
     * @param string|null $transactionId Bank-provided transaction ID (e.g. CAMT.053 AcctSvcrRef)
     * @param string $iban Counterparty IBAN
     * @param string $owner Counterparty name
     * @param int|float|string $amount Transaction amount, signed. The CALLER is
     *        responsible for normalizing the value to a dot-decimal numeric
     *        string or PHP float (e.g. via Dolibarr's price2num()) BEFORE
     *        calling this method. Raw European-format strings such as "100,50"
     *        will be coerced by (float) to 100.0 and therefore produce a
     *        different key than the same amount in dot-decimal form. This
     *        helper deliberately does NOT call price2num() because doing so
     *        would couple it to Dolibarr; staying pure keeps it unit-testable.
     * @param string $label Payment label / Verwendungszweck
     * @param string $ref Mandate or end-to-end reference
     * @param int $dateo Booking date as a unix timestamp; 0 means "unknown / not provided"
     * @return string Fixed-width import key of self::KEY_LENGTH characters
     */
    public static function build(
        ?string $transactionId,
        string $iban,
        string $owner,
        int|float|string $amount,
        string $label,
        string $ref,
        int $dateo = 0
    ): string {
        if (!empty($transactionId)) {
            return substr(sha1(trim($transactionId)), 0, self::KEY_LENGTH);
        }

        $key = implode('|', [
            $dateo > 0 ? date('Y-m-d', $dateo) : '',
            trim($iban),
            trim($owner),
            // (float) assumes a dot-decimal input; see the $amount PHPDoc above.
            number_format((float) $amount, 2, '.', ''),
            trim($label),
            trim($ref),
        ]);
        return substr(sha1($key), 0, self::KEY_LENGTH);
    }
}
