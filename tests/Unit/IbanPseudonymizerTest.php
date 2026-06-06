<?php

namespace BankImport\Tests\Unit;

use BankImport\IbanPseudonymizer;
use PHPUnit\Framework\TestCase;

/**
 * RED tests driving the GREEN implementation of IbanPseudonymizer::hash().
 *
 * The keystone stores counterparty IBANs pseudonymised, never raw (spec §9/§11): IBANs are
 * enumerable/low-entropy, so a plain hash is brute-forceable — we use HMAC-SHA256 with a pepper held
 * OUTSIDE the database (conf.php). hash() is pure: the pepper is injected as an argument, so the class
 * has no Dolibarr/config coupling and is unit-testable with a fixed pepper against a known vector.
 *
 * The expected vectors below are produced by PHP's own hash_hmac() (an independent oracle, i.e. the
 * HMAC-SHA256 standard), not by the class under test.
 */
class IbanPseudonymizerTest extends TestCase
{
    /** HMAC-SHA256('CH9300762011623852957', 'pepper-123') — the canonical (normalised) IBAN form. */
    private const VECTOR_PEPPER_123 = '8cc557a7ae0f9c0678d61c3a26a2eb8824181ce01e9feee8c07f94ec48fabd2e';

    /** HMAC-SHA256('CH9300762011623852957', 'other-pepper') — proves the pepper actually participates. */
    private const VECTOR_OTHER_PEPPER = '07abae46f6c3e4eb01961d14667c8857fdd533e39ef2e9456445345906ce5fad';

    /**
     * Happy path: a canonical IBAN hashes to the known HMAC-SHA256 vector (64 lowercase hex chars).
     */
    public function testHashMatchesKnownHmacVector(): void
    {
        $hash = IbanPseudonymizer::hash('CH9300762011623852957', 'pepper-123');

        $this->assertSame(self::VECTOR_PEPPER_123, $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    /**
     * Normalisation: spacing and case must not change the hash, so the same account always maps to one
     * value (required for exact-match L1 lookups and the own-transfer filter). The grouped, lowercase
     * form must yield the same vector as the canonical form.
     */
    public function testNormalisesSpacingAndCaseBeforeHashing(): void
    {
        $hash = IbanPseudonymizer::hash('ch93 0076 2011 6238 5295 7', 'pepper-123');

        $this->assertSame(self::VECTOR_PEPPER_123, $hash);
    }

    /**
     * The pepper participates: the same IBAN under a different pepper yields a different hash (so a
     * leaked database without the pepper cannot be correlated back to IBANs).
     */
    public function testDifferentPepperYieldsDifferentHash(): void
    {
        $hash = IbanPseudonymizer::hash('CH9300762011623852957', 'other-pepper');

        $this->assertSame(self::VECTOR_OTHER_PEPPER, $hash);
        $this->assertNotSame(self::VECTOR_PEPPER_123, $hash);
    }

    /**
     * Defensive contract: an IBAN that is empty after canonicalisation must throw, not return the HMAC
     * of an empty string. Otherwise every "no IBAN" case would collapse to one identical hash and
     * poison the L1 / own-transfer buckets. The wiring already skips RemittanceRef's null IBANs, so an
     * empty value reaching here is a contract violation and should fail loud.
     */
    public function testRejectsEmptyIbanAfterCanonicalisation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        IbanPseudonymizer::hash('   ', 'pepper-123');
    }

    /**
     * Canonicalisation must strip ALL non-alphanumeric separators (dots, dashes, and a non-breaking
     * space U+00A0), not just ASCII spaces — otherwise the same account formatted oddly (e.g. by the
     * §8 history bootstrap regex) would hash differently. The messy form must match the canonical vector.
     */
    public function testCanonicalisationStripsAllNonAlphanumericSeparators(): void
    {
        $messy = "ch93-0076.2011\u{00A0}6238 5295 7";

        $this->assertSame(self::VECTOR_PEPPER_123, IbanPseudonymizer::hash($messy, 'pepper-123'));
    }
}
