# US-10: Password Reset Flow

**Feature**: asyncstandup-core  
**Story**: US-10  
**Branch**: `feature/asyncstandup-tests-pwreset`

## User Story

**As a** registered user who has forgotten their password  
**I can** request a password reset link via email and set a new password  
**So that** I can regain access to my account without administrator intervention

## Acceptance Criteria

1. **Given** a registered email address, **When** forgot-password form submitted, **Then** reset token generated and stored; email sent with reset link; flash message shown: "If your email is registered, you will receive a reset link"
2. **Given** an unregistered email address, **When** forgot-password form submitted, **Then** same flash message shown (no email sent, no error revealed — prevents email enumeration)
3. **Given** a valid unused non-expired token, **When** `/reset-password.php?token=X` visited, **Then** form shown (new password + confirm fields)
4. **Given** form submitted with matching passwords (≥ 8 chars), **When** processed, **Then** password updated via `password_hash()`; token marked used; redirected to login with flash "Password updated — please log in"
5. **Given** token older than 1 hour, **When** reset-password visited, **Then** error "Reset link has expired — please request a new one"
6. **Given** token already used, **When** reset-password visited, **Then** error "Reset link already used"
7. **Given** token not found in DB, **When** reset-password visited, **Then** error "Invalid reset link"
8. **Given** passwords do not match, **When** form submitted, **Then** error shown; token not consumed; form re-shown with token preserved
9. **Given** password shorter than 8 characters, **When** form submitted, **Then** error shown; token not consumed
10. **Given** any form, **When** submitted, **Then** CSRF token validated; 403 if missing or invalid

### Change Password (while logged in — `public/profile.php`)

11. **Given** logged-in user on profile page, **When** "Change password" form submitted with correct current password + valid new password (≥ 8 chars) + matching confirm, **Then** password updated via `password_hash()`; flash "Password updated"
12. **Given** incorrect current password entered, **When** submitted, **Then** error "Current password is incorrect"; password unchanged
13. **Given** new password + confirm mismatch, **When** submitted, **Then** error "Passwords do not match"; password unchanged
14. **Given** new password shorter than 8 characters, **When** submitted, **Then** error; password unchanged

## Definition of Done

- [ ] All ACs met
- [ ] No email enumeration: identical response for registered and unregistered emails
- [ ] Tokens: `bin2hex(random_bytes(32))`, 1-hour expiry, UNIQUE in DB
- [ ] Password updated via `password_hash(PASSWORD_BCRYPT)` — never plaintext
- [ ] CSRF on all POST forms
- [ ] Reuses `src/Mailer.php` for email delivery
- [ ] `schema.sql` updated with `password_resets` table
- [ ] Path B: `schema.sql` and `src/Auth.php` already merged — characterisation not required (Auth.php additions are purely additive; schema is additive)

## Files

| Action | File | Risk |
|---|---|---|
| Create | `public/forgot-password.php` | — |
| Create | `public/reset-password.php` | — |
| Create | `templates/email/password_reset.php` | — |
| Modify | `schema.sql` | ⚠️ Path B — additive: new table only |
| Modify | `src/Auth.php` | ⚠️ Path B — additive: new functions only |

## Implementation Details

### New DB table

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

`expires_at` = `created_at + INTERVAL 1 HOUR` — set in PHP: `$now->modify('+1 hour')->format('Y-m-d H:i:s')`.

### New functions in `src/Auth.php`

```php
function createPasswordResetToken(PDO $pdo, int $userId): string {
    $token     = bin2hex(random_bytes(32));
    $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $now->modify('+1 hour')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$userId, $token, $expiresAt]);
    return $token;
}

function findValidResetToken(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM password_resets WHERE token = ?'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    return $row;  // caller validates expiry and used_at
}

function applyPasswordReset(PDO $pdo, int $tokenId, int $userId, string $newPassword): void {
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([$hash, $userId]);
    $pdo->prepare('UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$tokenId]);
    $pdo->commit();
}
```

### `public/forgot-password.php`

**GET**: render form (email input, CSRF token).

**POST**:
1. Validate CSRF
2. Sanitise email input
3. `SELECT id, display_name FROM users WHERE email = ?`
4. If found: `createPasswordResetToken()` → load template → `Mailer::send()`
5. If not found: do nothing (same timing — no early return that reveals existence)
6. Set flash: "If your email is registered, you will receive a reset link"
7. Redirect to `/forgot-password.php` (PRG)

**Timing attack note**: always run the same code path regardless of email existence — avoids measurable timing difference via response time.

### `public/reset-password.php`

**GET**:
1. `$token = $_GET['token'] ?? ''` — show error if empty
2. `findValidResetToken()` — show "Invalid reset link" if null
3. Check `used_at !== null` → "Reset link already used"
4. Check `expires_at` vs `UTC_TIMESTAMP()` → "Reset link has expired"
5. Render form (new password, confirm password, hidden token, CSRF)

**POST**:
1. Validate CSRF
2. Re-load and re-validate token (race condition protection)
3. Validate password ≥ 8 chars → error if not (re-render with token)
4. Validate password === confirm → error if not (re-render)
5. `applyPasswordReset()` — transaction: update password + mark token used
6. Set flash "Password updated — please log in"
7. Redirect to `/login.php` (PRG)

### Email template (`templates/email/password_reset.php`)

Variables (available after `extract()`):
- `$user_name` — display name or email if no display name set
- `$reset_url` — `$config['app']['base_url'] . '/reset-password.php?token=' . $token`
- `$expires_minutes` — `60`

`$subject = 'Reset your AsyncStandUp password';`

Plain-text body includes: greeting, reset link, expiry notice, security note ("If you did not request this, ignore this email").

## Security Notes

- **No email enumeration**: `forgot-password.php` always shows the same flash regardless of email existence — never reveals whether an account exists
- **Token consumed on use**: `used_at` set in same transaction as password update — no window for double-use
- **No plaintext token in logs**: `Mailer.php` SMTP DATA section not logged; only errors logged (no email content)
- **CSRF on reset form**: hidden token prevents CSRF-based forced password reset
- **Re-validation on POST**: token re-loaded on POST submission — protects against race condition where token expires or is used between GET and POST
