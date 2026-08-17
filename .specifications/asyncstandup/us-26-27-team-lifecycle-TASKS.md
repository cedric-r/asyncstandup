# TASKS — US-26 + US-27: Team Lifecycle (Suspension + Deletion Hardening)

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-26-27-team-lifecycle`  
**Agent**: PHP Developer (`fa2e6dbf`)  
**Stories**: US-26 (Team Suspension) + US-27 (Team Deletion Hardening)

---

## Phase 1 — Branch setup

**T-1** `backend-dev` — Create shared feature branch
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" \
  checkout -b feature/us-26-27-team-lifecycle
```

---

## Phase 2 — Schema: add `teams.status` column (US-26 AC-1)

**T-2** `backend-dev` — Update `tests/schema-sqlite.sql`

Add `status TEXT NOT NULL DEFAULT 'active',` inside `CREATE TABLE teams` after `summary_to_all_developers`:
```sql
CREATE TABLE IF NOT EXISTS teams (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    org_id       INTEGER NOT NULL,
    name         TEXT NOT NULL,
    timezone     TEXT NOT NULL,
    standup_time TEXT NOT NULL,
    summary_to_all_developers INTEGER NOT NULL DEFAULT 0,
    status       TEXT NOT NULL DEFAULT 'active',
    created_by   INTEGER NULL,
    created_at   TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (org_id)     REFERENCES organisations(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
```

**T-3** `backend-dev` — Append migration to `db/schema.sql`
```sql
-- US-26: team suspension
ALTER TABLE teams ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active';
```
Append after the last `-- US-24` block.

**T-4** `backend-dev` — Update `db/schema-postgresql.sql`

Add `status VARCHAR(10) NOT NULL DEFAULT 'active',` inside `CREATE TABLE teams`.
Also append at the bottom:
```sql
-- US-26: team suspension (run on existing PostgreSQL installs)
ALTER TABLE teams ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'active';
```

---

## Phase 3 — `src/TeamRepository.php`: suspend/reactivate functions (US-26 AC-4)

**T-5** `backend-dev` — Add `suspendTeam()` and `reactivateTeam()`

Insert after `updateTeam()`:
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

## Phase 4 — `src/TeamRepository.php`: harden `deleteTeam()` (US-27 AC-1 + AC-2)

**T-6** `backend-dev` — Rewrite `deleteTeam()` with transaction + step 0 + subquery syntax

Full replacement:
```php
function deleteTeam(PDO $pdo, int $teamId): void
{
    $pdo->beginTransaction();

    try {
        // Step 0 — mark suspended immediately (blocks concurrent cron getAllTeams() call)
        $pdo->prepare("UPDATE teams SET status = 'suspended' WHERE id = ?")
            ->execute([$teamId]);

        // Step 1 — standup_answers (subquery; portable across MySQL/pgsql/sqlite)
        $pdo->prepare('
            DELETE FROM standup_answers WHERE submission_id IN (
                SELECT ss.id FROM standup_submissions ss
                WHERE ss.token_id IN (
                    SELECT id FROM standup_tokens WHERE team_id = ?
                )
            )
        ')->execute([$teamId]);

        // Step 2 — standup_submissions
        $pdo->prepare('
            DELETE FROM standup_submissions WHERE token_id IN (
                SELECT id FROM standup_tokens WHERE team_id = ?
            )
        ')->execute([$teamId]);

        // Step 3 — standup_tokens
        $pdo->prepare('DELETE FROM standup_tokens WHERE team_id = ?')->execute([$teamId]);

        // Step 4 — summary_sent
        $pdo->prepare('DELETE FROM summary_sent WHERE team_id = ?')->execute([$teamId]);

        // Step 5 — team_recipients (team-scoped only; cross-team rows unaffected)
        $pdo->prepare('DELETE FROM team_recipients WHERE team_id = ?')->execute([$teamId]);

        // Step 6 — team_questions
        $pdo->prepare('DELETE FROM team_questions WHERE team_id = ?')->execute([$teamId]);

        // Step 7 — invitations
        $pdo->prepare('DELETE FROM invitations WHERE team_id = ?')->execute([$teamId]);

        // Step 8 — team_members
        $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$teamId]);

        // Step 9 — teams row
        $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

---

## Phase 5 — Email filtering (US-26 AC-2 + AC-3)

**T-7** `backend-dev` — Update `getAllTeams()` in `src/StandupEmailer.php`

```php
function getAllTeams(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT * FROM teams WHERE status = 'active'");
    $stmt->execute();
    return $stmt->fetchAll();
}
```

**T-8** `backend-dev` — Update `getPendingTokensForUser()` in `src/DashboardRepository.php`

Add `AND t.status = 'active'` to the teams join WHERE clause:
```sql
WHERE st.user_id = ?
  AND st.used_at IS NULL
  AND st.expires_at > ?
  AND tm.is_developer = 1
  AND t.status = 'active'   -- ← add this line
```

---

## Phase 6 — New endpoint: `public/teams/suspend.php` (US-26 AC-4)

**T-9** `backend-dev` — Create `public/teams/suspend.php`

```php
<?php
declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';

startSession();
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

validateCsrfToken($_POST['csrf_token'] ?? '');

$pdo    = getDb($config);
$teamId = (int) ($_GET['id'] ?? 0);
$team   = getTeamById($pdo, $teamId);

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) {
    forbid();
}

$action = $_POST['action'] ?? '';

if ($action === 'suspend') {
    suspendTeam($pdo, $teamId);
    setFlash('success', 'Team suspended. No emails will be sent until reactivated.');
} elseif ($action === 'reactivate') {
    reactivateTeam($pdo, $teamId);
    setFlash('success', 'Team reactivated. Emails will resume at the next scheduled time.');
} else {
    http_response_code(400);
    exit;
}

header('Location: /teams/index.php?org_id=' . (int) $team['org_id']);
exit;
```

---

## Phase 7 — UI: badges and buttons (US-26 AC-5)

**T-10** `backend-dev` — Update `public/teams/index.php`

1. Add `[Suspended]` badge next to team name (in the card `<p class="font-semibold">` line):
```php
<?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?>
<?php if (($team['status'] ?? 'active') === 'suspended'): ?>
  <span class="inline-block text-xs font-medium bg-amber-100 text-amber-700 px-2 py-0.5 rounded ml-1">[Suspended]</span>
<?php endif; ?>
```

2. Add Suspend / Reactivate button in the owner action buttons block (after the existing Settings link):
```php
<?php if (($team['status'] ?? 'active') === 'suspended'): ?>
  <form method="POST" action="/teams/suspend.php?id=<?= (int) $team['id'] ?>" class="inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="reactivate">
    <button type="submit" class="text-xs bg-green-600 hover:bg-green-700 text-white font-medium py-1 px-2.5 rounded">Reactivate</button>
  </form>
<?php else: ?>
  <form method="POST" action="/teams/suspend.php?id=<?= (int) $team['id'] ?>" class="inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="suspend">
    <button type="submit" class="text-xs bg-amber-500 hover:bg-amber-600 text-white font-medium py-1 px-2.5 rounded">Suspend</button>
  </form>
<?php endif; ?>
```

Note: `$csrfToken` is already generated in `index.php`. Confirm with a quick grep — if not, add `$csrfToken = generateCsrfToken();` near the top.

**T-11** `backend-dev` — Update `public/dashboard.php`

In the team loop, add `[Suspended]` badge next to the team name in whatever element displays it. Do not filter out suspended teams — owner must still see them in the dashboard to navigate to Settings.

---

## Phase 8 — PHPUnit tests (US-26 AC-6 + US-27 AC-4)

**T-12** `backend-dev` — Create `tests/TeamSuspensionTest.php`

4 tests (see US-26 STORY.md AC-6 for full test bodies). All use `createTestPdo()`.

**T-13** `backend-dev` — Create `tests/TeamDeletionHardeningTest.php`

4 tests (see US-27 STORY.md AC-4 for full test bodies). All use `createTestPdo()`.

Key fixture pattern for cross-team recipient test:
```php
// Insert recipient into both teams
$insertRecipient->execute([1, 'shared@example.com', 'Shared']);   // team 1
$insertRecipient->execute([2, 'shared@example.com', 'Shared']);   // team 2
deleteTeam($pdo, 1);
// Assert team_recipients still has 1 row for team 2
$count = $pdo->query('SELECT COUNT(*) FROM team_recipients WHERE team_id = 2')->fetchColumn();
$this->assertEquals(1, $count);
```

**T-14** `backend-dev` — Run full test suite

```bash
cd "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup"
./vendor/bin/phpunit
```

Target: 78 tests (70 prior + 4 US-26 + 4 US-27), all green.

---

## Phase 9 — Commit and signal

**T-15** `backend-dev` — Commit all changes
```bash
git add \
  db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
  src/TeamRepository.php src/StandupEmailer.php src/DashboardRepository.php \
  public/teams/suspend.php public/teams/index.php public/dashboard.php \
  tests/TeamSuspensionTest.php tests/TeamDeletionHardeningTest.php \
  .specifications/asyncstandup/us-26-team-suspension/ \
  .specifications/asyncstandup/us-27-team-deletion-hardening/ \
  .specifications/asyncstandup/us-26-27-team-lifecycle-TASKS.md

git commit -m "feat(us-26-27): team suspension + deletion hardening — status column, transaction, subquery cascade"
```

Signal Team Lead with commit hash.

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| US-26 AC-1 (schema) | T-2, T-3, T-4 |
| US-26 AC-2 (`getAllTeams` filter) | T-7 |
| US-26 AC-3 (pending tokens filter) | T-8 |
| US-26 AC-4 (suspend endpoint + repo functions) | T-5, T-9 |
| US-26 AC-5 (UI badges + buttons) | T-10, T-11 |
| US-26 AC-6 (4 tests) | T-12, T-14 |
| US-27 AC-1 (transaction wrapper + step 0) | T-6 |
| US-27 AC-2 (subquery deletes) | T-6 |
| US-27 AC-3 (cross-team recipient safe) | T-13 (verified by test) |
| US-27 AC-4 (4 tests) | T-13, T-14 |
| US-27 AC-5 (70 prior tests pass) | T-14 |

---

## Estimate

| Phase | Tasks | Hours |
|---|---|---|
| Branch | T-1 | 0.25h |
| Schema (3 files) | T-2, T-3, T-4 | 1h |
| `suspendTeam()`, `reactivateTeam()` | T-5 | 0.5h |
| `deleteTeam()` rewrite | T-6 | 1.5h |
| Email + pending filter | T-7, T-8 | 0.5h |
| `suspend.php` endpoint | T-9 | 1h |
| UI badges + buttons | T-10, T-11 | 1h |
| Tests (8 tests) | T-12, T-13, T-14 | 3h |
| Commit | T-15 | 0.25h |
| **Total** | | **~9h** |
