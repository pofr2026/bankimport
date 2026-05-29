<?php

namespace BankImport\Tests\Unit;

use BankImport\ImportKey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure ImportKey helper.
 *
 * Test 1 (test_csv_recurring_identical_rows_on_different_days_must_produce_different_keys)
 * is the RED test driving the date-aware-hash fix: it asserts a property the current
 * implementation does not yet satisfy. The other tests are regression baselines that
 * must stay green both before and after the fix.
 */
class ImportKeyTest extends TestCase
{
    /**
     * RED — drives the GREEN fix.
     *
     * The current CSV path hashes iban|owner|amount|label|ref WITHOUT the booking date.
     * A recurring monthly fee with the same counterparty/amount/label and an empty
     * mandate reference therefore collapses to a single import_key, so every month
     * after the first gets falsely skipped as a duplicate.
     *
     * After GREEN, build() accepts the booking date as the 7th argument and folds it
     * into the hash, so the two months produce different keys.
     */
    public function test_csv_recurring_identical_rows_on_different_days_must_produce_different_keys(): void
    {
        $jan = ImportKey::build(null, 'iban', 'owner', -5.00, 'Monthly fee', '', strtotime('2026-01-15'));
        $feb = ImportKey::build(null, 'iban', 'owner', -5.00, 'Monthly fee', '', strtotime('2026-02-15'));

        $this->assertNotSame(
            $jan,
            $feb,
            'CSV import_key must include the booking date so identical recurring transactions on different days do not collide.'
        );
    }

    /**
     * Regression baseline for the XML (CAMT.053) path: when AcctSvcrRef is present,
     * it alone determines the key — counterparty, amount, label and date are ignored.
     * Guarantees the refactor and the GREEN fix did not change XML dedup behavior.
     */
    public function test_xml_transaction_id_alone_determines_key(): void
    {
        $a = ImportKey::build('tx-abc-123', 'iban1', 'owner1', 100, 'L1', 'R1', strtotime('2026-01-01'));
        $b = ImportKey::build('tx-abc-123', 'iban2', 'owner2', 200, 'L2', 'R2', strtotime('2026-12-31'));

        $this->assertSame(
            $a,
            $b,
            'When transaction_id is provided it alone determines the key; other fields must be ignored.'
        );
    }

    /**
     * Regression: the llx_bank.import_key column is varchar(14) (mirrored in
     * ImportKey::KEY_LENGTH), so every returned key must fit. Covers both the
     * transaction_id branch and the composite branch.
     */
    public function test_key_length_matches_column_width(): void
    {
        $this->assertSame(ImportKey::KEY_LENGTH, strlen(ImportKey::build(null, 'i', 'o', 100, 'l', 'r', 0)));
        $this->assertSame(ImportKey::KEY_LENGTH, strlen(ImportKey::build('tx-12345', '', '', 0, '', '', 0)));
    }

    /**
     * Edge case: an empty-string transaction_id must be treated like null
     * (fall back to the composite hash, not produce sha1('') as the key).
     */
    public function test_empty_transaction_id_falls_back_to_composite_hash(): void
    {
        $emptyTx = ImportKey::build('',   'iban', 'owner', 100, 'label', 'ref', 0);
        $nullTx  = ImportKey::build(null, 'iban', 'owner', 100, 'label', 'ref', 0);

        $this->assertSame(
            $nullTx,
            $emptyTx,
            'Empty-string transaction_id should be treated like null (fallback to composite hash).'
        );
    }

    /**
     * Regression: identical inputs produce identical keys. Guarantees the function
     * is deterministic — without this, dedup itself would be unreliable.
     */
    public function test_identical_inputs_produce_identical_keys(): void
    {
        $a = ImportKey::build(null, 'iban', 'owner', -5.00, 'Fee', '', strtotime('2026-01-15'));
        $b = ImportKey::build(null, 'iban', 'owner', -5.00, 'Fee', '', strtotime('2026-01-15'));

        $this->assertSame($a, $b);
    }
}
