# US-17: Admin Role + Registration Approval + User Management

**Feature**: asyncstandup-core  
**Story**: US-17  
**Branch**: `feature/asyncstandup-admin`

## User Story

**As an** administrator  
**I can** approve or reject new user registrations and manage admin flags  
**So that** only vetted users can access the system

## Acceptance Criteria

1. **Given** new user completes registration, **When** submitted, **Then** `account_status = 'pending'`; user sees "Account pending approval. You will be notified when approved." — not logged in
2. **Given** pending user enters correct credentials, **When** login submitted, **Then** login rejected with "Your account is awaiting administrator approval."
3. **Given** rejected user enters correct credentials, **When** login submitted, **Then** login rejected with "Your account was not approved. Contact administrator." (AC notes: rejected = user record deleted; email freed for re-registration; this message shown only if record survives — in practice, rejected users are deleted so they see the normal "Invalid email or password" message; spec the deleted-record behaviour)
4. **Given** admin visits `/admin/users.php`, **When** loaded, **Then** sees list of all users sorted: pending first, then approved, then rejected; columns: email, display name, status, is_admin, registered date, actions
5. **Given** admin approves a pending user, **When** action confirmed (POST), **Then** `account_status = 'approved'`; approval email sent to user; admin redirected back to list
6. **Given** admin rejects a pending user, **When** action confirmed (POST), **Then** user record deleted; email freed; no email sent to user; admin redirected back with flash "User rejected and removed."
7. **Given** admin toggles the admin flag on another user, **When** action confirmed (POST), **Then** `is_admin` flipped (1↔0); admin cannot remove their own admin flag (action blocked with error)
8. **Given** non-admin visits `/admin/users.php`, **When** loaded, **Then** `requireAdmin()` → `forbid()` → HTTP 403
9. **Given** a new user completes registration, **When** registration succeeds, **Then** a notification email is sent to all `is_admin = 1` users informing them of the new registrant's display name and email address, with a link to `/admin/users.php`

## Definition of Done

- [ ] All ACs met
- [ ] Schema: `is_admin TINYINT(1) NOT NULL DEFAULT 0` and `account_status VARCHAR(10) NOT NULL DEFAULT 'pending'` added to `users`
- [ ] Migration: existing users in DB set to `account_status = 'approved'` via `UPDATE users SET account_status = 'approved'` in schema migration notes / README
- [ ] `requireAdmin()` in `src/Auth.php`: checks `$_SESSION['is_admin'] === true` (set on login); calls `forbid()` if not
- [ ] `account_status` checked in login flow before password verify to provide correct error messages
- [ ] Admin page: all state changes via POST (not GET) with CSRF token
- [ ] Approval email via `Mailer.php`; template: `templates/email/account_approved.php`
- [ ] README documents first-admin setup query
- [ ] Admin cannot de-admin themselves (AC-7 safeguard)

## Files

| Action | File | Risk |
|---|---|---|
| Create | `public/admin/users.php` | — |
| Create | `public/admin/index.php` | — (redirect to users.php) |
| Create | `templates/email/account_approved.php` | — |
| Create | `templates/email/admin_new_registration.php` | — |
| Modify | `db/schema.sql` | ⚠️ Path B — add 2 columns; migration note |
| Modify | `src/Auth.php` | ⚠️ Path B — `requireAdmin()`, login status check, register sets pending |
| Modify | `public/login.php` | ⚠️ Path B — handle pending/rejected messages |
| Modify | `public/register.php` | ⚠️ Path B — show pending message after registration (no auto-login) |
| Modify | `README.md` | — first-admin instructions |

## Implementation Details

### Schema additions (`db/schema.sql`)

Add to `CREATE TABLE users`:
```sql
is_admin       TINYINT(1)   NOT NULL DEFAULT 0,
account_status VARCHAR(10)  NOT NULL DEFAULT 'pending'
                            CHECK (account_status IN ('pending', 'approved', 'rejected'))
```

**Migration for existing deployments** (document in README):
```sql
ALTER TABLE users
    ADD COLUMN is_admin       TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN account_status VARCHAR(10) NOT NULL DEFAULT 'pending';

-- Approve all existing users so they are not locked out
UPDATE users SET account_status = 'approved';
```

### Login status check in `src/Auth.php` / `public/login.php`

Update login flow:

```php
$user = findUserByEmail($pdo, $email);

if (!$user || !password_verify($password, $user['password_hash'])) {
    $errors[] = 'Invalid email or password.';  // generic — no account enumeration
} elseif ($user['account_status'] === 'pending') {
    $errors[] = 'Your account is awaiting administrator approval.';
} elseif ($user['account_status'] === 'rejected') {
    // Rejected users are deleted — this branch fires if record survives (edge case)
    $errors[] = 'Your account was not approved. Contact administrator.';
} else {
    // Approved — start session
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['is_admin'] = (bool) $user['is_admin'];
    session_regenerate_id(true);
    header('Location: /dashboard.php');
    exit;
}
```

`$_SESSION['is_admin']` set on login — avoids per-request DB lookup.

### Register flow change (`public/register.php`)

After INSERT, do NOT start session. Show message:
```
Account created. Your registration is pending administrator approval.
You will receive an email when your account is approved.
```

Redirect to `/login.php` (or render in place). No auto-login.

### `requireAdmin()` in `src/Auth.php`

```php
function requireAdmin(): void {
    requireLogin();  // also handles unauthenticated redirect
    if (empty($_SESSION['is_admin'])) {
        forbid();
    }
}
```

### `public/admin/users.php`

```php
requireAdmin();

// Load users sorted: pending first, approved, rejected
$users = $pdo->query("
    SELECT id, email, display_name, account_status, is_admin, created_at
    FROM users
    ORDER BY
        CASE account_status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
```

**Actions** (all POST with CSRF):

| Action | Endpoint | Effect |
|---|---|---|
| Approve | `?action=approve&user_id=N` | `UPDATE users SET account_status='approved'`; send approval email |
| Reject | `?action=reject&user_id=N` | `DELETE FROM users WHERE id=N` (record removed; email freed) |
| Toggle admin | `?action=toggle_admin&user_id=N` | Flip `is_admin`; block if target == `$_SESSION['user_id']` |

All actions via POST form (not GET link) to prevent CSRF + accidental activation via link prefetch. Use small forms with hidden action field and CSRF token per row.

### Admin notification email template (`templates/email/admin_new_registration.php`)

Variables: `$registrant_name`, `$registrant_email`, `$admin_url`, `$app_name`

`$subject = "[{$app_name}] New user registration pending approval";`

Plain-text body: notification that a new user has registered; registrant name and email; link to `$admin_url` (`/admin/users.php`) to approve or reject.

Sent immediately after INSERT during registration — before showing the pending message to the user. Looped per admin: `SELECT email, display_name FROM users WHERE is_admin = 1`.

On SMTP failure for any admin: log to `logs/standup-errors.log`; do not abort the registration.

### Approval email template (`templates/email/account_approved.php`)

Variables: `$user_name`, `$login_url`, `$app_name`

`$subject = "Your {$app_name} account has been approved";`

Plain-text body: greeting; "Your account has been approved"; login URL.

### AC-3 clarification: rejected users

Reject action = `DELETE FROM users WHERE id = ?`. The user record is removed. If a rejected user tries to log in afterward, they see the generic "Invalid email or password." message (no record found). AC-3 message ("Your account was not approved") only applies if the record survives (not used in practice). Document this in the story notes.

### Session `is_admin` flag maintenance

When an admin toggles their own flag (blocked by safeguard), no change occurs. When another user's admin flag is toggled, that user's **next login** will pick up the new value. Existing sessions are not invalidated (acceptable trade-off for this scope; document in README as known limitation).

### First-admin setup (README documentation)

```
# After running schema.sql and creating your first account:
mysql -u root -p asyncstandup -e "UPDATE users SET is_admin = 1, account_status = 'approved' WHERE email = 'your@email.com';"
```

Or for SQLite:
```
sqlite3 asyncstandup.db "UPDATE users SET is_admin = 1, account_status = 'approved' WHERE email = 'your@email.com';"
```

## Security Notes

- **Admin self-de-admin prevention**: check `(int)$_POST['user_id'] !== (int)$_SESSION['user_id']` before toggle; return error if same
- **CSRF on all admin actions**: each action row has its own `<form>` with CSRF token — no GET-based state changes
- **No email enumeration on login**: "Invalid email or password" is the generic error; specific messages only shown for `account_status` fields (not whether the email exists)
- **`$_SESSION['is_admin']` flag**: set at login time; tamper-proof (server-side session); no client cookie manipulation
- **Reject = delete**: rejected users cannot log in because their record is gone — the most secure approach (no persistent rejected state)
