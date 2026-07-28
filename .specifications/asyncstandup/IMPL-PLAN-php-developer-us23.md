# IMPL-PLAN — PHP Developer
## US-23: Admin Delete User

**Status**: APPROVED
**Branch**: `feature/asyncstandup-admin-delete`
**Agent**: PHP Developer

---

## File List (exhaustive — 4 files)

| Action | File | Path B? |
|---|---|---|
| Modify | `src/Auth.php` | ⚠️ Yes — extract cascade + 2 new functions |
| Modify | `public/admin/users.php` | ⚠️ Yes — delete_user action + button per row |
| Modify | `tests/RepositoryTest.php` | ⚠️ Yes — pre-listed: comprehensive cascade test |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us23.md` | No |

---

## Refactor Plan for `src/Auth.php`

### New shared inner function

```php
function cascadeDeleteUser(PDO $pdo, int $userId): void
```
Contains the 10 FK-safe cascade steps. No transaction management — caller wraps.
Extracted from `deleteUserAccount()`. Steps:
1. `UPDATE standup_submissions SET user_id = NULL WHERE user_id = ?`
2. `UPDATE standup_tokens SET user_id = NULL WHERE user_id = ?`
3. `UPDATE organisations SET created_by = NULL WHERE created_by = ?`
4. `UPDATE teams SET created_by = NULL WHERE created_by = ?`
5. `UPDATE team_recipients SET added_by = NULL WHERE added_by = ?` (hot-fix 972452f)
6. `DELETE FROM team_members WHERE user_id = ?`
7. `DELETE FROM org_members WHERE user_id = ?`
8. `DELETE FROM invitations WHERE invited_by = ?`
9. `DELETE FROM password_resets WHERE user_id = ?`
10. `DELETE FROM users WHERE id = ?`

### `deleteUserAccount()` — refactored (behaviour unchanged)

Password verify → `beginTransaction()` → `cascadeDeleteUser()` → `commit()`.

### New function

```php
function adminDeleteUser(PDO $pdo, int $targetUserId): void
```
No password check. `beginTransaction()` → `cascadeDeleteUser()` → `commit()`.

---

## `admin/users.php` — delete_user action

POST action `'delete_user'`:
1. CSRF validate
2. Self-delete guard: `(int)$targetId === (int)$_SESSION['user_id']` → error
3. `adminDeleteUser($pdo, $targetId)`
4. `setFlash('success', 'User deleted.')` → redirect

Button per row (outside own row): danger styling + `onclick="return confirm(...)"` (UX only; server-side is authoritative).

---

## Test Plan — `tests/RepositoryTest.php`

1. `testAdminDeleteUser_CascadePreservesSubmissionsAndTokens`
   — seed full tree (user → org → team → member → question → token → submission → answer → summary_sent → recipient → invitation → password_reset)
   — call `adminDeleteUser()`
   — assert: user gone; submissions/tokens preserved with `user_id=NULL`; `team_recipients.added_by=NULL`; `team_members` gone; `org_members` gone

2. `testAdminDeleteUser_WrongSelfDeleteGuard` (logic test in admin page — tested at page level, not repository level)
   — covered by AC-3; verified by self-delete guard in admin/users.php

---

## Self-Check

- [ ] `cascadeDeleteUser()` contains exactly 10 steps including `team_recipients.added_by=NULL`
- [ ] `deleteUserAccount()` calls `cascadeDeleteUser()` — behaviour unchanged for self-delete
- [ ] `adminDeleteUser()` has no password check
- [ ] Admin self-delete guard: server-side `(int)$targetId !== (int)$_SESSION['user_id']`
- [ ] `requireAdmin()` enforced on admin/users.php before any POST handling
- [ ] 61 existing tests still pass
