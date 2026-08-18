# RETRO — US-36: Teams Schema & Per-Team Mode Selector

**Story**: US-36 — Teams Schema & Per-Team Mode Selector
**Branch**: `feature/us-36-teams-schema`
**Merge commit**: `d9b0d34`
**Review cycles**: 1
**Date**: 2026-08-18

---

## What was built

| File | Change |
|---|---|
| `tests/schema-sqlite.sql` | 4 cols added to `CREATE TABLE teams` + 2 cols to `CREATE TABLE users` |
| `db/schema.sql` | 6 ALTER TABLE statements (4 teams + 2 users) |
| `db/schema-postgresql.sql` | 6 ALTER TABLE IF NOT EXISTS statements |
| `src/TeamRepository.php` | `updateTeam()` — 3 new params; enum + HTTPS URL validation; return `array\|false`; `getTeamById() ?? false` |
| `public/teams/edit.php` | POST: extract channel/webhook/channelName; check `=== false`; GET: radio fieldset + teams-fields div (PHP initial visibility + IIFE JS toggle) |
| `tests/TeamsSchemaTest.php` (new) | 3 tests — default email, store webhook, reject 'slack' |

**Test result**: 112 tests, 222 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle**

---

## Notes

1. **`updateTeam()` return type `void` → `array|false`**: All existing callers ignored the return value — safe change. Used `getTeamById($pdo, $teamId) ?? false` to satisfy PHPStan `array|false` return type (getTeamById returns `?array`).
2. **SQLite schema edit order**: New columns inserted BEFORE `FOREIGN KEY` lines to maintain SQLite syntax validity. Full suite ran immediately after — 109/109 pass.
3. **`edit.php` PHP initial visibility**: `style="display:<?= $teamsFieldsVisible ? '' : 'none' ?>"` rendered server-side — correct initial state without JS flash and functional when JS is disabled.
4. **Existing callers unaffected**: `FrequencyTest.php` calls `updateTeam()` with positional args up to `$frequencyDay` only — new params have defaults, no changes needed.
