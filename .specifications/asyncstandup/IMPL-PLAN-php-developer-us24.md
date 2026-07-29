# IMPL-PLAN — PHP Developer
## US-24: Security Hardening (4 MEDIUMs)

**Status**: APPROVED
**Branch**: `feature/asyncstandup-security`
**Agent**: PHP Developer

---

## File List (exhaustive — 9 files)

| Action | File | Path B? | Fix |
|---|---|---|---|
| Modify | `public/invitations/accept.php` | ⚠️ Yes | Fix 1 — GET shows form, POST accepts |
| Modify | `public/forgot-password.php` | ⚠️ Yes | Fix 2A — CAPTCHA |
| Modify | `src/Auth.php` | ⚠️ Yes | Fix 2B+2C — token invalidation+rate limit; Fix 3 helpers; Fix 4 requireAdmin |
| Modify | `public/login.php` | ⚠️ Yes | Fix 3 — lockout check before credentials |
| Modify | `public/admin/users.php` | ⚠️ Yes | Fix 4 — pass $pdo to requireAdmin() |
| Modify | `db/schema.sql` | ⚠️ Yes | Fix 3 — ADD TABLE login_attempts |
| Modify | `tests/schema-sqlite.sql` | ⚠️ Yes — pre-listed | Fix 3 — SQLite login_attempts |
| Modify | `tests/RepositoryTest.php` | ⚠️ Yes — pre-listed | Fix 2B+2C+3 tests |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us24.md` | No |

**Note on branch-ancestry artefacts**: any hot-fix commits on main between US-23 and this branch will appear in the diff — listed here as branch-ancestry, no US-24 scope. (US-23 RETRO lesson applied.)

---

## New/Changed Functions in `src/Auth.php`

### Fix 2B+2C — `createPasswordResetToken()` (refactored)

```
1. Rate-limit check: COUNT unused tokens created in last 15 min for userId
   → if ≥ 3 return '' (caller shows generic flash; no enumeration)
2. In transaction: DELETE unused prior tokens + INSERT new token → commit
   Returns token string on success.
```

### Fix 3 — New helpers

```php
function isLoginLocked(PDO $pdo, string $email): bool
function recordFailedLogin(PDO $pdo, string $email): void
function clearLoginAttempts(PDO $pdo, string $email): void
```

`recordFailedLogin()`: upsert via DELETE+INSERT (cross-DB compatible for tests); 5+ failures → set `locked_until = +5 min`; window = 10 min.

### Fix 4 — `requireAdmin(PDO $pdo): void` (signature change)

Re-queries `SELECT is_admin, account_status FROM users WHERE id = ?` on every call. Updates `$_SESSION['is_admin']`; calls `forbid()` if not admin or not approved.

---

## `login_attempts` Schema

```sql
-- MySQL (db/schema.sql):
CREATE TABLE IF NOT EXISTS login_attempts (
    email            VARCHAR(255) NOT NULL PRIMARY KEY,
    attempt_count    INT          NOT NULL DEFAULT 0,
    first_attempt_at DATETIME     NOT NULL,
    locked_until     DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SQLite (tests/schema-sqlite.sql):
CREATE TABLE IF NOT EXISTS login_attempts (
    email            TEXT    NOT NULL PRIMARY KEY,
    attempt_count    INTEGER NOT NULL DEFAULT 0,
    first_attempt_at TEXT    NOT NULL,
    locked_until     TEXT
);
```

---

## SQLite Compatibility Note

`recordFailedLogin()` uses DELETE+INSERT (fresh window) or UPDATE (existing window) — avoids `ON DUPLICATE KEY UPDATE` which is MySQL-only. Tests use in-memory SQLite; this pattern works on both.

---

## Test Plan — `tests/RepositoryTest.php`

| # | Method | Covers |
|---|---|---|
| 1 | `testCreatePasswordResetToken_DeletesPriorUnusedTokens` | Fix 2B — 2 old tokens deleted; 1 new created |
| 2 | `testCreatePasswordResetToken_RateLimitReturnsEmpty` | Fix 2C — 3 tokens in 15 min → returns '' |
| 3 | `testRecordFailedLogin_LocksAfter5Attempts` | Fix 3 — 5 calls → locked_until set; isLoginLocked() = true |
| 4 | `testClearLoginAttempts_UnlocksUser` | Fix 3 — clearLoginAttempts() → isLoginLocked() = false |

---

## Self-Check

- [ ] Fix 1: GET is idempotent (no DB writes); POST has CSRF + acceptInvitationForUser()
- [ ] Fix 2A: captchaValidate() on forgot-password.php POST before token creation
- [ ] Fix 2B: DELETE old unused tokens + INSERT new token in single transaction
- [ ] Fix 2C: ≥ 3 tokens in 15 min → return '' (no enumeration)
- [ ] Fix 3: lockout check BEFORE CAPTCHA + credentials in login.php
- [ ] Fix 3: clearLoginAttempts() on successful login
- [ ] Fix 4: requireAdmin(PDO $pdo) — single caller updated
- [ ] Schema + SQLite schema updated for login_attempts
- [ ] 62 existing tests still pass
