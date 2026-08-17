# IMPL-PLAN — US-30: Configurable Standup Frequency

**Status**: APPROVED
**Branch**: `feature/us-30-configurable-frequency`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-30 — Configurable Standup Frequency

---

## Scope

All changes are within the bounds of STORY.md AC-1 through AC-6 and TASKS.md T-1 through T-10.

No new Composer dependencies. 9 files modified/created.

---

## Files to Create or Modify

| File | Type | Change |
|---|---|---|
| `tests/schema-sqlite.sql` | Modify | Add `frequency TEXT NOT NULL DEFAULT 'daily'` and `frequency_day INTEGER NULL` inside `CREATE TABLE teams` |
| `db/schema.sql` | Modify | Append 2 ALTER TABLE statements |
| `db/schema-postgresql.sql` | Modify | Add columns inline + 2 ALTER TABLE IF NOT EXISTS at bottom |
| `src/StandupEmailer.php` | Modify | Rewrite `isTeamDue()` — add frequency guard before time proximity check |
| `src/SummaryEmailer.php` | Modify | Extend `isSummaryDue()` — add frequency guard at top of function body |
| `src/TeamRepository.php` | Modify | Extend `updateTeam()` signature + SQL (2 new params, backward-compatible defaults) |
| `cron/send_standups.php` | Modify | Remove global weekend skip block (lines 38–44) — logic now inside `isTeamDue()` |
| `public/teams/edit.php` | Modify | POST handler: read `$frequency` + `$frequencyDay`; pass to `updateTeam()`; form: frequency `<select>` + day picker with inline JS toggle |
| `tests/FrequencyTest.php` | Create | 3 PHPUnit tests |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-30-configurable-frequency` created from `main`.

---

### T-2 — Schema (AC-1)

**`tests/schema-sqlite.sql`** — inside `CREATE TABLE teams`, after `summary_to_all_developers INTEGER NOT NULL DEFAULT 0,`:
```sql
frequency      TEXT    NOT NULL DEFAULT 'daily',
frequency_day  INTEGER NULL,
```

**`db/schema.sql`** — append after the last migration block:
```sql
-- US-30: configurable frequency
ALTER TABLE teams ADD COLUMN frequency     VARCHAR(10) NOT NULL DEFAULT 'daily';
ALTER TABLE teams ADD COLUMN frequency_day TINYINT(1)  NULL;
```

**`db/schema-postgresql.sql`** — add both columns inline in `CREATE TABLE teams`; append at bottom:
```sql
-- US-30: configurable frequency
ALTER TABLE teams ADD COLUMN IF NOT EXISTS frequency     VARCHAR(10) NOT NULL DEFAULT 'daily';
ALTER TABLE teams ADD COLUMN IF NOT EXISTS frequency_day SMALLINT NULL;
```

---

### T-3 — Rewrite `isTeamDue()` in `src/StandupEmailer.php` (AC-2)

Current function has no frequency awareness. Replace with the full implementation from STORY.md AC-2:
- Compute `$dayOfWeek = (int) $nowLocal->format('N')` (1=Mon…7=Sun)
- Read `$frequency = $team['frequency'] ?? 'daily'`
- For `'weekly'`: return false if `$dayOfWeek !== $team['frequency_day']`
- For `'daily'` or `'weekdays'`: return false if Saturday (6) or Sunday (7)
- Then existing time proximity check (unchanged)

Existing tests `testExactMatchReturnsTrue` etc. use `frequency`-less arrays — they will still pass because `?? 'daily'` defaults correctly and the fixture times avoid weekends.

---

### T-4 — Extend `isSummaryDue()` in `src/SummaryEmailer.php` (AC-3)

Insert the frequency guard at the top of the function body (before `$scheduledLocal`), same logic as `isTeamDue()`:
```php
$dayOfWeek = (int) $nowLocal->format('N');
$frequency = $team['frequency'] ?? 'daily';
if ($frequency === 'weekly') {
    if ($dayOfWeek !== (int) ($team['frequency_day'] ?? 1)) { return false; }
} elseif ($dayOfWeek === 6 || $dayOfWeek === 7) {
    return false;
}
```

---

### T-5 — Remove weekend skip from `cron/send_standups.php` (AC-2)

Delete from the `foreach ($teams as $team)` loop:
```php
// Feature 3: skip weekends in the team's local timezone.
$nowLocal  = $nowUtc->setTimezone(new DateTimeZone($team['timezone']));
$dayOfWeek = (int) $nowLocal->format('N');
if ($dayOfWeek === 6 || $dayOfWeek === 7) {
    continue; // Saturday or Sunday — no emails for this team.
}
```

Note: `$nowLocal` is used after this block by `isSummaryDue()` pass. Must check whether `$nowLocal` is still needed elsewhere in the loop; if so, keep the assignment but remove only the guard.

---

### T-6 — Extend `updateTeam()` in `src/TeamRepository.php` (AC-4)

Add `string $frequency = 'daily'` and `?int $frequencyDay = null` as trailing parameters (backward-compatible — existing call site in `edit.php` will be updated in T-7). Update SQL to include both new columns.

---

### T-7 — Update `public/teams/edit.php` (AC-5)

**POST handler** (after `$summaryToAllDevelopers`):
```php
$frequency    = in_array($_POST['frequency'] ?? '', ['daily', 'weekdays', 'weekly'], true)
                ? $_POST['frequency'] : 'daily';
$frequencyDay = ($frequency === 'weekly')
                ? max(1, min(7, (int) ($_POST['frequency_day'] ?? 1)))
                : null;
```
Update `updateTeam()` call to pass `$frequency, $frequencyDay`.

**Form HTML** — add after `summary_to_all_developers` checkbox, before submit button:
- `<select name="frequency">` with 3 options; selected from `$team['frequency'] ?? 'daily'`
- `<div id="frequency-day-picker">` with `<select name="frequency_day">` (options 1–7, Mon–Sun); shown/hidden by inline JS `<script>`
- Initial visibility: `style="display: block/none"` based on current team value

---

### T-8 — Tests (AC-6)

Create `tests/FrequencyTest.php` exactly as specified in TASKS.md T-8:
1. `testDailyTeamSkipsWeekend` — Saturday 2026-08-15 09:00 UTC with `frequency=daily` → false
2. `testWeeklyTeamFiresOnCorrectDay` — Monday 2026-08-17 09:00 UTC, `frequency=weekly, frequency_day=1` → true
3. `testWeeklyTeamSkipsWrongDay` — Tuesday 2026-08-18 09:00 UTC, `frequency=weekly, frequency_day=1` → false

---

### T-9 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥89 tests (86 prior + 3 new), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors.

---

### T-10 — Commit

All files in one commit with message:
`feat(us-30): configurable standup frequency — daily/weekdays/weekly, isTeamDue extended`

---

## Risk Notes

1. **`$nowLocal` in cron** — the weekend skip block also computes `$nowLocal`. After removing the block, verify whether `$nowLocal` is still referenced later in the loop (used by `isSummaryDue()`'s pass). Confirm by grepping before deleting — keep assignment if needed.
2. **Existing `isTeamDue` tests** — fixture teams have no `frequency` key; `?? 'daily'` default handles this. The test datetimes (09:00 UTC, various offsets) fall on weekdays — no regression expected. Will verify by running suite immediately after T-3.
3. **`updateTeam()` call sites** — only one call site in `edit.php`; updated in T-7. No other callers found (grep confirmed only one).
4. **PHPStan `?int $frequencyDay`** — `frequency_day` is `TINYINT(1) NULL` / `SMALLINT NULL` / `INTEGER NULL`; PDO returns it as string or null from fetchAll. The `updateTeam()` parameter accepts `?int` and passes it directly as a PDO bind — PHP casts automatically. PHPStan will see `?int` in the signature; should be clean.
