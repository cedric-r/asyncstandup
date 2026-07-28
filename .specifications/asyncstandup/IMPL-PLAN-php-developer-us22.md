# IMPL-PLAN — PHP Developer
## US-22: Developer-Only Access Restriction

**Status**: APPROVED
**Branch**: `feature/asyncstandup-access-restriction`
**Agent**: PHP Developer

---

## File List (exhaustive — 7 files)

| Action | File | Path B? |
|---|---|---|
| Modify | `src/Auth.php` | ⚠️ Yes — 2 new functions (additive) |
| Modify | `public/orgs/create.php` | ⚠️ Yes — forbid() at top |
| Modify | `public/orgs/index.php` | ⚠️ Yes — hide "New Organisation" link |
| Modify | `public/teams/create.php` | ⚠️ Yes — forbid() at top |
| Modify | `public/teams/index.php` | ⚠️ Yes — hide "New Team" link |
| Modify | `tests/RepositoryTest.php` | ⚠️ Yes — 6 new test cases (pre-listed) |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us22.md` | No |

---

## New Functions in `src/Auth.php`

```php
function isOrgOrTeamOwnerAnywhere(PDO $pdo, int $userId): bool
```
Returns `true` if ANY: `is_admin=1`, OR created ≥1 org, OR `is_owner=1` on ≥1 team.

```php
function isPureDeveloper(PDO $pdo, int $userId): bool
```
Returns `true` if: `isOrgOrTeamOwnerAnywhere()` = false AND `COUNT(team_members WHERE user_id=?)` > 0.
Zero-membership users return `false` (bootstrapping preserved).

---

## Usage Pattern

**Create pages** (after `requireLogin()`):
```php
$isPureDeveloper = isPureDeveloper($pdo, (int) $_SESSION['user_id']);
if ($isPureDeveloper) { forbid(); }
```

**Index pages** — compute once, use in template:
```php
$isPureDeveloper = isPureDeveloper($pdo, (int) $_SESSION['user_id']);
// In template: <?php if (!$isPureDeveloper): ?> <link> <?php endif; ?>
```

---

## Test Plan — 6 cases in `tests/RepositoryTest.php`

| # | Method | Scenario | `isOrgOrTeamOwnerAnywhere` | `isPureDeveloper` |
|---|---|---|---|---|
| 1 | `testOwnerAnywhere_AdminUser_ReturnsTrue` | is_admin=1 | true | — |
| 2 | `testOwnerAnywhere_OrgCreator_ReturnsTrue` | created org | true | — |
| 3 | `testOwnerAnywhere_TeamOwner_ReturnsTrue` | is_owner=1 | true | — |
| 4 | `testPureDeveloper_MemberNoOwnership_ReturnsTrue` | is_owner=0, has membership | false → true | true |
| 5 | `testPureDeveloper_NewUserNoMemberships_ReturnsFalse` | zero memberships | false | false |
| 6 | `testPureDeveloper_OwnerOnOneTeamDevOnAnother_ReturnsFalse` | mixed roles | true | false |

---

## Self-Check

- [ ] `isPureDeveloper()` zero-membership users return `false` (bootstrapping works)
- [ ] `$isPureDeveloper` computed once per page — not called multiple times
- [ ] Server-side `forbid()` on create pages is the authoritative check
- [ ] Hiding nav links is UX-only; server-side check cannot be bypassed
- [ ] All existing 55 tests still pass
