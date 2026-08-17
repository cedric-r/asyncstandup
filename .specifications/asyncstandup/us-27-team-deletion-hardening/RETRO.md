# RETRO — US-27: Team Deletion Hardening

**Story**: US-27 — Team Deletion Hardening (transactional cascade, portable SQL, race-condition guard)
**Branch**: `feature/us-26-27-team-lifecycle`
**Merge commit**: `ae34ee2`
**Review cycles**: 1
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `src/TeamRepository.php` | Rewrote `deleteTeam()`: wrapped in `beginTransaction()` / `commit()` / `rollBack()`; added Step 0 (`UPDATE status = 'suspended'`) as race-condition guard; replaced MySQL JOIN-DELETE syntax (steps 1–2) with portable subquery deletes |
| `tests/TeamDeletionHardeningTest.php` | 4 tests: cascade removes all child records; cross-team recipients unaffected; transactional commit leaves DB consistent; `suspendTeam()` blocks team from `getAllTeams()` used by cron |

**Test result**: 78 tests, 160 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle** — Gate D approved on first submission.

---

## Notes

1. **MySQL JOIN-DELETE not portable** — the original `DELETE a FROM standup_answers a JOIN standup_submissions ss … JOIN standup_tokens t …` syntax is MySQL-specific and fails on PostgreSQL and SQLite. Replaced with `DELETE FROM standup_answers WHERE submission_id IN (SELECT ss.id FROM standup_submissions ss WHERE ss.token_id IN (SELECT id FROM standup_tokens WHERE team_id = ?))` — supported by all three drivers.
2. **Step 0 race guard** — marking the team `suspended` before any DELETE ensures that if the cron's `getAllTeams()` runs mid-deletion it will not pick up the team, preventing emails to members whose records are being deleted.
3. **Cross-team recipient safety** — `DELETE FROM team_recipients WHERE team_id = ?` is already scoped to the target team; the cross-team test confirmed that a shared email address in another team's `team_recipients` survives deletion of team 1.
4. **Transaction on already-transactional `createTeam()`** — `createTeam()` already used `beginTransaction()` as the reference pattern; `deleteTeam()` follows the same `try/catch Throwable → rollBack → rethrow` structure.
