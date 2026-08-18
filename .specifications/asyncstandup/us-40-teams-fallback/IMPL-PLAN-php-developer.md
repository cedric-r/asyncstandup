# IMPL-PLAN — US-40: Fallback, Logging & Admin Visibility

**Status**: PENDING GATE C APPROVAL
**Branch**: `feature/us-40-teams-fallback`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-40 — Fallback, Logging & Admin Visibility

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-6 and TASKS.md T-1 through T-14.

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `SummaryEmailer.php` webhook failure | Line 315: bare `error_log(...)` when `!$success`; `return` on success (line ~320). `$pdo` is in scope (param of `sendSummaryEmail()`). No `require_once TeamRepository.php` — must add |
| `StandupEmailer.php` DM failure | Line 147: bare `error_log(...)` when `!$sent`; `return` on success (line ~144). `$pdo` is in scope (param of `sendStandupPrompt()`). No `require_once TeamRepository.php` — must add |
| `public/admin/index.php` | Redirects to `users.php` via `Location` header — not a nav page, just a redirect. Add `teams.php` link inside `users.php`'s nav, or create standalone `teams.php` with its own nav consistent with `users.php` layout |
| `public/admin/users.php` auth pattern | `requireAdmin($pdo)` call after `startSession()` + `getDb($config)` |
| Schema: `tests/schema-sqlite.sql` | Need `teams_last_error TEXT NULL` + `teams_last_error_at TEXT NULL` inside `CREATE TABLE teams (...)` — before closing `)` |

---

## Files to Create / Change

| File | Change |
|---|---|
| `db/schema.sql` | Append `ALTER TABLE teams ADD COLUMN teams_last_error/teams_last_error_at` |
| `db/schema-postgresql.sql` | Same with `ADD COLUMN IF NOT EXISTS` + `TIMESTAMP` |
| `tests/schema-sqlite.sql` | Add both columns to `CREATE TABLE teams` |
| `src/TeamRepository.php` | Add `recordTeamsError()`, `clearTeamsError()`, `getTeamsAdminOverview()` |
| `src/SummaryEmailer.php` | `require_once TeamRepository.php`; replace bare `error_log` with `recordTeamsError()`; add `clearTeamsError()` on success |
| `src/StandupEmailer.php` | Same — bot DM failure path |
| `public/admin/teams.php` (new) | Teams integration admin overview page |
| `public/admin/index.php` | Add `teams.php` redirect or nav link |
| `tests/TeamsFallbackTest.php` (new) | 3 tests |
| `tests/bootstrap.php` | No change needed (TeamRepository already required) |

---

## Task Sequence

### T-1 — Branch (done)

### T-2 — `db/schema.sql`

Append:
```sql
-- US-40: Teams delivery error tracking
ALTER TABLE teams ADD COLUMN teams_last_error    VARCHAR(255) NULL;
ALTER TABLE teams ADD COLUMN teams_last_error_at DATETIME    NULL;
```

### T-3 — `db/schema-postgresql.sql`

Append:
```sql
-- US-40: Teams delivery error tracking
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_last_error    VARCHAR(255) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_last_error_at TIMESTAMP   NULL;
```

### T-4 — `tests/schema-sqlite.sql`

Add both columns inside `CREATE TABLE teams (...)` before the closing `)`:
```sql
    teams_last_error    TEXT NULL,
    teams_last_error_at TEXT NULL,
```
Run suite immediately after to confirm no breakage.

### T-5 — `src/TeamRepository.php`: `recordTeamsError()` + `clearTeamsError()`

Per STORY.md AC-2. `gmdate('Y-m-d H:i:s')` for UTC timestamp. `mb_substr($message, 0, 255)` to fit column.

### T-6 — `src/TeamRepository.php`: `getTeamsAdminOverview()`

Per STORY.md AC-4. JOIN `organisations`. ORDER BY `o.name, t.name`.

### T-7 — `src/SummaryEmailer.php`

Add `require_once __DIR__ . '/TeamRepository.php';` at top.

Replace (around line 315):
```php
error_log("[AsyncStandUp] Teams webhook failed for team {$teamId} — falling back to email");
// Fall through to email sending below.
} else {
    return; // Teams posting succeeded — no email needed.
}
```
With:
```php
$errMsg = "Teams webhook failed for team {$teamId}";
error_log("[AsyncStandUp] {$errMsg} — falling back to email");
recordTeamsError($pdo, (int) $teamId, $errMsg);
// Fall through to email sending below.
} else {
    clearTeamsError($pdo, (int) $teamId);
    return; // Teams posting succeeded — no email needed.
}
```

### T-8 — `src/StandupEmailer.php`

Add `require_once __DIR__ . '/TeamRepository.php';` at top.

Replace (around line 147):
```php
if ($sent) {
    return; // DM sent successfully — skip email.
}
error_log("[AsyncStandUp] Teams DM failed for user {$member['id']} team {$team['id']} — falling back to email");
```
With:
```php
if ($sent) {
    clearTeamsError($pdo, (int) $team['id']);
    return; // DM sent successfully — skip email.
}
$dmErrMsg = "Teams DM failed for user {$member['id']} team {$team['id']}";
error_log("[AsyncStandUp] {$dmErrMsg} — falling back to email");
recordTeamsError($pdo, (int) $team['id'], $dmErrMsg);
```

### T-9/T-10 — `public/admin/teams.php`

Auth guard: `requireAdmin($pdo)`. Call `getTeamsAdminOverview($pdo)`. Render table per STORY.md AC-4. Require chain matches `users.php`.

Nav: add `<a href="/admin/teams.php">Teams Integration</a>` link at top of the page and add the same link to `public/admin/index.php` before the redirect (or add it to the `users.php` template nav).

### T-11 — `public/admin/index.php`

Since it's a pure redirect, update it to also include a link or change it to a real nav page. Simplest: add a second redirect option, OR — since `admin/index.php` just redirects — add a `teams.php` link in the `users.php` nav template instead.

### T-12 — `tests/TeamsFallbackTest.php`

3 tests per TASKS.md T-12. SQLite compat: `created_by = 1` in team INSERT.

### T-13 — Quality gate

Target: ≥127 tests (124 + 3), all pass; phpstan level 5 — 0 errors.

PHPStan risk: `$teamId` in `SummaryEmailer` may be `int|string` from array — cast to `(int)`.

### T-14 — Commit

```bash
git add db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
        src/TeamRepository.php src/SummaryEmailer.php src/StandupEmailer.php \
        public/admin/teams.php public/admin/index.php \
        tests/TeamsFallbackTest.php \
        .specifications/asyncstandup/us-40-teams-fallback/
git commit -m "feat(us-40): Teams fallback + error tracking + admin visibility"
```
