# XAMPP Pulse — Changelog

One line per shipped change (newest first). Detailed progress lives in `task.md`.

## 2026-07-21
- **DB tab — full "as‑is" copy, source → local.** mysqldump the source read‑only → back up local
  → drop/recreate → import, so local becomes an exact mirror (drops local‑only tables; production
  is never written; credentials never stored). Offered in the compare result for local targets.
  Fixed the stale "Production is never written (Phase 1)" banner. Auto-handles a newer source than
  local: on an "Unknown collation/charset" import error, rewrites the dump's `_uca1400_`/`_0900_`
  collations → `_unicode_ci` and `utf8mb3` → `utf8` (DDL only; row data untouched) and retries.
