# TASKS — US-30: Configurable Standup Frequency

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-30-configurable-frequency`  
**Agent**: PHP Developer (`fa2e6dbf`)

---

## Phase 1 — Branch + schema (AC-1)

**T-1** `backend-dev` — Create branch
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout -b feature/us-30-configurable-frequency
```

**T-2** `backend-dev` — Add `frequency` and `frequency_day` to all 3 schema files (see STORY.md AC-1 for exact SQL)

`tests/schema-sqlite.sql` — inside `CREATE TABLE teams` after `summary_to_all_developers`:
```sql
frequency      TEXT    NOT NULL DEFAULT 'daily',
frequency_day  INTEGER NULL,
```

`db/schema.sql` — append:
```sql
-- US-30: configurable frequency
ALTER TABLE teams ADD COLUMN frequency     VARCHAR(10) NOT NULL DEFAULT 'daily';
ALTER TABLE teams ADD COLUMN frequency_day TINYINT(1)  NULL;
```

`db/schema-postgresql.sql` — add both columns in `CREATE TABLE teams`; append migrations at bottom.

---

## Phase 2 — Logic: `isTeamDue()` and `isSummaryDue()` (AC-2, AC-3)

**T-3** `backend-dev` — Rewrite `isTeamDue()` in `src/StandupEmailer.php`

Replace the current function with the full implementation from STORY.md AC-2. Key change: frequency guard added before the time proximity check; the function now handles its own weekend skip.

**T-4** `backend-dev` — Extend `isSummaryDue()` in `src/SummaryEmailer.php`

Insert the frequency guard block at the top of the function body (before the existing `$scheduledLocal` calculation), per STORY.md AC-3.

**T-5** `backend-dev` — Remove global weekend skip from `cron/send_standups.php`

Delete these lines from the `foreach ($teams as $team)` loop:
```php
// Feature 3: skip weekends in the team's local timezone.
$dayOfWeek = (int) $nowLocal->format('N');
if ($dayOfWeek === 6 || $dayOfWeek === 7) {
    continue;
}
```

This logic now lives in `isTeamDue()` and `isSummaryDue()`. Weekend behaviour for `daily`/`weekdays` teams is identical — no regression.

---

## Phase 3 — `updateTeam()` signature extension (AC-4)

**T-6** `backend-dev` — Extend `updateTeam()` in `src/TeamRepository.php`

Add `string $frequency = 'daily'` and `?int $frequencyDay = null` parameters. Update the SQL to include both columns. See STORY.md AC-4 for the full function.

Also update the single call site in `public/teams/edit.php` POST handler to pass the two new arguments (done in T-7).

---

## Phase 4 — Edit form UI (AC-5)

**T-7** `backend-dev` — Update `public/teams/edit.php`

**POST handler additions** (in the validation block, after `$summaryToAllDevelopers`):
```php
$frequency    = in_array($_POST['frequency'] ?? '', ['daily', 'weekdays', 'weekly'], true)
                ? $_POST['frequency'] : 'daily';
$frequencyDay = ($frequency === 'weekly')
                ? max(1, min(7, (int) ($_POST['frequency_day'] ?? 1)))
                : null;
```

Update `updateTeam()` call to pass `$frequency, $frequencyDay`.

**Form HTML** — add frequency selector and day picker (with inline JS toggle) per STORY.md AC-5. Insert after the `summary_to_all_developers` checkbox block and before the submit button.

---

## Phase 5 — Tests (AC-6)

**T-8** `backend-dev` — Create `tests/FrequencyTest.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/StandupEmailer.php';

class FrequencyTest extends TestCase
{
    private function makeTeam(string $frequency, ?int $frequencyDay, string $standupTime, string $timezone): array
    {
        return [
            'frequency'     => $frequency,
            'frequency_day' => $frequencyDay,
            'standup_time'  => $standupTime . ':00',
            'timezone'      => $timezone,
        ];
    }

    public function testDailyTeamSkipsWeekend(): void
    {
        // 2026-08-15 is a Saturday
        $nowUtc = new DateTimeImmutable('2026-08-15 09:00:00', new DateTimeZone('UTC'));
        $team   = $this->makeTeam('daily', null, '09:00', 'UTC');
        $this->assertFalse(isTeamDue($team, $nowUtc));
    }

    public function testWeeklyTeamFiresOnCorrectDay(): void
    {
        // 2026-08-17 is a Monday (N=1)
        $nowUtc = new DateTimeImmutable('2026-08-17 09:00:00', new DateTimeZone('UTC'));
        $team   = $this->makeTeam('weekly', 1, '09:00', 'UTC');
        $this->assertTrue(isTeamDue($team, $nowUtc));
    }

    public function testWeeklyTeamSkipsWrongDay(): void
    {
        // 2026-08-18 is a Tuesday (N=2); team configured for Monday (1)
        $nowUtc = new DateTimeImmutable('2026-08-18 09:00:00', new DateTimeZone('UTC'));
        $team   = $this->makeTeam('weekly', 1, '09:00', 'UTC');
        $this->assertFalse(isTeamDue($team, $nowUtc));
    }
}
```

**T-9** `backend-dev` — Run full test suite; target ≥88 tests (85 prior + 3 new)

---

## Phase 6 — Commit and signal

**T-10** `backend-dev` — Commit
```bash
git add db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
        src/StandupEmailer.php src/SummaryEmailer.php src/TeamRepository.php \
        cron/send_standups.php public/teams/edit.php \
        tests/FrequencyTest.php \
        .specifications/asyncstandup/us-30-configurable-frequency/
git commit -m "feat(us-30): configurable standup frequency — daily/weekdays/weekly, isTeamDue extended"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (schema) | T-2 |
| AC-2 (`isTeamDue` frequency guard) | T-3, T-5 |
| AC-3 (`isSummaryDue` frequency guard) | T-4 |
| AC-4 (`updateTeam` extension) | T-6 |
| AC-5 (edit form UI) | T-7 |
| AC-6 (3 tests) | T-8, T-9 |

**Estimate**: ~5.5h total
