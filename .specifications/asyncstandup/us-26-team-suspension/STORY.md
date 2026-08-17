# US-26: Team Suspension

**Status**: APPROVED (autonomous mode)  
**Feature**: Team Lifecycle Management  
**Branch**: `feature/us-26-27-team-lifecycle` (shared with US-27)

---

## Story

**As a** team owner  
**I want** to suspend my team  
**So that** standup and summary emails stop being sent while all data is preserved, and I can reactivate the team when ready

---

## Acceptance Criteria

### AC-1 — Schema: `teams.status` column added

`status VARCHAR(10) NOT NULL DEFAULT 'active'` added to `teams` in all three schema files:

- `db/schema.sql` — append migration:
  ```sql
  ALTER TABLE teams ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active';
  ```
- `db/schema-postgresql.sql` — inline in `CREATE TABLE teams` definition (also `ALTER TABLE` migration comment for existing installs)
- `tests/schema-sqlite.sql` — inline in `CREATE TABLE teams` definition:
  ```sql
  status TEXT NOT NULL DEFAULT 'active',
  ```

Valid values: `'active'`, `'suspended'`. No DB constraint needed — enforced in PHP.

---

### AC-2 — `getAllTeams()` filters by `status = 'active'`

```php
// src/StandupEmailer.php
function getAllTeams(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT * FROM teams WHERE status = 'active'");
    $stmt->execute();
    return $stmt->fetchAll();
}
```

This single change stops both standup and summary emails for suspended teams — both cron paths call `getAllTeams()`.

---

### AC-3 — `getTeamsForUser()` filters pending tokens to active teams only

`getPendingTokensForUser()` in `src/DashboardRepository.php` must add `AND t.status = 'active'` to the `teams` join. A developer should not see a pending standup link if their team has been suspended.

```sql
-- Add to existing WHERE clause in getPendingTokensForUser():
AND t.status = 'active'
```

`getTeamsForUser()` (also in `DashboardRepository.php`) does **not** filter by status — suspended teams must still appear in the team list so the owner can see and reactivate them. They get a `[Suspended]` badge instead (AC-5).

---

### AC-4 — New endpoint: `public/teams/suspend.php`

POST-only. CSRF-protected. Owner-only.

```
POST /teams/suspend.php?id={teamId}
Body: csrf_token={token}&action=suspend|reactivate
```

Logic:
1. Validate CSRF token
2. Load team; confirm `isTeamOwner()` — else `forbid()`
3. `action=suspend` → `UPDATE teams SET status = 'suspended' WHERE id = ?`
4. `action=reactivate` → `UPDATE teams SET status = 'active' WHERE id = ?`
5. `setFlash('success', 'Team suspended.' | 'Team reactivated.')`
6. Redirect to `/teams/index.php?org_id={orgId}`

Two new `TeamRepository.php` functions:
```php
function suspendTeam(PDO $pdo, int $teamId): void
{
    $pdo->prepare("UPDATE teams SET status = 'suspended' WHERE id = ?")
        ->execute([$teamId]);
}

function reactivateTeam(PDO $pdo, int $teamId): void
{
    $pdo->prepare("UPDATE teams SET status = 'active' WHERE id = ?")
        ->execute([$teamId]);
}
```

---

### AC-5 — Team list and dashboard show suspension state

**`public/teams/index.php`** — in the team card, after the team name:
```php
<?php if (($team['status'] ?? 'active') === 'suspended'): ?>
  <span class="inline-block text-xs font-medium bg-amber-100 text-amber-700 px-2 py-0.5 rounded ml-2">[Suspended]</span>
<?php endif; ?>
```

Also in the action buttons section for owners, add Suspend / Reactivate button:
```php
<?php if ($isTOwner): ?>
  <?php if (($team['status'] ?? 'active') === 'suspended'): ?>
    <form method="POST" action="/teams/suspend.php?id=<?= (int) $team['id'] ?>" class="inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="reactivate">
      <button type="submit" class="text-xs bg-green-600 hover:bg-green-700 text-white font-medium py-1 px-2.5 rounded">Reactivate</button>
    </form>
  <?php else: ?>
    <form method="POST" action="/teams/suspend.php?id=<?= (int) $team['id'] ?>" class="inline">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
      <input type="hidden" name="action" value="suspend">
      <button type="submit" class="text-xs bg-amber-500 hover:bg-amber-600 text-white font-medium py-1 px-2.5 rounded">Suspend</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
```

**`public/dashboard.php`** — `getTeamsForUser()` already returns all teams (including suspended). Add a `[Suspended]` badge next to the team name in the dashboard team list. No structural change to the query.

---

### AC-6 — PHPUnit tests

New test class `tests/TeamSuspensionTest.php`:

| Test | Assertion |
|---|---|
| `testSuspendTeamSetsStatusSuspended` | After `suspendTeam()`, `SELECT status FROM teams WHERE id = ?` returns `'suspended'` |
| `testReactivateTeamSetsStatusActive` | After `reactivateTeam()`, status returns `'active'` |
| `testGetAllTeamsExcludesSuspended` | `getAllTeams()` returns only active teams; suspended team absent |
| `testGetPendingTokensExcludesSuspendedTeam` | `getPendingTokensForUser()` returns no tokens for a suspended team, even if token exists and is unexpired |

All tests use the SQLite in-memory test database (`createTestPdo()`).

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append `ALTER TABLE teams ADD COLUMN status ...` migration |
| `db/schema-postgresql.sql` | Add `status` to `CREATE TABLE teams`; add migration comment |
| `tests/schema-sqlite.sql` | Add `status` column to `CREATE TABLE teams` |
| `src/TeamRepository.php` | Add `suspendTeam()`, `reactivateTeam()` |
| `src/StandupEmailer.php` | Update `getAllTeams()` — add `WHERE status = 'active'` |
| `src/DashboardRepository.php` | Update `getPendingTokensForUser()` — add `AND t.status = 'active'` |
| `public/teams/suspend.php` (new) | Suspend/reactivate POST endpoint |
| `public/teams/index.php` | `[Suspended]` badge + Suspend/Reactivate buttons |
| `public/dashboard.php` | `[Suspended]` badge on team list |
| `tests/TeamSuspensionTest.php` (new) | 4 PHPUnit tests |
