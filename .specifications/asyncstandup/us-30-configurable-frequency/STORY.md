# US-30: Configurable Standup Frequency

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-30-configurable-frequency`

---

## Story

**As a** team owner  
**I want** to set my team's standup frequency to daily, weekdays-only, or weekly  
**So that** we can skip standups on weekends or reduce to one per week without disabling the team

---

## Acceptance Criteria

### AC-1 — Schema: `frequency` and `frequency_day` columns on `teams`

- `frequency VARCHAR(10) NOT NULL DEFAULT 'daily'` — values: `'daily'` | `'weekdays'` | `'weekly'`
- `frequency_day TINYINT(1) NULL` — ISO weekday (1=Mon … 7=Sun); only relevant when `frequency = 'weekly'`

**Migrations**:
```sql
-- db/schema.sql (append)
ALTER TABLE teams ADD COLUMN frequency     VARCHAR(10)  NOT NULL DEFAULT 'daily';
ALTER TABLE teams ADD COLUMN frequency_day TINYINT(1)   NULL;
```

PostgreSQL (`db/schema-postgresql.sql`): `frequency VARCHAR(10) NOT NULL DEFAULT 'daily'`, `frequency_day SMALLINT NULL`

SQLite (`tests/schema-sqlite.sql`): `frequency TEXT NOT NULL DEFAULT 'daily'`, `frequency_day INTEGER NULL`

---

### AC-2 — `isTeamDue()` respects frequency

Current `isTeamDue()` checks whether the current time in the team's timezone is within 60s of `standup_time`. It does not handle weekend skipping — that is done in the cron loop.

**Change**: Move weekend logic into `isTeamDue()` and extend for frequency:

```php
function isTeamDue(array $team, DateTimeImmutable $nowUtc): bool
{
    $teamTz   = new DateTimeZone($team['timezone']);
    $nowLocal = $nowUtc->setTimezone($teamTz);
    $dayOfWeek = (int) $nowLocal->format('N');  // 1=Mon … 7=Sun

    $frequency = $team['frequency'] ?? 'daily';

    // Frequency guard
    if ($frequency === 'weekly') {
        $targetDay = (int) ($team['frequency_day'] ?? 1);
        if ($dayOfWeek !== $targetDay) {
            return false;  // not the configured weekday
        }
    } elseif ($frequency === 'daily' || $frequency === 'weekdays') {
        if ($dayOfWeek === 6 || $dayOfWeek === 7) {
            return false;  // weekend — skip
        }
    }

    // Time proximity check (unchanged)
    $scheduledLocal = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $nowLocal->format('Y-m-d') . ' ' . substr((string) $team['standup_time'], 0, 5),
        $teamTz
    );
    if ($scheduledLocal === false) { return false; }
    $diff = abs($nowUtc->getTimestamp() - $scheduledLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp());
    return $diff < 60;
}
```

**Cron change**: Remove the weekend skip block from `cron/send_standups.php` (lines that check `$dayOfWeek === 6 || $dayOfWeek === 7`) — this logic now lives entirely inside `isTeamDue()`. `isSummaryDue()` must also gain the same frequency guard (see AC-3).

---

### AC-3 — `isSummaryDue()` respects frequency

`isSummaryDue()` is in `src/SummaryEmailer.php`. Apply the same frequency guard before the time proximity check:

```php
function isSummaryDue(array $team, DateTimeImmutable $nowUtc): bool
{
    $teamTz   = new DateTimeZone($team['timezone']);
    $nowLocal = $nowUtc->setTimezone($teamTz);
    $dayOfWeek = (int) $nowLocal->format('N');
    $frequency = $team['frequency'] ?? 'daily';

    if ($frequency === 'weekly') {
        if ($dayOfWeek !== (int) ($team['frequency_day'] ?? 1)) { return false; }
    } elseif ($dayOfWeek === 6 || $dayOfWeek === 7) {
        return false;
    }

    // Existing time proximity check (standup_time + 1 hour)…
```

---

### AC-4 — `updateTeam()` extended to persist frequency fields

```php
function updateTeam(
    PDO $pdo, int $teamId, string $name, string $timezone, string $standupTime,
    int $summaryToAllDevelopers = 0,
    string $frequency = 'daily',
    ?int $frequencyDay = null
): void {
    $pdo->prepare(
        'UPDATE teams SET name = ?, timezone = ?, standup_time = ?,
         summary_to_all_developers = ?, frequency = ?, frequency_day = ?
         WHERE id = ?'
    )->execute([trim($name), $timezone, $standupTime, $summaryToAllDevelopers,
                $frequency, $frequencyDay, $teamId]);
}
```

---

### AC-5 — `public/teams/edit.php` — frequency selector in the edit form

Add after the `summary_to_all_developers` checkbox:

```php
// In POST handler — validate and read:
$frequency    = in_array($_POST['frequency'] ?? '', ['daily','weekdays','weekly'], true)
                ? $_POST['frequency'] : 'daily';
$frequencyDay = ($frequency === 'weekly')
                ? max(1, min(7, (int)($_POST['frequency_day'] ?? 1)))
                : null;
// Pass to updateTeam(): ... $frequency, $frequencyDay
```

HTML in the form:
```html
<div class="mb-4">
  <label class="block text-sm font-medium text-gray-700 mb-1">Standup frequency</label>
  <select name="frequency" id="frequency" class="border border-gray-300 rounded-lg px-3 py-2 text-sm ...">
    <option value="daily"    <?= ($team['frequency'] ?? 'daily') === 'daily'    ? 'selected' : '' ?>>Daily (Mon–Fri)</option>
    <option value="weekdays" <?= ($team['frequency'] ?? 'daily') === 'weekdays' ? 'selected' : '' ?>>Weekdays only</option>
    <option value="weekly"   <?= ($team['frequency'] ?? 'daily') === 'weekly'   ? 'selected' : '' ?>>Weekly</option>
  </select>
</div>
<div id="frequency-day-picker" style="display: <?= ($team['frequency'] ?? 'daily') === 'weekly' ? 'block' : 'none' ?>;">
  <label class="block text-sm font-medium text-gray-700 mb-1">Which day?</label>
  <select name="frequency_day" class="...">
    <option value="1" <?= ($team['frequency_day'] ?? 1) == 1 ? 'selected' : '' ?>>Monday</option>
    <option value="2" <?= ($team['frequency_day'] ?? 1) == 2 ? 'selected' : '' ?>>Tuesday</option>
    <!-- … through 7=Sunday -->
  </select>
</div>
<script>
document.getElementById('frequency').addEventListener('change', function() {
  document.getElementById('frequency-day-picker').style.display =
    this.value === 'weekly' ? 'block' : 'none';
});
</script>
```

No JS library — inline `<script>` is acceptable as it is a single listener with no DOM manipulation beyond `style.display`.

---

### AC-6 — PHPUnit tests: 3 new tests

New test class `tests/FrequencyTest.php`:

| Test | What it verifies |
|---|---|
| `testDailyTeamSkipsWrongWeekday` | `isTeamDue()` returns `false` on Saturday for a `daily` team |
| `testWeeklyTeamFiresOnCorrectDay` | `isTeamDue()` returns `true` on the configured `frequency_day` at the right time |
| `testWeeklyTeamSkipsWrongDay` | `isTeamDue()` returns `false` when `frequency = 'weekly'` and today is not `frequency_day` |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append 2 ALTER TABLE statements |
| `db/schema-postgresql.sql` | Add columns + migrations |
| `tests/schema-sqlite.sql` | Add columns to `CREATE TABLE teams` |
| `src/StandupEmailer.php` | Extend `isTeamDue()` with frequency guard |
| `src/SummaryEmailer.php` | Extend `isSummaryDue()` with frequency guard |
| `src/TeamRepository.php` | Extend `updateTeam()` signature + SQL |
| `cron/send_standups.php` | Remove global weekend skip (moved into `isTeamDue()`) |
| `public/teams/edit.php` | Add frequency selector + inline JS |
| `tests/FrequencyTest.php` (new) | 3 PHPUnit tests |
