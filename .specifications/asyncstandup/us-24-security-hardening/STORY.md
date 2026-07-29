# US-24: Security Hardening (4 MEDIUMs)

**Feature**: asyncstandup-core  
**Story**: US-24  
**Branch**: `feature/asyncstandup-security`

## User Story

**As a** security-conscious operator  
**I can** deploy AsyncStandUp with four medium-severity vulnerabilities resolved  
**So that** the system is hardened against common web attack vectors

## Acceptance Criteria

1. **Given** logged-in user clicks an invitation accept link, **When** page loads, **Then** a confirmation form is shown with team and org name before joining; email mismatch shows a warning
2. **Given** user submits forgot-password form, **When** POST processed, **Then** CAPTCHA must be answered correctly before any token is created or email sent
3. **Given** user requests a password reset token, **When** processed, **Then** all prior unused tokens for that user are invalidated (deleted) before the new token is inserted
4. **Given** user submits 3 or more reset requests within 15 minutes, **When** 3rd+ request arrives, **Then** no new token created; same flash shown ("If your email is registered...") — no enumeration
5. **Given** user submits 5 or more failed login attempts within 10 minutes, **When** 6th attempt arrives, **Then** "Too many failed attempts. Please try again in 5 minutes." — no credentials checked; lock stored server-side
6. **Given** `requireAdmin()` is called, **When** executed, **Then** re-queries DB for `is_admin` + `account_status`; a user de-admin'd since their last login is immediately blocked

## Definition of Done

- [ ] All ACs met
- [ ] Fix 1: invitation accept is GET (show form) → POST (add to team) — no side effects on GET
- [ ] Fix 2A: CAPTCHA on `forgot-password.php` — same `captchaValidate()` as login/register
- [ ] Fix 2B: `createPasswordResetToken()` deletes prior unused tokens in same transaction as INSERT
- [ ] Fix 2C: rate limit check in `createPasswordResetToken()` — ≥ 3 tokens in 15 min → return without creating
- [ ] Fix 3: `login_attempts` table; `loginUser()` checks lockout before credentials; increments on fail; resets on success
- [ ] Fix 4: `requireAdmin(PDO $pdo)` re-queries DB; updates session `is_admin` on mismatch; calls `forbid()`
- [ ] `requireAdmin()` signature change (add `PDO $pdo`) — all callers updated
- [ ] `login_attempts` and schema change in both `schema.sql` and `tests/schema-sqlite.sql`
- [ ] New test cases in `tests/RepositoryTest.php`

## Files

| Action | File | Risk |
|---|---|---|
| Modify | `public/invitations/accept.php` | ⚠️ Path B — GET shows form; POST accepts |
| Modify | `src/Auth.php` | ⚠️ Path B — 3 function changes + 1 new helper |
| Modify | `public/forgot-password.php` | ⚠️ Path B — CAPTCHA added |
| Modify | `public/login.php` | ⚠️ Path B — lockout check |
| Modify | `public/admin/users.php` | ⚠️ Path B — pass `$pdo` to `requireAdmin()` |
| Modify | `db/schema.sql` | ⚠️ Path B — ADD TABLE `login_attempts` |
| Modify | `tests/schema-sqlite.sql` | ⚠️ Path B — add table |

## Implementation Details

---

### Fix 1 — Invitation Accept Confirmation (VULN-001)

**Current problem**: GET immediately adds user to team — a link prefetch or CSRF-based redirect could add a user without intent.

**Revised `public/invitations/accept.php`:**

**GET handler** — show confirmation only:
```php
// 1. Load invitation by token (validate existence, expiry, not-yet-accepted)
// 2. Detect email mismatch (if logged in)
// 3. Render confirmation form — do NOT add to team
```

**POST handler** — add to team:
```php
// 1. Validate CSRF
// 2. Re-load token (re-validate)
// 3. acceptInvitationForUser() → add to team_members
// 4. Redirect to dashboard with flash "You have joined [Team]."
```

**Confirmation form UI:**
```html
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 max-w-lg mx-auto">
    <h1 class="text-xl font-semibold text-gray-900 mb-2">Team Invitation</h1>
    <p class="text-sm text-gray-700 mb-4">
        You have been invited to join <strong><?= h($invitation['team_name']) ?></strong>
        at <strong><?= h($invitation['org_name']) ?></strong>.
    </p>

    <?php if ($emailMismatch): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded px-4 py-3 text-sm mb-4">
        Note: this invitation was sent to <strong><?= h($invitation['invited_email']) ?></strong>.
        You are logged in as <strong><?= h($currentUser['email']) ?></strong>.
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="token"      value="<?= h($token) ?>">
        <div class="flex gap-3">
            <button type="submit" name="action" value="accept"
                class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg">
                Accept invitation
            </button>
            <a href="/dashboard.php"
                class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium py-2 px-4 rounded-lg">
                Decline
            </a>
        </div>
    </form>
</div>
```

Email mismatch detection:
```php
$emailMismatch = isLoggedIn()
    && isset($currentUser['email'])
    && strtolower($currentUser['email']) !== strtolower($invitation['invited_email']);
```

"Decline" is a link to `/dashboard.php` — no DB action; token remains valid for the intended recipient.

---

### Fix 2A — CAPTCHA on `forgot-password.php` (VULN-002)

Same pattern as `login.php` and `register.php` (US-15):

**GET**: `$captcha = captchaGetRandomQuestion();` — include in template.

**POST** (before any token logic):
```php
$captchaAnswer = $_POST['captcha_answer'] ?? '';
if (!captchaValidate($captchaAnswer)) {
    $errors[] = 'Incorrect answer to the security question.';
    $captcha   = captchaGetRandomQuestion();
    // Render form with errors — do NOT proceed to token creation
}
```

---

### Fix 2B — Invalidate Prior Tokens (VULN-002)

In `createPasswordResetToken(PDO $pdo, int $userId): string` (`src/Auth.php`):

```php
function createPasswordResetToken(PDO $pdo, int $userId): string {
    // Check rate limit first
    $window = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $stmt   = $pdo->prepare(
        'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?'
    );
    $stmt->execute([$userId, $window]);
    if ((int) $stmt->fetchColumn() >= 3) {
        return '';  // Caller treats empty string as rate-limited; no token created
    }

    $token     = bin2hex(random_bytes(32));
    $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $now->modify('+1 hour')->format('Y-m-d H:i:s');
    $createdAt = $now->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        // Delete prior unused tokens
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
            ->execute([$userId]);

        // Insert new token
        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token, created_at, expires_at) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $token, $createdAt, $expiresAt]);

        $pdo->commit();
        return $token;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

**Caller update in `forgot-password.php`**:
```php
$token = createPasswordResetToken($pdo, $user['id']);
if ($token === '') {
    // Rate limited — show same generic flash (no enumeration)
} else {
    // Send email with $token
}
// In both cases: set flash "If your email is registered..."
```

---

### Fix 3 — Login Rate Limiting (VULN-003)

**New table:**

```sql
CREATE TABLE IF NOT EXISTS login_attempts (
    email           VARCHAR(255) NOT NULL PRIMARY KEY,
    attempt_count   INT          NOT NULL DEFAULT 0,
    first_attempt_at DATETIME    NOT NULL,
    locked_until    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

SQLite equivalent in `tests/schema-sqlite.sql`:
```sql
CREATE TABLE IF NOT EXISTS login_attempts (
    email            TEXT NOT NULL PRIMARY KEY,
    attempt_count    INTEGER NOT NULL DEFAULT 0,
    first_attempt_at TEXT NOT NULL,
    locked_until     TEXT
);
```

**Helper functions in `src/Auth.php`:**

```php
function isLoginLocked(PDO $pdo, string $email): bool {
    $stmt = $pdo->prepare('SELECT locked_until FROM login_attempts WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['locked_until']) return false;
    return new DateTimeImmutable($row['locked_until']) > new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function recordFailedLogin(PDO $pdo, string $email): void {
    $email     = strtolower(trim($email));
    $nowUtc    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $window    = $nowUtc->modify('-10 minutes')->format('Y-m-d H:i:s');
    $now       = $nowUtc->format('Y-m-d H:i:s');
    $lockUntil = $nowUtc->modify('+5 minutes')->format('Y-m-d H:i:s');

    // Upsert: if no row or first_attempt outside window, reset counter
    $stmt = $pdo->prepare(
        'SELECT attempt_count, first_attempt_at FROM login_attempts WHERE email = ?'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['first_attempt_at'] < $window) {
        // Fresh window
        $pdo->prepare(
            'INSERT INTO login_attempts (email, attempt_count, first_attempt_at, locked_until)
             VALUES (?, 1, ?, NULL)
             ON DUPLICATE KEY UPDATE attempt_count = 1, first_attempt_at = ?, locked_until = NULL'
        )->execute([$email, $now, $now]);
    } else {
        $newCount = (int) $row['attempt_count'] + 1;
        $lock     = $newCount >= 5 ? $lockUntil : null;
        $pdo->prepare(
            'UPDATE login_attempts SET attempt_count = ?, locked_until = ? WHERE email = ?'
        )->execute([$newCount, $lock, $email]);
    }
}

function clearLoginAttempts(PDO $pdo, string $email): void {
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')
        ->execute([strtolower(trim($email))]);
}
```

**SQLite note**: `ON DUPLICATE KEY UPDATE` is MySQL-specific. For SQLite compatibility in tests, use `INSERT OR REPLACE INTO login_attempts ...` — document as DB-dialect difference; production uses MySQL.

**`public/login.php` integration** (before CAPTCHA + credential check):
```php
if (isLoginLocked($pdo, $email)) {
    $errors[] = 'Too many failed attempts. Please try again in 5 minutes.';
    // Do not check credentials or CAPTCHA
} else {
    // Existing: CAPTCHA validate → password_verify
    // On CAPTCHA fail: recordFailedLogin() // optional
    // On password fail: recordFailedLogin()
    // On success: clearLoginAttempts()
}
```

---

### Fix 4 — `requireAdmin()` DB Re-verification (M-1)

**Updated signature**: `requireAdmin(PDO $pdo): void`

```php
function requireAdmin(PDO $pdo): void {
    requireLogin();

    $stmt = $pdo->prepare(
        'SELECT is_admin, account_status FROM users WHERE id = ?'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !(bool) $row['is_admin'] || $row['account_status'] !== 'approved') {
        // Revoke stale session flag
        unset($_SESSION['is_admin']);
        forbid();
    }

    // Keep session flag in sync
    $_SESSION['is_admin'] = true;
}
```

**Caller update** — `public/admin/users.php`:
```php
// Before: requireAdmin();
// After:
requireAdmin($pdo);
```

`$pdo` is already available at the top of `admin/users.php` (loaded from `src/Db.php`).

---

### Tests (`tests/RepositoryTest.php`)

**Fix 2B — Token invalidation:**
- Seed 2 unused password_reset rows for same user; call `createPasswordResetToken()`; assert old rows deleted; new row inserted; `fetchAll()` count = 1

**Fix 2C — Rate limit:**
- Seed 3 password_reset rows for same user within window; call `createPasswordResetToken()`; assert returns `''`; no new row inserted

**Fix 3 — Login rate limit:**
- `recordFailedLogin()` × 5 for same email; assert `locked_until` set; `isLoginLocked()` returns `true`
- Wait (use a past timestamp): `isLoginLocked()` returns `false` after lock window passes
- `clearLoginAttempts()` after 2 failures; `isLoginLocked()` returns `false`

**Fix 4 — `requireAdmin()` is controller-layer only** — not unit-testable without HTTP layer; document as manual-verification test.

## Security Notes

- **Fix 1**: GET side-effect removal — standard OWASP A05:2021 guideline; idempotent GETs prevent accidental joins
- **Fix 2**: CAPTCHA on forgot-password closes bot-driven token-generation attacks; rate limit closes token-exhaustion attacks
- **Fix 3**: Server-side rate limit cannot be bypassed by cookie/session manipulation; IP-based alternative considered but `email` key chosen to avoid IPv6/proxy false-positives
- **Fix 4**: DB re-verification on every admin page request adds one indexed SELECT per request — acceptable overhead; closes the stale-session admin privilege escalation window
- **No new auth tokens issued on fix 4**: session `user_id` is already set; only `is_admin` session flag is corrected
