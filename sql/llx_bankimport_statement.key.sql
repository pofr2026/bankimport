-- ============================================================================
-- Keys and indexes for llx_bankimport_statement.
--
-- The unique key (fk_account, currency, electronic_seq_nb) is the natural
-- identity of a statement within the continuity chain, and makes persistence
-- idempotent: re-importing the same statement file refreshes the existing row
-- (delete-then-insert on this key) instead of accumulating duplicates.
-- ============================================================================

ALTER TABLE llx_bankimport_statement ADD UNIQUE INDEX uk_bankimport_statement(fk_account, currency, electronic_seq_nb);
ALTER TABLE llx_bankimport_statement ADD INDEX idx_bankimport_statement_fk_account(fk_account);
