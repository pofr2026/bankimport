# Changelog for bankimport module


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