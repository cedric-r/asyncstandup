# RETRO — US-30: Configurable Standup Frequency

**Story**: US-30 — Configurable Standup Frequency (daily / weekdays / weekly)
**Branch**: `feature/us-30-configurable-frequency`
**Merge commit**: `a57c412`
**Review cycles**: 1
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `tests/schema-sqlite.sql` | Added `frequency TEXT NOT NULL DEFAULT 'daily'` and `frequency_day INTEGER NULL` in `CREATE TABLE teams` |
| `db/schema.sql` | Appended 2 ALTER TABLE statements for `frequency` and `frequency_day` |
| `db/schema-postgresql.sql` | Added columns inline in CREATE TABLE + 2 ALTER TABLE IF NOT EXISTS at bottom |
| `src/StandupEmailer.php` | Rewrote `isTeamDue()` — frequency guard (weekly checks day, daily/weekdays skip weekends) before time proximity check |
| `src/SummaryEmailer.php` | Extended `isSummaryDue()` — same frequency guard at top of function body |
| `src/TeamRepository.php` | Extended `updateTeam()` — 2 new trailing params (`string $frequency = 'daily'`, `?int $frequencyDay = null`), SQL updated |
| `cron/send_standups.php` | Removed global weekend skip block (8 lines) — logic now in `isTeamDue()` |
| `public/teams/edit.php` | POST validation for frequency/frequency_day; `<select name="frequency">` (3 options); `#frequency-day-picker` (Mon–Sun) with inline JS toggle; `updateTeam()` call updated |
| `tests/FrequencyTest.php` | 3 tests: daily skip Saturday, weekly fire Monday, weekly skip Tuesday |

**Test result**: 89 tests, 172 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle** — Gate D approved on first submission.

---

## Notes

1. **`$nowLocal` in cron** — The global weekend skip block also computed `$nowLocal`. Checked before removal: `$nowLocal` still referenced at line 75 (`sendSummaryEmail`). Kept the assignment (`$nowLocal = $nowUtc->setTimezone($teamTz)`); removed only the day-of-week guard block.
2. **Existing `isTeamDue` tests** — Existing test fixtures have no `frequency` key; `?? 'daily'` default handles this transparently. All fixture datetimes fall on weekdays — no regression.
3. **`updateTeam()` backward compatibility** — New params have defaults (`'daily'`, `null`); existing call site (only one in `edit.php`) updated in T-7. Grep confirmed no other call sites.
4. **Multi-line PostgreSQL schema edit** — Same CRLF pattern as US-26/29; used PHP `str_replace` on file contents rather than `sed -i` (which cannot do multi-line on Windows Git Bash).
