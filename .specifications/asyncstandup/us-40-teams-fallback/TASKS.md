# TASKS — US-40: Fallback, Logging & Admin Visibility

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-40-teams-fallback`  
**Agent**: PHP Developer (`fa2e6dbf`)  
**Dependency**: US-37 + US-38 + US-39 merged

---

## Phase 1 — Branch + schema (AC-1)

**T-1** `backend-dev`
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout main && git pull && git checkout -b feature/us-40-teams-fallback
```

**T-2** `backend-dev` — Append to `db/schema.sql`
```sql
-- US-40: Teams error tracking
ALTER TABLE teams ADD COLUMN teams_last_error    VARCHAR(255) NULL;
ALTER TABLE teams ADD COLUMN teams_last_error_at DATETIME    NULL;
```

**T-3** `backend-dev` — Append to `db/schema-postgresql.sql`
```sql
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_last_error    VARCHAR(255) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_last_error_at TIMESTAMP   NULL;
```

**T-4** `backend-dev` — Update `tests/schema-sqlite.sql`

Add to `CREATE TABLE teams`:
```sql
teams_last_error    TEXT NULL,
teams_last_error_at TEXT NULL,
```

---

## Phase 2 — `src/TeamRepository.php`: error helpers + overview query (AC-2, AC-4)

**T-5** `backend-dev` — Add `recordTeamsError()` and `clearTeamsError()`

Full implementations from STORY.md AC-2. Add to `src/TeamRepository.php` alongside other team functions.

**T-6** `backend-dev` — Add `getTeamsAdminOverview(PDO $pdo): array`

Full query from STORY.md AC-4. Returns all teams joined with `organisations`, ordered by org name then team name.

---

## Phase 3 — Wire error tracking into existing fallback points (AC-3)

**T-7** `backend-dev` — Update `src/SummaryEmailer.php`

Find the webhook failure `error_log` added in US-37. Replace:
```php
error_log("[AsyncStandUp] Teams webhook failed for team {$team['id']} — falling back to email");
```
With:
```php
$errMsg = "Teams webhook failed (HTTP error or timeout)";
error_log("[AsyncStandUp] {$errMsg} for team {$team['id']} — falling back to email");
recordTeamsError($pdo, (int) $team['id'], $errMsg);
```

And on successful channel post, add:
```php
clearTeamsError($pdo, (int) $team['id']);
```

Add `require_once` for `TeamRepository.php` if not already present. Ensure `$pdo` is in scope — inspect function signature; pass as parameter if needed.

**T-8** `backend-dev` — Update `src/StandupEmailer.php`

Find the bot DM failure `error_log` added in US-38. Apply same pattern: replace with `recordTeamsError()` on failure; add `clearTeamsError()` on success.

---

## Phase 4 — `public/admin/teams.php` (AC-4, AC-5)

**T-9** `backend-dev` — Inspect existing `public/admin/index.php` and `users.php` for structure

```bash
head -30 "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup/public/admin/index.php"
head -30 "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup/public/admin/users.php"
```

Match the auth guard pattern (likely `requireAdmin()` or `isAdmin()` check).

**T-10** `backend-dev` — Create `public/admin/teams.php`

Auth guard at top (same as `users.php`). Call `getTeamsAdminOverview($pdo)`. Render table with:
- Team name (link to `/teams/{id}/edit`)
- Org name
- Channel badge (grey/blue/green per mode)
- Webhook URL (truncated, or `—`)
- Teams last error (red text + date if set, else `—`)

Full HTML from STORY.md AC-4.

**T-11** `backend-dev` — Add nav link in `public/admin/index.php`

Find the section listing admin links and add:
```html
<a href="/admin/teams.php" class="...">Teams Integration</a>
```

---

## Phase 5 — Tests (AC-6)

**T-12** `backend-dev` — Create `tests/TeamsFallbackTest.php` (3 tests)

```php
class TeamsFallbackTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'OrgA'), (2, 'OrgB')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by) VALUES (1, 1, 'T1', 'UTC', '09:00', 1)");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by) VALUES (2, 2, 'T2', 'UTC', '09:00', 1)");
    }

    public function testRecordTeamsErrorPersistsMessage(): void
    {
        recordTeamsError($this->pdo, 1, 'webhook failed');
        $row = $this->pdo->query("SELECT teams_last_error FROM teams WHERE id = 1")->fetch();
        $this->assertEquals('webhook failed', $row['teams_last_error']);
    }

    public function testClearTeamsErrorRemovesError(): void
    {
        recordTeamsError($this->pdo, 1, 'err');
        clearTeamsError($this->pdo, 1);
        $row = $this->pdo->query("SELECT teams_last_error, teams_last_error_at FROM teams WHERE id = 1")->fetch();
        $this->assertNull($row['teams_last_error']);
        $this->assertNull($row['teams_last_error_at']);
    }

    public function testGetTeamsAdminOverviewReturnsAllTeams(): void
    {
        $rows = getTeamsAdminOverview($this->pdo);
        $this->assertCount(2, $rows);
        $orgNames = array_column($rows, 'org_name');
        $this->assertContains('OrgA', $orgNames);
        $this->assertContains('OrgB', $orgNames);
    }
}
```

**T-13** `backend-dev` — Run full test suite; target ≥125 tests (122 prior + 3 new)

---

## Phase 6 — Commit and signal

**T-14** `backend-dev`
```bash
git add \
  db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
  src/TeamRepository.php src/SummaryEmailer.php src/StandupEmailer.php \
  public/admin/teams.php public/admin/index.php \
  tests/TeamsFallbackTest.php \
  .specifications/asyncstandup/us-40-teams-fallback/
git commit -m "feat(us-40): Teams fallback + error tracking + admin visibility — recordTeamsError, admin/teams.php"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (schema error columns) | T-2, T-3, T-4 |
| AC-2 (recordTeamsError, clearTeamsError) | T-5 |
| AC-3 (wire into fallback points) | T-7, T-8 |
| AC-4 (admin/teams.php) | T-6, T-9, T-10 |
| AC-5 (nav link) | T-11 |
| AC-6 (3 tests) | T-12, T-13 |

**Estimate**: ~4h
