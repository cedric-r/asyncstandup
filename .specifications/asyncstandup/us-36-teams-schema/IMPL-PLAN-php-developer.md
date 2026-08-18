# IMPL-PLAN — US-36: Teams Schema & Per-Team Mode Selector

**Status**: PENDING GATE C APPROVAL
**Branch**: `feature/us-36-teams-schema`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-36 — Teams Schema & Per-Team Mode Selector

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-5 and TASKS.md T-1 through T-12.

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `tests/schema-sqlite.sql` `teams` table | Ends with `created_at TEXT NOT NULL DEFAULT ''` before FOREIGN KEY lines — new columns added before FOREIGN KEY block |
| `tests/schema-sqlite.sql` `users` table | Ends with `created_at TEXT NOT NULL DEFAULT ''` before closing `)` — new columns added there |
| `updateTeam()` current signature | `(PDO, int, string, string, string, int=0, string='daily', ?int=null): void` — returns `void`, no validation, no return value |
| `updateTeam()` call in `edit.php` (line 41) | `updateTeam($pdo, $teamId, $name, $timezone, $standupTime.':00', $summaryToAllDevelopers, $frequency, $frequencyDay)` — positional; new params added at end with defaults — no breakage to existing tests |
| `edit.php` POST block | Lines 25–45; submit button is last element before closing `</form>`. New fields inserted before `<button type="submit">` |
| Current return type `void` → `array\|false` | Change required per TASKS.md T-6. All existing callers pass; `edit.php` ignores return value currently — must be updated to check `false` |
| Existing `FrequencyTest.php` callers | Use positional args up to `$frequencyDay` — new params have defaults → no changes needed |

---

## Files to Change / Create

| File | Change |
|---|---|
| `db/schema.sql` | Append 6 ALTER statements (4 teams cols + 2 users cols) |
| `db/schema-postgresql.sql` | Append 6 ALTER statements with IF NOT EXISTS |
| `tests/schema-sqlite.sql` | Add 4 cols to `CREATE TABLE teams`; add 2 cols to `CREATE TABLE users` |
| `src/TeamRepository.php` | `updateTeam()`: 3 new params + validation + return `array\|false` instead of `void` |
| `public/teams/edit.php` | POST handler: extract + pass new params, check `false`; GET render: radio group + teams-fields div + JS toggle |
| `tests/TeamsSchemaTest.php` (new) | 3 PHPUnit tests |
| `tests/bootstrap.php` | No change needed — `TeamRepository.php` already required |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-36-teams-schema` created from `main`.

---

### T-2 + T-3 — Schema: `db/schema.sql` + `db/schema-postgresql.sql`

**`db/schema.sql`** — append:
```sql
-- US-36: MS Teams integration — teams columns
ALTER TABLE teams ADD COLUMN notification_channel   VARCHAR(10)  NOT NULL DEFAULT 'email';
ALTER TABLE teams ADD COLUMN teams_webhook_url      VARCHAR(500) NULL;
ALTER TABLE teams ADD COLUMN teams_channel_name     VARCHAR(100) NULL;
ALTER TABLE teams ADD COLUMN teams_conversation_ref TEXT         NULL;

-- US-36: MS Teams integration — users columns
ALTER TABLE users ADD COLUMN teams_aad_id           VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN teams_conversation_ref TEXT         NULL;
```

**`db/schema-postgresql.sql`** — append same with `IF NOT EXISTS` and `TIMESTAMP` for any datetime.

---

### T-4 — Schema: `tests/schema-sqlite.sql`

**`CREATE TABLE teams`**: Insert 4 new columns before `FOREIGN KEY (org_id)` line:
```sql
notification_channel   TEXT NOT NULL DEFAULT 'email',
teams_webhook_url      TEXT NULL,
teams_channel_name     TEXT NULL,
teams_conversation_ref TEXT NULL,
```

**`CREATE TABLE users`**: Insert 2 new columns before closing `)`:
```sql
teams_aad_id           TEXT NULL,
teams_conversation_ref TEXT NULL,
```

After edit: run full suite immediately to confirm no regressions.

---

### T-5 + T-6 — `src/TeamRepository.php`: extend `updateTeam()` (AC-3)

Signature change:
- Add `string $notificationChannel = 'email'`, `?string $teamsWebhookUrl = null`, `?string $teamsChannelName = null`
- Return type: `void` → `array|false`
- Validation:
  - `$notificationChannel` must be in `['email', 'teams', 'teams-summary']` → else `return false`
  - If `teams` or `teams-summary`: `$teamsWebhookUrl` must be non-empty, pass `FILTER_VALIDATE_URL`, and start with `'https://'` → else `return false`
  - If `email`: set `$teamsWebhookUrl = null` (clear stored URL)
  - Trim `$teamsChannelName` to `mb_substr(..., 0, 100)` or null
- SQL: extend UPDATE SET with `notification_channel = ?, teams_webhook_url = ?, teams_channel_name = ?`
- Return: `getTeamById($pdo, $teamId)` on success

**PHPStan note**: `getTeamById()` returns `?array`; return type must be `array|false` not `array|null|false`. Use `getTeamById($pdo, $teamId) ?? false` to collapse null → false.

---

### T-7 + T-8 + T-9 — `public/teams/edit.php` (AC-4)

**POST handler** (after existing `$frequencyDay` extraction, before `if (empty($errors))`):
```php
$channel     = in_array($_POST['notification_channel'] ?? '', ['email', 'teams', 'teams-summary'], true)
               ? $_POST['notification_channel'] : 'email';
$webhookUrl  = trim($_POST['teams_webhook_url'] ?? '') ?: null;
$channelName = trim($_POST['teams_channel_name'] ?? '') ?: null;
```

Change `updateTeam()` call to pass new args and check return:
```php
$result = updateTeam($pdo, $teamId, $name, $timezone, $standupTime . ':00',
                     $summaryToAllDevelopers, $frequency, $frequencyDay,
                     $channel, $webhookUrl, $channelName);
if ($result === false) {
    $errors[] = 'Invalid notification channel or missing/invalid webhook URL for Teams mode.';
}
```

Move `setFlash` + redirect into the `if ($result !== false)` branch.

**GET render** — add before `<button type="submit">`:
- `<fieldset>` with 3 radio options (email/teams/teams-summary); value from `$_POST['notification_channel'] ?? $team['notification_channel'] ?? 'email'`
- `<div id="teams-fields">` with webhook URL input + channel name input; initial `style.display` set via PHP based on current channel value (avoids flash-of-wrong-state even without JS)
- Inline `<script>` IIFE for JS visibility toggle + initial `update()` call

---

### T-10 — `tests/TeamsSchemaTest.php` (AC-5)

3 tests per TASKS.md T-10. Note: `teams` INSERT must include `created_by` column (INTEGER NULL) — confirmed in schema. `TeamsSchemaTest.php` must `require_once` `TeamRepository.php`.

---

### T-11 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥112 tests (109 + 3), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors. PHPStan note: `updateTeam()` return type `array|false` — must match docblock. `getTeamById()` returns `?array`; use `?? false` to satisfy return type.

---

### T-12 — Commit

```bash
git add db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
        src/TeamRepository.php public/teams/edit.php \
        tests/TeamsSchemaTest.php \
        .specifications/asyncstandup/us-36-teams-schema/
git commit -m "feat(us-36): Teams schema — notification_channel, webhook_url, aad_id, mode selector UI"
```

---

## Risk Notes

1. **`updateTeam()` return type `void` → `array|false`**: All existing callers (`FrequencyTest.php` + `edit.php`) ignore the return value — safe change. PHPStan may complain about `getTeamById()` returning `?array` if return type is `array|false`; use `?? false`.
2. **`edit.php` POST flow**: Current code calls `updateTeam()` unconditionally inside `if (empty($errors))` then always does `setFlash` + redirect. Must restructure: check `$result !== false` before redirect; push error otherwise.
3. **SQLite `CREATE TABLE` edit**: Adding columns before FOREIGN KEY lines — must preserve all FK constraints. Confirm via test run immediately after schema edit.
4. **JS initial state**: Without JS, `teams-fields` must be visible by default (or PHP-rendered visibility). Set `style="display: <?= in_array($channel, ['teams','teams-summary']) ? '' : 'none' ?>"` via PHP — correct initial state without JS flash.
