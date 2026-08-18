# RETRO — US-40: Fallback, Logging & Admin Visibility

**Story**: US-40 — Fallback, Logging & Admin Visibility
**Branch**: `feature/us-40-teams-fallback`
**Merge commit**: `2e4ed44`
**Review cycles**: 1
**Date**: 2026-08-18

---

## What was built

| File | Change |
|---|---|
| `db/schema.sql` | `ALTER TABLE teams ADD COLUMN teams_last_error/teams_last_error_at` |
| `db/schema-postgresql.sql` | Same with `ADD COLUMN IF NOT EXISTS` + `TIMESTAMP` |
| `tests/schema-sqlite.sql` | `teams_last_error TEXT NULL` + `teams_last_error_at TEXT NULL` in `CREATE TABLE teams` |
| `src/TeamRepository.php` | `recordTeamsError()`, `clearTeamsError()`, `getTeamsAdminOverview()` |
| `src/SummaryEmailer.php` | `require_once TeamRepository`; `recordTeamsError()` on webhook failure; `clearTeamsError()` + return on success |
| `src/StandupEmailer.php` | Same for bot DM failure path |
| `public/admin/teams.php` (new) | Teams integration admin overview: mode badges, webhook URL, last error in red |
| `templates/layout.php` | "Teams" nav link in admin guard |
| `tests/TeamsFallbackTest.php` (new) | 3 tests |

**Test result**: 127 tests, 255 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle** (Gate D approved first review)

---

## Notes

1. **Suite after schema-sqlite.sql change**: run immediately — confirmed 124/124 pass before any PHP changes.
2. **`(int)` cast**: `$teamId` in `SummaryEmailer.php` is `int|string` from array; cast applied to both `recordTeamsError()` and `clearTeamsError()` calls.
3. **`public/admin/index.php`**: remains a pure redirect to `users.php`; comment added referencing `teams.php`. Nav link added in `templates/layout.php` for admin users.
4. **`TeamRepository.php` require_once**: not previously included in `SummaryEmailer.php` or `StandupEmailer.php` — added to both.
