<?php
/**
 * PHPUnit bootstrap for the bankimport module.
 *
 * Scope: we only unit-test PURE helper classes (no Dolibarr dependencies).
 *
 * Classes that require Dolibarr (BankImport itself, which extends CommonObject and
 * touches DoliDB / Account / dol_mktime) are tested via the live container instead
 * — see the manual E2E runner approach used in dev. This bootstrap therefore does
 * NOT stub Dolibarr globals; if a unit test needs them, the class under test is
 * not pure enough and should be refactored first.
 *
 * Class autoloading is handled by composer's PSR-4 mapping in composer.json
 * (BankImport\ -> core/class/, BankImport\Tests\ -> tests/).
 */

require_once __DIR__ . '/../vendor/autoload.php';
