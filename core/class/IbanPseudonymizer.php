<?php

namespace BankImport;

/**
 * Pure helper that pseudonymises a counterparty IBAN for storage in the keystone side-table.
 *
 * IBANs are PII and, being structured and low-entropy, are enumerable — a plain SHA-256 of an IBAN is
 * brute-forceable. So the corpus stores HMAC-SHA256(IBAN, pepper) with the pepper held OUTSIDE the
 * database (in conf.php), per spec §9/§11. The HMAC preserves exact-match equality, which is all the
 * pipeline needs: L1 IBAN lookups and the own-transfer filter compare hashes, never plaintext.
 *
 * The pepper is injected as an argument, never read here, so this class stays pure and side-effect-free
 * (the wiring layer fetches the pepper from conf.php and passes it in). Erasure/rotation is a DPO matter
 * — pseudonymisation alone does not satisfy a deletion request (spec §9).
 */
class IbanPseudonymizer
{
    /**
     * Return HMAC-SHA256 (64 lowercase hex chars) of the canonicalised IBAN under the given pepper.
     *
     * @param string $iban   The counterparty IBAN, in any spacing/case (canonicalised before hashing).
     * @param string $pepper The secret pepper, supplied by the caller from outside the database.
     */
    public static function hash(string $iban, string $pepper): string
    {
        return hash_hmac('sha256', self::canonicalize($iban), $pepper);
    }

    /**
     * Canonicalise an IBAN so that spacing and case never change the hash: strip all whitespace and
     * uppercase. This guarantees one account maps to exactly one hash regardless of how the source
     * formatted it (e.g. "ch93 0076 …" and "CH9300762011…" collapse to the same value).
     */
    private static function canonicalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban));
    }
}
