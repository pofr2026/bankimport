# CLAUDE.md

Project instructions for the bankimport Dolibarr module. These are loaded every
session and override default behavior.

## Core engineering principles (always apply, like TDD)

These are non-negotiable and apply to every change, whether or not explicitly asked:

1. **TDD (strict red-green-refactor).** Write the failing test first, confirm it
   with the user, then write the production code.
2. **DRY — no duplicated logic.** If the same logic appears 2+ times, extract it
   into one shared helper (for example a class in `core/class/`, as with
   FeeSplitter / EntryPlan) instead of copy-pasting it locally.
3. **No hardcoding.** Magic numbers and magic strings go into named constants or
   configuration, never inlined in several places.
4. **Reuse before adding.** Before writing new logic, check whether existing data,
   an existing helper, or a native Dolibarr API already provides it (e.g. reuse
   `num_releve` rather than custom scoping).

If a change would violate one of these, stop and flag it instead of silently
proceeding.
