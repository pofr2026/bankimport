# Changelog for bankimport module


## 0.0.13

- Add post-import statement verification for XML (CAMT.053): the bank's own `<Bal>` / `<TxsSummry>` blocks are compared against what actually landed in `llx_bank`, and the import screen shows a per-check result table (count, credit/debit sums, net, per-entry, unaddressable). Catches dropped entries, wrong signs and false-skips that unit tests cannot. Implemented as a pure `BankImport\StatementSummary` helper (parse / aggregate / verify) with 24 unit tests.
- Store the CAMT.053 `<Stmt><Id>` as `num_releve` and the `AcctSvcrRef` as `num_chq` on each imported line, enabling the verification scoping above and Dolibarr's native bank reconciliation views.
- Fix duplicate detection that ignored the bank account: a Revolut FX swap between the user's own accounts emits the same `AcctSvcrRef` on both legs (debit in one currency, credit in the other), so the second leg was silently dropped. Duplicate detection is now scoped per account (affects both XML and CSV imports).
- Fix the counterparty IBAN being passed into `addline()`'s accountancy-code argument (a pre-existing slot mismatch) on both the XML and CSV paths; the IBAN is now preserved in the line's private note as `CounterpartyIBAN=`.
- Fix `BankImportHelper::getEnv()` returning `getenv()`'s `false` instead of the supplied default when a variable is unset (`??` only falls through on null). On a clean install without a `.env` or CI-provided `VERSION`, the module now correctly falls back to the bundled default version.
- **Note:** verification compares against rows scoped by `num_releve`, which earlier versions did not write. Re-importing a statement whose lines were imported before v0.0.13 (no `num_releve`) may report them as missing. Fresh imports from v0.0.13 onward verify correctly.


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