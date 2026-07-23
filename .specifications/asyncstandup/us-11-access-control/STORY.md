# US-11: Access Control

**Feature**: asyncstandup-core  
**Story**: US-11  
**Branch**: `feature/asyncstandup-tests-pwreset`

## User Story

**As an** operator  
**I can** trust that non-owner team members cannot access owner-only pages  
**So that** team configuration and member data are protected from unintended modification

## Acceptance Criteria

1. **Given** logged-in user who is NOT a team owner (`is_owner = 0`), **When** they visit `/teams/dashboard.php?team_id=X`, **Then** HTTP 403 returned with "Forbidden" message; page not rendered
2. **Given** logged-in team owner (`is_owner = 1`), **When** they visit `/teams/dashboard.php?team_id=X`, **Then** page renders normally
3. **Given** non-owner team member, **When** viewing the team list, **Then** "Dashboard" link for that team is NOT rendered in navigation — no invitation to visit the restricted page
4. **Given** logged-in user who is NOT an org member, **When** they visit `/orgs/edit.php?id=X`, **Then** HTTP 403 returned — confirmed by `isOrgMember()` check (already exists; verify it is enforced)
5. **Given** logged-in org member who is NOT the org creator (`created_by ≠ user_id`), **When** they visit `/orgs/edit.php?id=X`, **Then** HTTP 403 returned — org name editable only by creator
6. **Given** logged-in team member who is NOT a team owner, **When** they visit `/teams/edit.php?team_id=X`, **Then** HTTP 403 returned — team settings editable only by owners

## Definition of Done

- [ ] All ACs met
- [ ] All 403 responses: `http_response_code(403); echo '<h1>Forbidden</h1><p>You do not have permission to access this page.</p>'; exit;`
- [ ] No page content rendered after 403 exit
- [ ] Dashboard link conditionally hidden in team list template based on `is_owner` flag
- [ ] `isOrgCreator()` helper added to `OrgRepository.php` (or inline check — see implementation details)
- [ ] `isTeamOwner()` helper confirmed present in `TeamRepository.php` (added in US-3; verify and reuse)

## Files

| Action | File | Risk |
|---|---|---|
| Modify | `public/teams/dashboard.php` | ⚠️ Path B — add owner check at top |
| Modify | `public/orgs/edit.php` | ⚠️ Path B — add creator check after existing `isOrgMember()` check |
| Modify | `public/teams/edit.php` | ⚠️ Path B — add `isTeamOwner()` check at top |
| Modify | `public/teams/index.php` (or team listing template) | ⚠️ Path B — hide dashboard link for non-owners |
| Modify | `src/OrgRepository.php` | ⚠️ Path B — add `isOrgCreator()` function |

## Implementation Details

### 403 response pattern (all pages)

```php
function forbid(): never {
    http_response_code(403);
    echo '<h1>Forbidden</h1><p>You do not have permission to access this page.</p>';
    exit;
}
```

Defined in `src/Auth.php` (or inline). Called immediately after failed check; no further code runs.

### AC-1/2: `public/teams/dashboard.php`

Add at top (after `requireLogin()` and loading `$teamId` from `$_GET`):

```php
$member = getTeamMembership($pdo, $teamId, $currentUser['id']);
if (!$member || !$member['is_owner']) {
    forbid();
}
```

`getTeamMembership()` returns the `team_members` row or `null` — already needed for the non-owner member path (US-7). Reuse or adapt.

### AC-3: Dashboard link visibility in team list

In `public/teams/index.php` (or whichever template renders the team list), the "Dashboard" link renders conditionally:

```php
<?php if ($team['is_owner']): ?>
    <a href="/teams/dashboard.php?team_id=<?= (int)$team['id'] ?>">Dashboard</a>
<?php endif; ?>
```

`$team['is_owner']` comes from the team list query (already includes `team_members` row per logged-in user). If not already included, add to query:

```sql
SELECT t.*, tm.is_owner, tm.is_developer, tm.is_recipient
FROM teams t
JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = :userId
WHERE t.org_id = :orgId
```

### AC-4: `public/orgs/edit.php` — non-member check

Existing `isOrgMember()` check confirmed enforced. If gap found, add:

```php
if (!isOrgMember($pdo, $orgId, $currentUser['id'])) {
    forbid();
}
```

### AC-5: `public/orgs/edit.php` — creator-only check

Add after `isOrgMember()` check:

```php
if (!isOrgCreator($pdo, $orgId, $currentUser['id'])) {
    forbid();
}
```

**`isOrgCreator()` in `src/OrgRepository.php`**:

```php
function isOrgCreator(PDO $pdo, int $orgId, int $userId): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM organisations WHERE id = ? AND created_by = ?');
    $stmt->execute([$orgId, $userId]);
    return (bool) $stmt->fetchColumn();
}
```

### AC-6: `public/teams/edit.php` — owner-only check

Add at top after `requireLogin()`:

```php
if (!isTeamOwner($pdo, $teamId, $currentUser['id'])) {
    forbid();
}
```

`isTeamOwner()` defined in `src/TeamRepository.php` (US-3):

```php
function isTeamOwner(PDO $pdo, int $teamId, int $userId): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND is_owner = 1'
    );
    $stmt->execute([$teamId, $userId]);
    return (bool) $stmt->fetchColumn();
}
```

Verify this function exists from US-3 implementation. If absent, add it.

## Security Notes

- **Early exit**: all `forbid()` calls use `exit` — no partial page rendered, no data leaked
- **No client-side gating**: hiding the dashboard link (AC-3) is UX-only; server-side check (AC-1) is the true enforcement
- **Consistent 403**: all restricted pages return HTTP 403 (not redirect to login) — only unauthenticated users are redirected to login; authenticated-but-unauthorised users get 403
- **No information disclosure**: 403 response does not reveal whether the team/org exists; response is identical for non-existent and forbidden resources
