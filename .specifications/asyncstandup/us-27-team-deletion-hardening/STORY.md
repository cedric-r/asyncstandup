# US-27: Team Deletion Hardening

**Status**: APPROVED (autonomous mode)  
**Feature**: Team Lifecycle Management  
**Branch**: `feature/us-26-27-team-lifecycle` (shared with US-26)

---

## Story

**As a** team owner  
**I want** team deletion to be wrapped in a database transaction, with the team immediately marked suspended before the cascade begins  
**So that** concurrent cron jobs cannot send emails to a team that is being deleted, and a failed partial delete is rolled back cleanly

---

## Acceptance Criteria

### AC-1 — `deleteTeam()` wrapped in a transaction

```php
function deleteTeam(PDO $pdo, int $teamId): void
{
    $pdo->beginTransaction();
    try {
        // Step 0 — suspend first (blocks concurrent getAllTeams() from picking this team)
        $pdo->prepare("UPDATE teams SET status = 'suspended' WHERE id = ?")
            ->execute([$teamId]);

        // Steps 1–9: cascade deletes (see AC-2 for corrected syntax)
        // ...

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

The `UPDATE ... SET status = 'suspended'` is step 0 — the very first SQL inside the transaction. Any concurrent `getAllTeams()` call (which filters `WHERE status = 'active'`) will skip this team from that moment forward, even before the cascade completes.

---

### AC-2 — MySQL JOIN-delete syntax replaced with portable subqueries

**Step 1 — `standup_answers`**:

```php
// BEFORE (MySQL-only):
$pdo->prepare('
    DELETE a FROM standup_answers a
    JOIN standup_submissions ss ON ss.id = a.submission_id
    JOIN standup_tokens t ON t.id = ss.token_id
    WHERE t.team_id = ?
')->execute([$teamId]);

// AFTER (portable subquery):
$pdo->prepare('
    DELETE FROM standup_answers WHERE submission_id IN (
        SELECT ss.id FROM standup_submissions ss
        WHERE ss.token_id IN (
            SELECT id FROM standup_tokens WHERE team_id = ?
        )
    )
')->execute([$teamId]);
```

**Step 2 — `standup_submissions`**:

```php
// BEFORE (MySQL-only):
$pdo->prepare('
    DELETE ss FROM standup_submissions ss
    JOIN standup_tokens t ON t.id = ss.token_id
    WHERE t.team_id = ?
')->execute([$teamId]);

// AFTER (portable subquery):
$pdo->prepare('
    DELETE FROM standup_submissions WHERE token_id IN (
        SELECT id FROM standup_tokens WHERE team_id = ?
    )
')->execute([$teamId]);
```

Steps 3–9 are already single-table `DELETE FROM ... WHERE team_id = ?` — no change needed.

---

### AC-3 — Cross-team recipient preserved after deletion

`team_recipients` rows are scoped to a single `team_id`. `deleteTeam()` step 5 deletes `WHERE team_id = ?` — only that team's rows. A recipient who also belongs to another team (via a separate `team_recipients` row with a different `team_id`) is not affected.

No code change required here — this AC confirms the existing step 5 is already correct and must not be changed.

---

### AC-4 — PHPUnit tests

New test class `tests/TeamDeletionHardeningTest.php`:

| Test | Assertion |
|---|---|
| `testDeleteTeamRemovesAllRelatedData` | After `deleteTeam()`, `SELECT COUNT(*) FROM teams WHERE id = ?` = 0; `standup_answers`, `standup_submissions`, `standup_tokens`, `summary_sent`, `team_recipients`, `team_questions`, `invitations`, `team_members` all have 0 rows for that team |
| `testDeleteTeamDoesNotAffectOtherTeam` | Create 2 teams; delete team 1; team 2 and all its data still intact |
| `testCrossTeamRecipientPreserved` | Create 2 teams; add recipient row to both; delete team 1; recipient row for team 2 still exists |
| `testDeleteTeamSubquerySyntaxWorksOnSQLite` | Same as `testDeleteTeamRemovesAllRelatedData` — this test inherently validates the subquery syntax since tests run on SQLite |

All tests use the SQLite in-memory test database (`createTestPdo()`).

**Note**: `testDeleteTeamSubquerySyntaxWorksOnSQLite` is effectively the same as `testDeleteTeamRemovesAllRelatedData` — it exists as an explicitly named test to document the SQLite-compatibility intent. Consolidate into a single test with a descriptive name if preferred.

---

### AC-5 — All 70 prior tests still pass

Including the 4 new US-26 tests (total target: 70 + 4 + 4 = 78 after both US-26 and US-27).

---

## Files Changed

| File | Change |
|---|---|
| `src/TeamRepository.php` | Wrap `deleteTeam()` in transaction; add step 0 (`SET status = 'suspended'`); replace JOIN-delete steps 1–2 with subqueries |
| `tests/TeamDeletionHardeningTest.php` (new) | 4 PHPUnit tests |

---

## Dependency on US-26

US-27's step 0 (`SET status = 'suspended'`) requires the `teams.status` column, which is added by US-26. **US-26 schema migration must be applied before US-27's `deleteTeam()` change is deployed.** On the shared branch both land together — no sequencing issue in practice.
