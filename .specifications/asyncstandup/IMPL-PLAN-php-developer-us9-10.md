# IMPL-PLAN — PHP Developer
## US-9: PHPUnit PHAR Test Suite + US-10: Password Reset

**Status**: APPROVED
**Branch**: `feature/asyncstandup-tests-pwreset`
**Agent**: PHP Developer

---

## File List (exhaustive)

Every file created or modified on this branch.

### US-9 — PHPUnit PHAR Test Suite

| Action | File | Path B? |
|---|---|---|
| Create | `tests/bootstrap.php` | No |
| Create | `tests/phpunit.xml` | No |
| Create | `tests/schema-sqlite.sql` | No |
| Create | `tests/StandupEmailerTest.php` | No |
| Create | `tests/SummaryEmailerTest.php` | No |
| Create | `tests/RepositoryTest.php` | No |
| Create | `tests/InvitationCharacterisationTest.php` | No — Path B sequence artefact |
| Modify | `src/InvitationRepository.php` | ⚠️ Yes — Path B; `$now` parameter extraction |
| Modify | `README.md` | No — PHPUnit PHAR download instructions |

`tests/phpunit.phar` — **not committed** (see PHPUnit PHAR strategy below).

### US-10 — Password Reset

| Action | File | Path B? |
|---|---|---|
| Create | `public/forgot-password.php` | No |
| Create | `public/reset-password.php` | No |
| Create | `templates/email/password_reset.php` | No |
| Modify | `db/schema.sql` | ⚠️ Yes — additive: new table appended |
| Modify | `src/Auth.php` | ⚠️ Yes — additive: 3 new functions appended |

### IMPL-PLAN

| Action | File |
|---|---|
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us9-10.md` |

**No other files will be created.** If an unplanned file is required: STOP; create `PLAN-AMENDMENT-N.md`; notify Team Lead.

---

## PHPUnit PHAR Strategy

**Decision**: document as a manual one-line download; **do not commit the binary**.

**Justification**: Committing a ~3–5 MB binary permanently inflates every future `git clone`. The PHAR URL is stable; a checksum in the README provides the same integrity guarantee without the repo size cost. This is consistent with the project's `vendor/`-not-committed philosophy from US-5.

**README instruction** (to be added):
```bash
# Download PHPUnit PHAR (one-time setup):
wget https://phar.phpunit.de/phpunit-10.phar -O tests/phpunit.phar
# Verify checksum (from https://phar.phpunit.de/phpunit-10.phar.sha256asc):
# sha256sum -c tests/phpunit.phar.sha256asc
```

Add `tests/phpunit.phar` to `.gitignore`.

**Run command**: `php tests/phpunit.phar --configuration tests/phpunit.xml`

---

## TDD Path Declarations

| File | Path | Rationale |
|---|---|---|
| `src/InvitationRepository.php` | **Path B** | Already on `main`; `$now` extraction changes function signature |
| `src/Auth.php` | **Path B** | Already on `main`; purely additive (3 new functions at end of file) — no characterisation commit required (STORY.md §Definition of Done) |
| `db/schema.sql` | **Path B** | Already on `main`; purely additive (new table appended) — no characterisation required |
| All other new files | **Path A** | New files |

**Note on US-10 Path B files**: both `src/Auth.php` and `db/schema.sql` are modified additively — no existing function or table is touched. Characterisation is explicitly waived per STORY.md. The risk is the same as adding a new file.

---

## InvitationRepository.php — `$now` Extraction (Path B)

**Before** (current merged code):
```php
function acceptInvitationForUser(PDO $pdo, string $token, int $userId): bool
{
    // ...
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // ...
}
```

**After** (one-line change, no behaviour change at existing call sites):
```php
function acceptInvitationForUser(
    PDO $pdo,
    string $token,
    int $userId,
    ?DateTimeImmutable $now = null
): bool {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // rest unchanged
}
```

All existing call sites (`accept.php`, `register.php`) pass 3 arguments — unaffected by the optional 4th parameter.

---

## New Functions in `src/Auth.php` (US-10)

```php
function createPasswordResetToken(PDO $pdo, int $userId): string
```
Generates `bin2hex(random_bytes(32))` token; computes `expires_at = now + 1 hour` in PHP; inserts into `password_resets`. Returns token string.

---

```php
function findValidResetToken(PDO $pdo, string $token): ?array
```
`SELECT * FROM password_resets WHERE token = ?`. Returns row or null. Caller validates `used_at` and `expires_at`.

---

```php
function applyPasswordReset(PDO $pdo, int $tokenId, int $userId, string $newPassword): void
```
Transaction: `UPDATE users SET password_hash = ?` + `UPDATE password_resets SET used_at = UTC_TIMESTAMP()`. Both or neither.

---

## New DB Table (US-10)

Appended to `db/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## Test Method Names & Assertions

### `tests/InvitationCharacterisationTest.php` — 2 cases (Path B artefact)

Pins PRESERVED behaviours before the `$now` extraction. Both assertions remain true after the optional parameter is added (US-7 lesson: pin preserved behaviours, not absent code).

| # | Method | Assertion |
|---|---|---|
| 1 | `testValidTokenReturnsTrue` | Valid token → `acceptInvitationForUser()` returns `true`; `team_members` row inserted; `accepted_at` set |
| 2 | `testAlreadyAcceptedTokenReturnsFalse` | Already-accepted token → returns `false`; no second `team_members` row |

### `tests/StandupEmailerTest.php` — 6 cases

Team fixture: `['standup_time' => '09:00:00', 'timezone' => 'Europe/London']` (UTC+0 in winter).

| # | Method | nowUtc input | Expected |
|---|---|---|---|
| 1 | `testExactMatchReturnsTrue` | 09:00:00 UTC | `true` |
| 2 | `testOneSecondBeforeWindowReturnsFalse` | 08:58:59 UTC | `false` |
| 3 | `test59SecondsBeforeWindowReturnsTrue` | 08:59:01 UTC | `true` |
| 4 | `test59SecondsAfterWindowReturnsTrue` | 09:00:59 UTC | `true` |
| 5 | `test60SecondsAfterWindowReturnsFalse` | 09:01:00 UTC | `false` |
| 6 | `testDifferentTimezoneReturnsTrue` | 14:00:00 UTC (team: `America/New_York`, standup `09:00`, winter offset -5h) | `true` |

Window logic under test: `abs(diff) < 60` — both boundary values (59s = inside, 60s = outside).

### `tests/SummaryEmailerTest.php` — 6 cases

Same team fixture. Each `nowUtc` is the corresponding `StandupEmailerTest` value `+1 hour`. All 6 expected results identical.

| # | Method | nowUtc input | Expected |
|---|---|---|---|
| 1 | `testExactMatchReturnsTrue` | 10:00:00 UTC | `true` |
| 2 | `testOneSecondBeforeWindowReturnsFalse` | 09:58:59 UTC | `false` |
| 3 | `test59SecondsBeforeWindowReturnsTrue` | 09:59:01 UTC | `true` |
| 4 | `test59SecondsAfterWindowReturnsTrue` | 10:00:59 UTC | `true` |
| 5 | `test60SecondsAfterWindowReturnsFalse` | 10:01:00 UTC | `false` |
| 6 | `testDifferentTimezoneReturnsTrue` | 15:00:00 UTC | `true` |

### `tests/RepositoryTest.php` — 7 cases

Each test creates a fresh PDO via `createTestPdo()` in `setUp()` — no shared state.

| # | Method | Function | Assertion |
|---|---|---|---|
| 1 | `testSaveSubmissionHappyPath` | `saveSubmission()` | 1 row in `standup_submissions`; 2 rows in `standup_answers` (one per question); `used_at` set on token |
| 2 | `testSaveSubmissionRollsBackOnFailure` | `saveSubmission()` | Corrupt `question_id` (FK violation) → transaction rolled back; 0 rows in `standup_submissions` |
| 3 | `testAssembleSummaryDataSubmitterAndNonSubmitter` | `assembleSummaryData()` | 1 developer in `submissions` with correct answers; 1 developer in `non_submitters` |
| 4 | `testAcceptInvitationForUserValidToken` | `acceptInvitationForUser()` | Returns `true`; `team_members` row exists; `accepted_at` set |
| 5 | `testAcceptInvitationForUserAlreadyAccepted` | `acceptInvitationForUser()` | Returns `false` |
| 6 | `testAcceptInvitationForUserExpired` | `acceptInvitationForUser($pdo, $token, $userId, $now)` | `$now` = `created_at + 8 days`; returns `false`; no `team_members` row |
| 7 | `testDeleteOrgFullCascade` | `deleteOrg()` | Seeds org → team → member → question → token → submission → answer → summary_sent → recipient → invitation → org_member; all removed; no PDO exception |

---

## `tests/bootstrap.php` — Key Implementation Notes

```php
function createTestPdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');   // catch FK violation bugs
    $sql = file_get_contents(__DIR__ . '/schema-sqlite.sql');
    $pdo->exec($sql);
    return $pdo;
}
```

`require_once` for every src file used by tests:
- `src/StandupEmailer.php`, `src/SummaryEmailer.php`
- `src/InvitationRepository.php`, `src/OrgRepository.php`, `src/SubmissionRepository.php`

**No `require_once` for `src/Auth.php`** (contains exception handler + `ini_set` at file scope that would suppress PHPUnit's error reporting).

---

## `tests/schema-sqlite.sql` — MySQL → SQLite conversions

Derived from `db/schema.sql`. Transformations applied per table:
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` → removed
- `INT UNSIGNED` → `INTEGER`
- `TINYINT(1)` → `INTEGER`
- `AUTO_INCREMENT PRIMARY KEY` → `INTEGER PRIMARY KEY AUTOINCREMENT`
- `DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())` → `TEXT NOT NULL DEFAULT ''` (set in PHP or test seeding)
- `DATE NOT NULL` → `TEXT NOT NULL`
- `TIME NOT NULL` → `TEXT NOT NULL`
- `VARCHAR(n)` → `TEXT`
- `UNIQUE KEY uq_... (col1, col2)` → converted to `UNIQUE(col1, col2)` inline or as table constraint
- `FOREIGN KEY ... REFERENCES ...` — kept as SQLite syntax (enforced via `PRAGMA foreign_keys = ON`)
- `SET NAMES utf8mb4` / `SET foreign_key_checks` → removed

---

## `tests/phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="bootstrap.php" colors="true" failOnWarning="true">
    <testsuites>
        <testsuite name="AsyncStandUp">
            <directory>.</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Run from project root: `php tests/phpunit.phar --configuration tests/phpunit.xml`

---

## Ordered Commit Sequence

### Commit 1 — IMPL-PLAN
```
chore(us9-10): add IMPL-PLAN-php-developer-us9-10.md — Status: DRAFT
```

### Commit 2 — US-9 scaffold
```
chore(us-9): add test scaffold — bootstrap, phpunit.xml, schema-sqlite.sql, .gitignore + README
```
Files: `tests/bootstrap.php`, `tests/phpunit.xml`, `tests/schema-sqlite.sql`, `.gitignore` (add `tests/phpunit.phar`), `README.md`

### Commit 3 — Path B characterisation (US-9)
```
test(us-9): characterise acceptInvitationForUser before $now parameter extraction
```
Files: `tests/InvitationCharacterisationTest.php`
**Run `php tests/phpunit.phar ...` before proceeding** — all characterisation tests must pass.

### Commit 4 — Path B production change (US-9)
```
refactor(us-9): extract $now parameter from acceptInvitationForUser in InvitationRepository.php
```
Files: `src/InvitationRepository.php`

### Commit 5 — Full test suite (US-9)
```
test(us-9): add StandupEmailerTest, SummaryEmailerTest, RepositoryTest
```
Files: `tests/StandupEmailerTest.php`, `tests/SummaryEmailerTest.php`, `tests/RepositoryTest.php`

### Commit 6 — Schema migration (US-10)
```
feat(us-10): add password_resets table to schema.sql
```
Files: `db/schema.sql`

### Commit 7 — Auth functions (US-10)
```
feat(us-10): add createPasswordResetToken, findValidResetToken, applyPasswordReset to Auth.php
```
Files: `src/Auth.php`

### Commit 8 — Password reset pages (US-10)
```
feat(us-10): forgot-password, reset-password pages, password_reset email template
```
Files: `public/forgot-password.php`, `public/reset-password.php`, `templates/email/password_reset.php`

---

## Self-Check Before Signalling READY FOR REVIEW

```bash
php tests/phpunit.phar --configuration tests/phpunit.xml
# All tests pass, exit 0
```

- [ ] PHPUnit phar listed in `.gitignore` — not committed
- [ ] `declare(strict_types=1)` in all new PHP files
- [ ] CSRF validated on `forgot-password.php` POST and `reset-password.php` POST
- [ ] No email enumeration in `forgot-password.php` — same flash for known/unknown email
- [ ] `applyPasswordReset()` uses transaction
- [ ] `password_hash(PASSWORD_BCRYPT)` — no plaintext
- [ ] Token re-validated on `reset-password.php` POST (race-safe)
- [ ] All user-facing output uses `htmlspecialchars()`
- [ ] No `var_dump`/`print_r`/`die` in production files
- [ ] Characterisation commit `(3)` precedes InvitationRepository modification `(4)` in branch history
