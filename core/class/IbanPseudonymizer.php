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
    /** HMAC algorithm. IBANs are enumerable, so a keyed hash (not a bare digest) is required (§9). */
    private const HASH_ALGO = 'sha256';

    /**
     * Return HMAC-SHA256 (64 lowercase hex chars) of the canonicalised IBAN under the given pepper.
     *
     * @param string $iban   The counterparty IBAN, in any spacing/case (canonicalised before hashing).
     * @param string $pepper The secret pepper, supplied by the caller from outside the database.
     * @throws \InvalidArgumentException if the IBAN is empty after canonicalisation — hashing "" would
     *         map every missing-IBAN case to one identical value and poison the L1 / transfer buckets.
     *         The caller must skip null/absent IBANs (RemittanceRef returns null when there is none).
     */
    public static function hash(string $iban, string $pepper): string
    {
        $canonical = self::canonicalize($iban);
        if ($canonical === '') {
            throw new \InvalidArgumentException('IbanPseudonymizer::hash() received an empty IBAN.');
        }

        return hash_hmac(self::HASH_ALGO, $canonical, $pepper);
    }

    /**
     * Canonicalise an IBAN so that formatting never changes the hash: strip every non-alphanumeric
     * character (spaces, non-breaking spaces, dots, dashes — any separator) and uppercase. The IBAN
     * alphabet is exactly [A-Z0-9], so this is lossless for a valid IBAN and guarantees one account
     * maps to exactly one hash regardless of how the source formatted it.
     */
    private static function canonicalize(string $iban): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }
}
