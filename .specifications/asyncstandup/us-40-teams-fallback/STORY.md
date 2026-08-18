# US-40: Fallback, Logging & Admin Visibility

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-40-teams-fallback`  
**Depends on**: US-37 + US-38 + US-39 merged

---

## Story

**As an** admin  
**I want** to see which teams have Teams mode enabled and any recent delivery errors  
**So that** I can diagnose silently failing integrations without reading log files

---

## Acceptance Criteria

### AC-1 — Schema: error tracking columns on `teams`

```sql
-- db/schema.sql (append — US-40)
ALTER TABLE teams ADD COLUMN teams_last_error    VARCHAR(255) NULL;
ALTER TABLE teams ADD COLUMN teams_last_error_at DATETIME    NULL;
```

`db/schema-postgresql.sql`: `ALTER TABLE teams ADD COLUMN IF NOT EXISTS ...` (same pattern).

`tests/schema-sqlite.sql`: add `teams_last_error TEXT NULL` and `teams_last_error_at TEXT NULL` to `CREATE TABLE teams`.

---

### AC-2 — `src/TeamRepository.php`: `recordTeamsError()` and `clearTeamsError()`

```php
function recordTeamsError(PDO $pdo, int $teamId, string $message): void
{
    $pdo->prepare(
        'UPDATE teams SET teams_last_error = ?, teams_last_error_at = ? WHERE id = ?'
    )->execute([mb_substr($message, 0, 255), gmdate('Y-m-d H:i:s'), $teamId]);
}

function clearTeamsError(PDO $pdo, int $teamId): void
{
    $pdo->prepare(
        'UPDATE teams SET teams_last_error = NULL, teams_last_error_at = NULL WHERE id = ?'
    )->execute([$teamId]);
}
```

---

### AC-3 — Call `recordTeamsError()` / `clearTeamsError()` at all Teams failure points

Replace bare `error_log` calls (added in US-37 + US-38) with paired `recordTeamsError()` + `error_log`:

**In `src/SummaryEmailer.php`** (webhook failure):
```php
if (!$success) {
    $msg = "Teams webhook failed for team {$team['id']}";
    error_log("[AsyncStandUp] $msg — falling back to email");
    recordTeamsError($pdo, (int) $team['id'], $msg);
    sendSummaryEmail($team, $summaryData);
} else {
    clearTeamsError($pdo, (int) $team['id']);
}
```

**In `src/StandupEmailer.php`** (bot DM failure):
```php
if (!$sent) {
    $msg = "Teams DM failed for user {$user['id']} team {$team['id']}";
    error_log("[AsyncStandUp] $msg — falling back to email");
    recordTeamsError($pdo, (int) $team['id'], $msg);
    // Fall through to email
} else {
    clearTeamsError($pdo, (int) $team['id']);  // clear on first success
}
```

Note: `clearTeamsError` is called on success so stale errors don't persist after a transient failure.

---

### AC-4 — `public/admin/teams.php`: admin Teams overview page

New page in `public/admin/` (alongside existing `users.php`, `index.php`). Requires `is_admin = 1` session check.

**GET** — list ALL teams (all orgs) with:
- Team name + org name
- `notification_channel` badge (`email` = grey, `teams-summary` = blue, `teams` = green)
- `teams_webhook_url` (truncated to 60 chars if present, else `—`)
- `teams_last_error` + `teams_last_error_at` — show in red if both non-null; show `—` if clean
- Link to edit page

```php
function getTeamsAdminOverview(PDO $pdo): array
{
    $stmt = $pdo->query('
        SELECT t.id, t.name AS team_name, t.notification_channel,
               t.teams_webhook_url, t.teams_channel_name,
               t.teams_last_error, t.teams_last_error_at,
               o.name AS org_name
        FROM teams t
        JOIN organisations o ON o.id = t.org_id
        ORDER BY o.name, t.name
    ');
    return $stmt->fetchAll();
}
```

HTML badges:
```php
$badges = [
    'email'         => '<span class="bg-gray-100 text-gray-600 text-xs px-2 py-0.5 rounded-full">email</span>',
    'teams-summary' => '<span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full">Teams Summary</span>',
    'teams'         => '<span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full">Teams DM</span>',
];
```

Error display:
```html
<?php if ($team['teams_last_error']): ?>
  <span class="text-red-600 text-xs">
    <?= htmlspecialchars(substr($team['teams_last_error'], 0, 80), ENT_QUOTES) ?>
    <span class="text-gray-400">(<?= htmlspecialchars(substr($team['teams_last_error_at'] ?? '', 0, 10), ENT_QUOTES) ?>)</span>
  </span>
<?php else: ?>
  <span class="text-gray-400 text-xs">—</span>
<?php endif; ?>
```

---

### AC-5 — Link to `public/admin/teams.php` from admin nav

In `public/admin/index.php` (or nav partial), add:
```html
<a href="/admin/teams.php">Teams Integration</a>
```

---

### AC-6 — PHPUnit tests: 3 new tests

New test class `tests/TeamsFallbackTest.php`:

| Test | What it verifies |
|---|---|
| `testRecordTeamsErrorPersistsMessage` | `recordTeamsError($pdo, 1, 'webhook failed')` → DB row has non-null `teams_last_error` matching the message |
| `testClearTeamsErrorRemovesError` | After `recordTeamsError`, call `clearTeamsError` → both columns are `NULL` |
| `testGetTeamsAdminOverviewReturnsAllTeams` | Insert 2 teams in different orgs; `getTeamsAdminOverview()` returns 2 rows with `org_name` |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append `teams_last_error` + `teams_last_error_at` |
| `db/schema-postgresql.sql` | Same |
| `tests/schema-sqlite.sql` | Add both columns to `CREATE TABLE teams` |
| `src/TeamRepository.php` | Add `recordTeamsError()`, `clearTeamsError()`, `getTeamsAdminOverview()` |
| `src/SummaryEmailer.php` | Replace `error_log` with `recordTeamsError` + `clearTeamsError` on success |
| `src/StandupEmailer.php` | Same — bot DM failure path |
| `public/admin/teams.php` (new) | Teams integration overview page |
| `public/admin/index.php` | Add nav link |
| `tests/TeamsFallbackTest.php` (new) | 3 tests |
