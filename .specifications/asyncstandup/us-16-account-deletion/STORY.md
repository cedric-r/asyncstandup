# US-16: Delete Own Account

**Feature**: asyncstandup-core  
**Story**: US-16  
**Branch**: `feature/asyncstandup-admin`

## User Story

**As a** logged-in user  
**I can** delete my own account from the Profile page after confirming my password  
**So that** I can remove my personal data from the system

## Acceptance Criteria

1. **Given** logged-in user on profile page, **When** "Delete account" button clicked, **Then** a password confirmation form is shown (separate section or modal equivalent)
2. **Given** correct password entered in confirmation form, **When** submitted, **Then** account deleted per cascade order; session destroyed; redirected to `/register.php` with flash "Your account has been deleted."
3. **Given** incorrect password entered, **When** submitted, **Then** error "Incorrect password." shown; account NOT deleted; user remains logged in
4. **Given** empty password field, **When** submitted, **Then** treated as incorrect password (AC-3 path)
5. **Given** account deleted, **When** deletion complete, **Then** `standup_submissions` and `standup_tokens` rows are retained with `user_id = NULL` (archival preserved); no answer rows deleted

## Definition of Done

- [ ] All ACs met
- [ ] Schema: `standup_submissions.user_id` and `standup_tokens.user_id` changed to `NULL`-able (FK preserved but nullable)
- [ ] Cascade operations performed in FK-safe order within a single transaction
- [ ] Password confirmed with `password_verify()` before any deletion begins
- [ ] Session destroyed and CSRF token cleared after successful deletion
- [ ] CSRF token validated on the confirmation form POST
- [ ] `deleteUserAccount()` function in `src/Auth.php` — single point of deletion logic

## Files

| Action | File | Risk |
|---|---|---|
| Modify | `public/profile.php` | ⚠️ Path B — add delete confirmation form section |
| Modify | `db/schema.sql` | ⚠️ Path B — ALTER two columns to nullable |
| Modify | `src/Auth.php` | ⚠️ Path B — add `deleteUserAccount()` |

## Implementation Details

### Schema changes (`db/schema.sql`)

Alter two FK columns to allow NULL (submissions and tokens reference users but must survive user deletion):

```sql
-- Allow NULL for archival preservation
ALTER TABLE standup_submissions MODIFY user_id INT UNSIGNED NULL;
ALTER TABLE standup_tokens      MODIFY user_id INT UNSIGNED NULL;
```

For new deployments: update `CREATE TABLE standup_submissions` and `CREATE TABLE standup_tokens` in `schema.sql` to declare `user_id INT UNSIGNED NULL` from the start.

### `deleteUserAccount(PDO $pdo, int $userId, string $passwordInput): bool`

```php
function deleteUserAccount(PDO $pdo, int $userId, string $passwordInput): bool {
    // 1. Fetch user + verify password
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($passwordInput, $user['password_hash'])) {
        return false;
    }

    // 2. Execute cascade in FK-safe order (single transaction)
    $pdo->beginTransaction();
    try {
        // Nullify user_id on archival tables (preserve data)
        $pdo->prepare('UPDATE standup_submissions SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('UPDATE standup_tokens      SET user_id = NULL WHERE user_id = ?')->execute([$userId]);

        // Delete membership and ownership records
        $pdo->prepare('DELETE FROM team_members  WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM org_members   WHERE user_id = ?')->execute([$userId]);

        // Delete invitations sent by this user
        $pdo->prepare('DELETE FROM invitations   WHERE invited_by = ?')->execute([$userId]);

        // Delete password reset tokens
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);

        // Delete the user record
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

### `public/profile.php` — delete form section

Add below the existing profile update form:

```html
<hr>
<section class="delete-account">
    <h3>Delete Account</h3>
    <p>This action is permanent. Your standup submissions are retained for team records, but your personal data will be removed.</p>
    <form method="POST" action="/profile.php?action=delete">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <label>Confirm your password:
            <input type="password" name="confirm_password" required autocomplete="current-password">
        </label>
        <button type="submit" class="btn-danger">Delete my account</button>
    </form>
</section>
```

### POST handler (`?action=delete`)

```php
if ($_POST && ($_GET['action'] ?? '') === 'delete') {
    validateCsrfOrFail();
    $password = $_POST['confirm_password'] ?? '';
    if (deleteUserAccount($pdo, $currentUser['id'], $password)) {
        session_destroy();
        // Flash cannot use session after destroy — use a query param or cookie for one-time message
        header('Location: /register.php?deleted=1');
        exit;
    } else {
        $errors[] = 'Incorrect password.';
    }
}
```

On `/register.php`, detect `?deleted=1` and display "Your account has been deleted." flash.

### Cascade order (documented)

```
1. standup_submissions.user_id → NULL  (archival, no delete)
2. standup_tokens.user_id      → NULL  (archival, no delete)
3. DELETE team_members WHERE user_id = ?
4. DELETE org_members  WHERE user_id = ?
5. DELETE invitations  WHERE invited_by = ?
6. DELETE password_resets WHERE user_id = ?
7. DELETE users WHERE id = ?
```

`team_recipients` rows where `added_by = user_id`: set `added_by = NULL` if that column is nullable, or leave (external emails are team data, not personal data).

## Security Notes

- Password confirmed with `password_verify()` — no timing attack risk (bcrypt compare is already constant-time)
- Transaction ensures atomicity — partial deletion impossible on DB error
- Session destroyed immediately after successful deletion — no residual auth state
- Remaining archived rows (`standup_submissions`, `standup_tokens`) have no PII beyond `user_id = NULL` — team records preserved
