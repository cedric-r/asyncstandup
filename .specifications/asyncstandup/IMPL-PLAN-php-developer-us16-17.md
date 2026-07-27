# IMPL-PLAN — PHP Developer
## US-16: Delete Own Account + US-17: Admin Role + Registration Approval

**Status**: APPROVED
**Branch**: `feature/asyncstandup-admin`
**Agent**: PHP Developer

---

## File List (exhaustive — 11 files)

| Action | File | Path B? | Story |
|---|---|---|---|
| Modify | `src/Auth.php` | ⚠️ Yes | US-16 + US-17 (additive) |
| Modify | `public/profile.php` | ⚠️ Yes | US-16 — delete form section |
| Modify | `public/login.php` | ⚠️ Yes | US-17 — pending/rejected messages |
| Modify | `public/register.php` | ⚠️ Yes | US-17 — no auto-login; pending message |
| Modify | `db/schema.sql` | ⚠️ Yes | US-16 + US-17 — column changes + ALTER |
| Modify | `tests/RepositoryTest.php` | ⚠️ Yes | `deleteUserAccount()` integration tests |
| Modify | `README.md` | No | US-17 — first-admin query |
| Create | `public/admin/users.php` | No | US-17 |
| Create | `public/admin/index.php` | No | US-17 — redirect to users.php |
| Create | `templates/email/account_approved.php` | No | US-17 |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us16-17.md` | No — this file |

**`tests/RepositoryTest.php` is pre-listed**: `deleteUserAccount()` takes a PDO parameter with a non-trivial transaction — Test Validator will require tests per established precedent.

---

## New Functions in `src/Auth.php`

### US-16

```php
function deleteUserAccount(PDO $pdo, int $userId, string $passwordInput): bool
```
1. Fetch `password_hash` from `users` WHERE `id = ?`; `password_verify()` — return `false` if wrong.
2. Begin transaction.
3. CASCADE (in FK-safe order):
   - `UPDATE standup_submissions SET user_id = NULL WHERE user_id = ?`
   - `UPDATE standup_tokens SET user_id = NULL WHERE user_id = ?`
   - `UPDATE organisations SET created_by = NULL WHERE created_by = ?` *(additional — see note)*
   - `UPDATE teams SET created_by = NULL WHERE created_by = ?` *(additional — see note)*
   - `DELETE FROM team_members WHERE user_id = ?`
   - `DELETE FROM org_members WHERE user_id = ?`
   - `DELETE FROM invitations WHERE invited_by = ?`
   - `DELETE FROM password_resets WHERE user_id = ?`
   - `DELETE FROM users WHERE id = ?`
4. Commit; return `true`.

**FK gap from spec**: `organisations.created_by` and `teams.created_by` are `NOT NULL` references to `users.id`. Deleting a user who created orgs/teams would violate the FK constraint unless these are nullified first. The ALTER TABLE statements below make these nullable and the cascade adds the two UPDATE steps. This is a necessary augmentation to the US-16 spec.

### US-17

```php
function requireAdmin(): void
// requireLogin() first; then: if (empty($_SESSION['is_admin'])) { forbid(); }
```

---

## Schema Changes (`db/schema.sql`)

### US-16 — make user_id nullable on archival tables + created_by nullable

```sql
-- Archival preservation: submissions + tokens survive user deletion
ALTER TABLE standup_submissions MODIFY user_id INT UNSIGNED NULL;
ALTER TABLE standup_tokens      MODIFY user_id INT UNSIGNED NULL;

-- Allow orgs/teams to survive creator deletion (created_by -> NULL)
ALTER TABLE organisations MODIFY created_by INT UNSIGNED NULL;
ALTER TABLE teams         MODIFY created_by INT UNSIGNED NULL;
```

Also update the `CREATE TABLE` stanzas in schema.sql to reflect nullable `user_id` / `created_by` for fresh deployments.

### US-17 — add columns to users

```sql
ALTER TABLE users
    ADD COLUMN is_admin       TINYINT(1)   NOT NULL DEFAULT 0,
    ADD COLUMN account_status VARCHAR(10)  NOT NULL DEFAULT 'pending';

-- Approve all existing users so they are not locked out
UPDATE users SET account_status = 'approved' WHERE account_status = 'pending';
```

Also update `CREATE TABLE users` stanza to include both columns.

---

## US-17 Login Flow (updated `loginUser()` and `public/login.php`)

```
1. SELECT * FROM users WHERE email = ?
2. If no row OR !password_verify() → generic "Invalid email or password."
3. Else if account_status = 'pending'  → specific message
4. Else if account_status = 'rejected' → specific message (edge case; normally deleted)
5. Else (approved):
   - $_SESSION['user_id']  = $user['id']
   - $_SESSION['is_admin'] = (bool) $user['is_admin']
   - session_regenerate_id(true)
   - Redirect to /dashboard.php
```

`loginUser()` in Auth.php currently returns `bool`. It needs to return a status string or use a new function to pass back the pending/rejected state. Approach: change `loginUser()` return to `string` ('ok'|'invalid'|'pending'|'rejected') and handle in `login.php`.

---

## US-17 Register Flow Change

After `registerUser()` succeeds: do NOT set `$_SESSION['user_id']`. Show message and redirect/render "Account pending approval." No session started.

`registerUser()` default `account_status = 'pending'` (the column default handles this — no code change needed in the INSERT).

---

## Test Plan for `deleteUserAccount()` (2 cases in `tests/RepositoryTest.php`)

1. `testDeleteUserAccountCorrectPassword_UserDeletedSubmissionsPreserved`
   - Seed: user + org + team + submission + token
   - Call `deleteUserAccount($pdo, $userId, 'password')` → assert `true`
   - Assert: `users` row gone; `standup_submissions` row still exists with `user_id = NULL`
   - Assert: `team_members`, `org_members` rows gone

2. `testDeleteUserAccountWrongPassword_ReturnsFalse`
   - Seed: user
   - Call `deleteUserAccount($pdo, $userId, 'wrongpassword')` → assert `false`
   - Assert: user row still exists

---

## Self-Check

- [ ] `deleteUserAccount()` transaction wraps all cascade steps
- [ ] `organisations.created_by` + `teams.created_by` nullified before user delete
- [ ] Schema ALTER statements for both US-16 and US-17 changes
- [ ] `loginUser()` returns status string; pending/rejected handled in login.php
- [ ] `$_SESSION['is_admin']` set on approved login
- [ ] `requireAdmin()` calls `requireLogin()` then checks `$_SESSION['is_admin']`
- [ ] Admin cannot toggle own `is_admin` flag (self-protection check)
- [ ] All admin actions via POST with CSRF token
- [ ] Register: no auto-login on success; pending message shown
- [ ] CSRF validated on delete-account form
- [ ] `declare(strict_types=1)` in new PHP files
- [ ] `tests/RepositoryTest.php` schema-sqlite.sql may need ALTER handling
- [ ] 37 existing tests still pass
