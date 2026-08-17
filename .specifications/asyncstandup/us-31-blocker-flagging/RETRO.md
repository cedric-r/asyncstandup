# RETRO — US-31: Blocker Question Flagging

**Story**: US-31 — Blocker Question Flagging
**Branch**: `feature/us-31-32-question-metadata` (shared with US-32)
**Merge commit**: `a16483e`
**Review cycles**: 1
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `tests/schema-sqlite.sql` | Added `is_blocker INTEGER NOT NULL DEFAULT 0` to `CREATE TABLE team_questions` |
| `db/schema.sql` | Appended `ALTER TABLE team_questions ADD COLUMN is_blocker TINYINT(1) NOT NULL DEFAULT 0` |
| `db/schema-postgresql.sql` | Added `is_blocker BOOLEAN NOT NULL DEFAULT FALSE` inline + ALTER TABLE IF NOT EXISTS |
| `src/TeamRepository.php` | Added `setBlockerQuestion()`, `clearBlockerQuestion()`; `getQuestions()` changed from `SELECT *` to explicit column list including `is_blocker`, `is_mood` |
| `src/SummaryEmailer.php` | `assembleSummaryData()` questions query now includes `is_blocker` |
| `templates/email/standup_summary.php` | Non-empty blocker answers prefixed with `⚠️ BLOCKER — ` |
| `public/teams/responses.php` | `<dt>` gets `text-red-700` + `[blocker]` badge; `<dd>` gets `font-medium text-red-800` when blocker + non-empty |
| `public/teams/questions.php` | `set_blocker` / `clear_blocker` POST actions + per-question toggle buttons |
| `tests/BlockerFlaggingTest.php` | 3 tests: clear previous blocker, flag surfaces in assembled data, clear removes all flags |

**Test result**: 95 tests, 184 assertions — all pass (US-31 + US-32 together)
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle** — Gate D approved on first submission.

---

## Notes

1. **`getQuestions()` `SELECT *` → explicit** — Changed to `SELECT id, question, position, is_blocker, is_mood` so both flags are available everywhere the function is called (questions.php, responses.php, dashboard.php, TeamRepository internals). No callers depend on `SELECT *` ordering.
2. **`assembleSummaryData()` separate query** — `assembleSummaryData()` in `SummaryEmailer.php` has its own `team_questions` query (separate from `getQuestions()`); updated independently to include `is_blocker`.
3. **`bootstrap.php` extended** — `TeamRepository.php` and `DashboardRepository.php` added to bootstrap `require_once` chain since US-31/32 tests need `setBlockerQuestion()` etc. directly.
