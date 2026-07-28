# US-22: Developer-Only Access Restriction

**Feature**: asyncstandup-core  
**Story**: US-22  
**Branch**: `feature/asyncstandup-access-restriction`

## User Story

**As a** system administrator  
**I can** trust that pure developer users cannot create or manage organisations and teams  
**So that** team structure is controlled exclusively by owners and admins

## Definitions

### "Pure Developer"

A user is a **pure developer** when ALL of the following are true:

1. `users.is_admin = 0`
2. No rows in `organisations` where `created_by = user_id`
3. No rows in `team_members` where `user_id = ? AND is_owner = 1`
4. **At least one row in `team_members`** for this user (any role)

Condition 4 is critical: a freshly registered user with zero team memberships is **not** a pure developer — they must be able to bootstrap by creating their first organisation. Only users who have been invited into teams (and hold no ownership) are restricted.

### "Owner Anywhere"

A user has ownership/admin privilege if ANY of these are true:
- `is_admin = 1`, OR
- Created at least one organisation (`organisations.created_by = user_id`), OR
- Is an owner of at least one team (`team_members.is_owner = 1`)

## Acceptance Criteria

1. **Given** pure developer (has memberships, no ownership, no admin) visits `orgs/create.php`, **When** loaded, **Then** `forbid()` → HTTP 403
2. **Given** pure developer visits `orgs/index.php`, **When** rendered, **Then** "New Organisation" button/link is NOT shown
3. **Given** pure developer visits `teams/create.php`, **When** loaded, **Then** `forbid()` → HTTP 403
4. **Given** pure developer visits `teams/index.php`, **When** rendered, **Then** "New Team" link is NOT shown
5. **Given** user who is an org creator, team owner, or admin visits `orgs/create.php` or `teams/create.php`, **When** loaded, **Then** page renders normally — no regression
6. **Given** freshly registered user with zero team memberships visits `orgs/create.php`, **When** loaded, **Then** page renders normally — they are not a pure developer and can bootstrap

## Definition of Done

- [ ] All ACs met
- [ ] `isOrgOrTeamOwnerAnywhere(PDO $pdo, int $userId): bool` added to `src/Auth.php`
- [ ] `isPureDeveloper(PDO $pdo, int $userId): bool` added to `src/Auth.php`
- [ ] Both helpers are PDO-injectable (testable with in-memory SQLite)
- [ ] 403 pages use existing `forbid()` function
- [ ] Nav link hiding uses `$isPureDeveloper` variable computed once per page (not per-render call)
- [ ] New test cases in `tests/RepositoryTest.php` covering all pure developer / owner-anywhere scenarios

## Files

| Action | File | Risk |
|---|---|---|
| Modify | `src/Auth.php` | ⚠️ Path B — two new functions (additive) |
| Modify | `public/orgs/create.php` | ⚠️ Path B — add `isPureDeveloper()` check at top |
| Modify | `public/orgs/index.php` | ⚠️ Path B — hide "New Organisation" link conditionally |
| Modify | `public/teams/create.php` | ⚠️ Path B — add `isPureDeveloper()` check at top |
| Modify | `public/teams/index.php` | ⚠️ Path B — hide "New Team" link conditionally |
| Modify | `tests/RepositoryTest.php` | ⚠️ Path B — new test cases |

## Implementation Details

### `isOrgOrTeamOwnerAnywhere(PDO $pdo, int $userId): bool`

```php
function isOrgOrTeamOwnerAnywhere(PDO $pdo, int $userId): bool {
    // 1. Admin flag
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if ((bool) $stmt->fetchColumn()) return true;

    // 2. Org creator
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM organisations WHERE created_by = ?');
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) return true;

    // 3. Team owner
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM team_members WHERE user_id = ? AND is_owner = 1'
    );
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) return true;

    return false;
}
```

### `isPureDeveloper(PDO $pdo, int $userId): bool`

```php
function isPureDeveloper(PDO $pdo, int $userId): bool {
    // Must have no ownership/admin privileges
    if (isOrgOrTeamOwnerAnywhere($pdo, $userId)) return false;

    // Must have at least one team membership (any role)
    // Without this check, new users with zero memberships would also be blocked
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() > 0;
}
```

### Usage pattern in create pages

```php
// At top of orgs/create.php and teams/create.php, after requireLogin():
$isPureDeveloper = isPureDeveloper($pdo, $currentUser['id']);
if ($isPureDeveloper) {
    forbid();
}
```

### Usage pattern in index pages

```php
// At top of orgs/index.php and teams/index.php, after requireLogin():
$isPureDeveloper = isPureDeveloper($pdo, $currentUser['id']);
```

In the template:
```php
<?php if (!$isPureDeveloper): ?>
    <a href="/orgs/create.php" class="...">New Organisation</a>
<?php endif; ?>
```

### Performance consideration

`isPureDeveloper()` executes up to 4 DB queries (is_admin + 2 COUNTs + membership check). This is called once per page load, not in a loop — acceptable. If performance becomes a concern, the result can be cached in `$_SESSION['is_pure_developer']` and invalidated on team membership changes. Document as a future optimisation; do not implement now.

### `tests/RepositoryTest.php` — new test cases

Test all paths of `isPureDeveloper()` and `isOrgOrTeamOwnerAnywhere()`:

| Scenario | `isOrgOrTeamOwnerAnywhere` | `isPureDeveloper` |
|---|---|---|
| Admin user | `true` | `false` |
| Org creator, no team memberships | `true` | `false` |
| Team owner (`is_owner=1`) | `true` | `false` |
| Member with `is_owner=0`, has membership | `false` | `true` |
| New user, zero memberships | `false` | `false` |
| Member with mixed roles (owner on one team, dev on another) | `true` | `false` |

Use in-memory SQLite PDO from `createTestPdo()`. Seed minimal data per scenario. No mocking needed.

## Behaviour Summary

| User type | Can create org | Can create team | "New Org" link shown | "New Team" link shown |
|---|---|---|---|---|
| Admin | ✅ | ✅ | ✅ | ✅ |
| Org creator | ✅ | ✅ | ✅ | ✅ |
| Team owner | ✅ | ✅ | ✅ | ✅ |
| Pure developer (memberships, no ownership) | ❌ 403 | ❌ 403 | ❌ hidden | ❌ hidden |
| New user (zero memberships) | ✅ | ✅ | ✅ | ✅ |

## Security Note

Server-side `forbid()` is the authoritative enforcement for `orgs/create.php` and `teams/create.php`. Hiding the nav links is UX-only and does not replace the server-side check. A pure developer crafting a direct POST to `orgs/create.php` will receive HTTP 403.
