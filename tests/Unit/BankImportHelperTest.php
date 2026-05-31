<?php

namespace BankImport\Tests\Unit;

use PHPUnit\Framework\TestCase;

// BankImportHelper is a global (non-namespaced) class living under core/modules/,
// so it is not covered by the composer PSR-4 map (BankImport\ -> core/class/).
// Require it explicitly. It pulls in vendor/autoload for Dotenv, which the
// bootstrap has already loaded — require_once keeps that idempotent.
require_once __DIR__ . '/../../core/modules/BankImportHelper.php';

/**
 * Tests for BankImportHelper::getEnv().
 *
 * The headline test pins down N2: getenv() returns false (not null) when a
 * variable is unset, and the null-coalescing chain only falls through on null,
 * so the documented default was never reached on a clean install with no .env
 * and no CI-provided VERSION — the module would surface false / an empty
 * version instead of the bumped default.
 */
class BankImportHelperTest extends TestCase
{
    /**
     * RED for N2: an absent variable must yield the provided default, not the
     * boolean false that getenv() returns for a missing key. This is what makes
     * the version bump actually effective on a clean install.
     */
    public function test_getenv_returns_default_when_variable_absent(): void
    {
        $absentKey = 'BANKIMPORT_ABSENT_' . uniqid();

        $this->assertSame(
            '0.0.13',
            \BankImportHelper::getEnv($absentKey, '0.0.13'),
            'getEnv must return the default for an unset variable, not getenv()\'s false.'
        );
    }

    /**
     * Guard the happy path: a value present in $_ENV is returned as-is and the
     * default is ignored. Ensures the N2 fix does not regress normal lookups.
     */
    public function test_getenv_returns_value_when_present(): void
    {
        $key = 'BANKIMPORT_PRESENT_' . uniqid();
        $_ENV[$key] = '0.0.99';

        $this->assertSame('0.0.99', \BankImportHelper::getEnv($key, 'fallback'));

        unset($_ENV[$key]);
    }
}
