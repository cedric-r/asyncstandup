# US-23: Admin Delete User

**Feature**: asyncstandup-core  
**Story**: US-23  
**Branch**: `feature/asyncstandup-admin-delete`

## User Story

**As an** administrator  
**I can** delete any user account from the admin panel  
**So that** I can remove users who should no longer have access to the system

## Acceptance Criteria

1. **Given** admin visits `admin/users.php`, **When** rendered, **Then** each user row — except the admin's own row — has a "Delete user" button with danger styling
2. **Given** admin submits the "Delete user" POST for a target user with valid CSRF, **When** processed, **Then** user account is deleted with full cascade; admin redirected to `admin/users.php` with flash "User deleted."
3. **Given** admin submits a delete POST targeting their own user ID (crafted POST), **When** processed, **Then** request rejected with error; account not deleted; admin session intact
4. **Given** deletion executes, **When** complete, **Then** `standup_submissions.user_id` and `standup_tokens.user_id` set to NULL; user record gone from `users` table; all other related rows removed
5. **Given** non-admin sends POST to the delete action, **When** processed, **Then** `requireAdmin()` → HTTP 403; no deletion occurs

## Definition of Done

- [ ] All ACs met
- [ ] `cascadeDeleteUser(PDO $pdo, int $userId): void` extracted as shared inner function in `src/Auth.php`
- [ ] `deleteUserAccount()` (US-16) refactored to call `cascadeDeleteUser()` — behaviour unchanged
- [ ] `adminDeleteUser(PDO $pdo, int $userId): void` calls `cascadeDeleteUser()` without password check
- [ ] Self-delete guard: `(int)$_POST['target_user_id'] !== (int)$_SESSION['user_id']`
- [ ] Single-POST confirm pattern (consistent with existing reject action on admin page)
- [ ] `tests/RepositoryTest.php` covers `cascadeDeleteUser()` cascade correctness

## Files

| Action | File | Risk |
|---|---|---|
| Modify | `src/Auth.php` | ⚠️ Path B — refactor + 2 new functions |
| Modify | `public/admin/users.php` | ⚠️ Path B — add delete action |
| Modify | `tests/RepositoryTest.php` | ⚠️ Path B — new test cases |

## Implementation Details

---

### Refactor in `src/Auth.php`

Extract the cascade steps from `deleteUserAccount()` (US-16) into a shared inner function:

```php
/**
 * Executes the full user deletion cascade inside the caller's transaction.
 * Does NOT begin or commit the transaction — caller is responsible.
 * Steps (FK-safe order):
 *   1. standup_submissions.user_id → NULL
 *   2. standup_tokens.user_id → NULL
 *   3. team_recipients.added_by → NULL (nullable FK from hot-fix 972452f)
 *   4. DELETE team_members WHERE user_id = ?
 *   5. DELETE org_members WHERE user_id = ?
 *   6. DELETE invitations WHERE invited_by = ?
 *   7. DELETE password_resets WHERE user_id = ?
 *   8. DELETE users WHERE id = ?
 */
function cascadeDeleteUser(PDO $pdo, int $userId): void {
    $pdo->prepare('UPDATE standup_submissions SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE standup_tokens      SET user_id = NULL WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE team_recipients     SET added_by = NULL WHERE added_by = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM team_members   WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM org_members    WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM invitations    WHERE invited_by = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM users          WHERE id = ?')->execute([$userId]);
}
```

**Note on `team_recipients.added_by`**: This column was made nullable in the hot-fix commit `972452f` after the US-16 implementation review. The nullable FK prevents FK violation when deleting a user who has added external recipients to a team. Setting it to NULL (step 3) preserves the `team_recipients` row — external emails continue to receive summaries even after the user who added them is deleted.

**Refactored `deleteUserAccount()`** (no behaviour change):

```php
function deleteUserAccount(PDO $pdo, int $userId, string $passwordInput): bool {
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($passwordInput, $user['password_hash'])) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        cascadeDeleteUser($pdo, $userId);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

**New `adminDeleteUser()`**:

```php
function adminDeleteUser(PDO $pdo, int $targetUserId): void {
    $pdo->beginTransaction();
    try {
        cascadeDeleteUser($pdo, $targetUserId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
```

---

### `public/admin/users.php` — delete action

**POST handler addition** (alongside existing approve/reject/toggle_admin handlers):

```php
case 'delete_user':
    $targetId = (int)($_POST['target_user_id'] ?? 0);
    if ($targetId === (int)$_SESSION['user_id']) {
        $flashError = 'You cannot delete your own account from the admin panel.';
        break;
    }
    if ($targetId < 1) {
        $flashError = 'Invalid user.';
        break;
    }
    adminDeleteUser($pdo, $targetId);
    $flash = 'User deleted.';
    break;
```

**Delete button in user row** (rendered for all rows where `$user['id'] !== $currentUser['id']`):

```html
<form method="POST" class="inline">
    <input type="hidden" name="csrf_token"     value="<?= h($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action"         value="delete_user">
    <input type="hidden" name="target_user_id" value="<?= (int)$user['id'] ?>">
    <button type="submit"
        class="text-xs bg-red-600 hover:bg-red-700 text-white font-medium py-1 px-3 rounded-lg transition-colors"
        onclick="return confirm('Delete <?= h(addslashes($user['email'])) ?>? This cannot be undone.')">
        Delete
    </button>
</form>
```

`onclick="return confirm(...)"` — a single line of inline JS used only as a UX guard; the server-side CSRF + self-delete check is the authoritative protection. If JS is disabled, the POST still fires with the server-side guard intact.

---

### `tests/RepositoryTest.php` — new test cases

**`cascadeDeleteUser()` cascade test** (extend existing `deleteOrg()` cascade test pattern):

Seed: user, org (created_by = user), team, team_member, team_question, standup_token, standup_submission, standup_answer, summary_sent, team_recipient (added_by = user), invitation (invited_by = user), org_member, password_reset.

Call `cascadeDeleteUser($pdo, $userId)` (inside a manually started transaction for the test, or call `adminDeleteUser()`).

Assertions:
- [ ] `users` table: user row gone
- [ ] `standup_submissions.user_id` = NULL for seeded submission
- [ ] `standup_tokens.user_id` = NULL for seeded token
- [ ] `team_recipients.added_by` = NULL for seeded recipient (row retained)
- [ ] `team_members` row: gone
- [ ] `org_members` row: gone
- [ ] `invitations` row: gone
- [ ] `password_resets` row: gone
- [ ] No PDO exception (FK safety confirmed)

**`adminDeleteUser()` self-delete guard test**: this is in the controller layer (POST handler), not in `cascadeDeleteUser()` — test it as a controller-level check in `tests/AdminTest.php` (new file) or document as "controller-only; not unit-testable without HTTP layer".

## Security Notes

- **CSRF**: delete action covered by existing CSRF validation in `admin/users.php` POST handler
- **Self-delete guard**: checked in the controller before calling `adminDeleteUser()` — prevents admin from accidentally locking themselves out
- **`cascadeDeleteUser()` has no self-guard**: the guard is the caller's responsibility; `cascadeDeleteUser()` is a low-level helper; document this in its docblock
- **Transaction**: `adminDeleteUser()` wraps in transaction — partial deletion impossible on DB error
- **Inline `confirm()`**: UX-only JS; not security-critical; consistent with industry practice for single-POST destructive admin actions
