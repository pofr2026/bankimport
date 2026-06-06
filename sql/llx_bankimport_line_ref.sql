-- ============================================================================
-- Per-line matching keys extracted from the CAMT.053 entry at import time.
--
-- One row per imported llx_bank line (1:1, keyed on fk_bank). Dolibarr's import
-- flattens a CAMT entry into a single llx_bank row and loses the structured
-- reference and counterparty IBAN; this side-table carries them forward so the
-- categorization engine (a separate module) can match deterministically without
-- any core schema change -- "integration via data" (spec section 3).
--
-- fk_bank is the PRIMARY KEY: it is the natural 1:1 key to llx_bank.rowid, so a
-- surrogate rowid would be redundant, and making it the PK collapses the
-- idempotency guard (spec section 5/7 UNIQUE(fk_bank)) into the key itself --
-- a re-import is a no-op via INSERT ... ON DUPLICATE KEY. The type mirrors
-- llx_bank.rowid (integer, no AUTO_INCREMENT: the caller always supplies the id,
-- because the bank line already exists at import time). The table is read by SQL
-- only, never through a generic Dolibarr DAO in v0.1, so it needs no rowid.
--
-- All extracted columns are nullable: a given entry carries only whichever keys
-- apply (a bank fee line has none; a QR-bill payment has a structured_ref; an
-- own sales payment has an invoice_ref_token). Currency is intentionally absent
-- (recoverable from the account, spec section 1.2).
--
-- The llx_ prefix is rewritten to the instance prefix by Dolibarr's table loader
-- when the module is (re)activated.
-- ============================================================================

CREATE TABLE llx_bankimport_line_ref(
	fk_bank					integer NOT NULL,				-- llx_bank.rowid this row describes (1:1)
	structured_ref			varchar(64),					-- creditor reference value (QRR / SCOR), raw
	structured_ref_type		varchar(8),						-- reference type as sent (e.g. QRR, SCOR), unvalidated
	invoice_ref_token		varchar(64),					-- Swico S1 /10/ field = our invoice ref, for sales QR-bills
	counterparty_iban_hmac	char(64),						-- HMAC-SHA256 of the counterparty IBAN (never raw, section 9/11)
	date_import				datetime NOT NULL,				-- when this row was last written
	PRIMARY KEY (fk_bank)
) ENGINE=InnoDB;
