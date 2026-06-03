-- ============================================================================
-- Per-statement opening/closing booking balances declared by the bank.
--
-- One row per imported CAMT.053 <Stmt> block (per account, per currency). The
-- bank-declared OPBD/CLBD are persisted here so the cross-statement continuity
-- check (BankImport\StatementContinuity) can verify the ledger invariant
-- CLBD_N == OPBD_(N+1) across separately imported statement files. A missing
-- statement file shows up as a break in that chain — something the running
-- totals in llx_bank cannot reveal, because the absent rows simply are not there
-- and the stored total stays internally consistent over whatever WAS imported.
--
-- The table prefix llx_ is rewritten to the instance prefix by Dolibarr's
-- table loader when the module is (re)activated.
-- ============================================================================

CREATE TABLE llx_bankimport_statement(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_account			integer NOT NULL,				-- llx_bank_account this statement belongs to
	electronic_seq_nb	varchar(32) NOT NULL,			-- <Stmt><ElctrncSeqNb>, the chain order key
	num_releve			varchar(64),					-- <Stmt><Id>, mirrors llx_bank.num_releve for cross-reference
	currency			varchar(3) NOT NULL,			-- each currency is an independent chain
	opbd				double(24,8) NOT NULL,			-- signed opening booking balance (OPBD)
	clbd				double(24,8) NOT NULL,			-- signed closing booking balance (CLBD)
	date_import			datetime NOT NULL				-- when this statement's balances were last persisted
) ENGINE=InnoDB;
