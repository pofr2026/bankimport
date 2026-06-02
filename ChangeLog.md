# Changelog for bankimport module


## 0.0.15

- Fix the module configuration page being unreachable: `config_page_url` was empty, so Dolibarr showed no configure (gear) icon for the module on the Modules list and `admin/setup.php` could not be opened. This left the `BANKIMPORT_SPLIT_FEES` toggle added in 0.0.14 inaccessible from the UI (it could only be changed directly in the database). The setup page is now linked again so the toggle is reachable.


## 0.0.14

- Add optional splitting of embedded fees on XML (CAMT.053) import: an entry whose `<Chrgs>` records a charge in the account's own currency is posted as two bank lines — the principal and the fee — that sum to the original amount, so bank fees (e.g. Revolut FX charges) appear and can be booked on their own line. Implemented as a pure `BankImport\FeeSplitter` helper with 7 unit tests.
- Never split a cross-currency fee: when an FX transfer between the user's own accounts charges the fee in the target currency, the source-currency leg advertises that fee in a different currency than its own amount. Splitting it there would double-count the fee (the other leg already carries it) and invent a charge that never hit this account, so such entries stay a single line. Verification cannot catch this (the two sub-lines still net to the original amount), so the guard lives in `FeeSplitter` and is covered by tests.
- Keep verification green after a split: both lines carry the same `num_chq` (`AcctSvcrRef`), so `StatementSummary::aggregate()` folds them back into one logical entry equal to the bank's reported amount; the lines are told apart by distinct import keys (the fee line's reference is salted with `:fee`) so duplicate detection keeps working and re-imports stay idempotent even if the setting is toggled.
- Add a `BANKIMPORT_SPLIT_FEES` setting (disabled by default — opt-in) with an on/off toggle on the module setup page; enabling it turns on the fee splitting described above. Existing imports are untouched, and splitting only applies to entries that carry an `AcctSvcrRef` (required so the two lines share a stable reference for verification and duplicate detection).
- Add `FeeLineLabel` and the new setup strings to the English and German language files.


## 0.0.13

- Add post-import statement verification for XML (CAMT.053): the bank's own `<Bal>` / `<TxsSummry>` blocks are compared against what actually landed in `llx_bank`, and the import screen shows a per-check result table (count, credit/debit sums, net, per-entry, unaddressable). Catches dropped entries, wrong signs and false-skips that unit tests cannot. Implemented as a pure `BankImport\StatementSummary` helper (parse / aggregate / verify) with 30 unit tests.
- Store the CAMT.053 `<Stmt><Id>` as `num_releve` and the `AcctSvcrRef` as `num_chq` on each imported line, enabling the verification scoping above and Dolibarr's native bank reconciliation views.
- Harden the verification read-back so an empty actual-state can no longer masquerade as a storm of "entry missing" mismatches. A `StatementSummary::verificationPrecondition()` gate now classifies three runtime conditions before per-check comparison: a failed read-back query surfaces a single `error` row (the DB error was previously swallowed and silently turned into mismatches), a statement that cannot be scoped because it lacks `<Stmt><Id>` (empty `num_releve`, which would otherwise match unrelated rows) is reported as `skipped`, and a statement matching zero stored rows is reported as `skipped` instead of one mismatch per expected entry. Per-entry false-skip detection is unchanged whenever at least one row is present. Adds 6 unit tests and a new `error` status to the result table.
- Fix duplicate detection that ignored the bank account: a Revolut FX swap between the user's own accounts emits the same `AcctSvcrRef` on both legs (debit in one currency, credit in the other), so the second leg was silently dropped. Duplicate detection is now scoped per account (affects both XML and CSV imports).
- Fix the counterparty IBAN being passed into `addline()`'s accountancy-code argument (a pre-existing slot mismatch) on both the XML and CSV paths; the IBAN is now preserved in the line's private note as `CounterpartyIBAN=`.
- Fix `BankImportHelper::getEnv()` returning `getenv()`'s `false` instead of the supplied default when a variable is unset (`??` only falls through on null). On a clean install without a `.env` or CI-provided `VERSION`, the module now correctly falls back to the bundled default version.
- **Note:** verification compares against rows scoped by `num_releve`, which earlier versions did not write. Re-importing a statement whose lines were imported before v0.0.13 (no `num_releve`) finds zero rows under the new scope and is now reported as `skipped` (not as missing entries). Fresh imports from v0.0.13 onward verify correctly.


## 0.0.12

- Fix PHP 8.2 warnings ("Attempt to read property X on null") when CAMT.053 entries omit optional branches (`RltdPties`, `RltdAgts`); introduced a safe `xmlText()` accessor for nested SimpleXML paths.
- Fix CSV duplicate detection for recurring identical transactions: the booking date is now part of the `import_key` hash, so two identical fees on different days no longer collide. **Note:** existing CSV `import_key` values from earlier versions used a date-less algorithm; re-importing an already-imported CSV file may produce duplicates one time after the upgrade.
- Extract `BankImport\ImportKey` as a pure helper class for the import-key derivation. Both the CSV and XML paths now route through `ImportKey::build()`; the legacy private `generateImportKey()` method has been removed.
- Fix a latent dedup-breakage for any row routed through the `transaction_id` branch: the legacy `generateImportKey()` returned the raw value (typically 32+ chars), which exceeded the `import_key varchar(14)` column. MariaDB silently truncated on INSERT, so the key held in memory never matched the stored one and dedup was broken for affected rows. `ImportKey::build()` now SHA-1 hashes the value to fit. The XML path used to mitigate this inline (that inline workaround is now gone); the CSV branch was dead until this consolidation but is now safe for future CSV formats that carry a transaction id.
- Add PHPUnit 10.5 as a dev dependency plus a unit test suite (`tests/Unit/ImportKeyTest`) covering the dedup logic; run with `composer test` (or `vendor/bin/phpunit`).


## 0.0.11

- Added support for XML import in CAMT.053 format (e.g. Revolut Business statements).
- Automatic format detection (CSV vs. XML) based on file content (BOM-tolerant sniffing of leading bytes).
- Duplicate detection for XML entries via `AcctSvcrRef` (SHA-1 hashed to 14 chars to fit the `import_key` column); falls back to the CSV-style composite hash if `AcctSvcrRef` is absent.
- Correct amount sign handling for XML (`CdtDbtInd` DBIT → negative, CRDT → positive).
- Direction-aware counterparty resolution: debtor (`Dbtr`/`DbtrAcct`/`DbtrAgt`) for incoming, creditor (`Cdtr`/`CdtrAcct`/`CdtrAgt`) for outgoing transactions, with `InitgPty` as a name fallback.
- File upload validation extended to accept `.xml` and XML MIME types alongside CSV.
- UI: file input `accept` attribute and help text updated; English and German language files reflect the new format support.


## 0.0.10

Initial public version