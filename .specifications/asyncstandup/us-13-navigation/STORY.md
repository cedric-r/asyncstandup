# US-13: Navigation Improvements

**Feature**: asyncstandup-core  
**Story**: US-13  
**Branch**: `feature/asyncstandup-navigation`

## User Story

**As a** logged-in user  
**I can** navigate between all pages of a team without dead ends, and as a team owner I can click on any developer member to view their response history  
**So that** the application is usable without having to rely on the browser's back button

## Acceptance Criteria

1. **Given** any team-scoped page visited, **When** rendered, **Then** a team nav bar is visible showing: breadcrumb (Organisations > Org Name > Team Name) + links to all pages the current user can access for that team
2. **Given** the current page is rendered, **When** the team nav bar displays, **Then** the link for the current page has CSS class `active` (visually distinct — bold or underlined)
3. **Given** owner visits `teams/members.php`, **When** rendered, **Then** each developer member (`is_developer = 1`) has a "View responses" link: `responses.php?team_id=X&member_id=[user_id]`
4. **Given** non-owner visits `teams/members.php`, **When** rendered, **Then** no "View responses" links shown
5. **Given** `teams/index.php` rendered for an owner, **When** a team is listed, **Then** direct action links shown per team: Dashboard, Members, Questions, Edit, Responses
6. **Given** `teams/index.php` rendered for a non-owner member, **When** a team is listed, **Then** only member-accessible links shown (no owner-only pages)
7. **Given** any org edit or delete page visited, **When** rendered, **Then** a "← Back to organisations" link is visible and functional

## Definition of Done

- [ ] All ACs met
- [ ] `templates/team-nav.php` created; all 7 team pages include it
- [ ] Nav links rendered conditionally based on `$currentMembership` array (`is_owner`, `is_developer`, `is_recipient` flags)
- [ ] Active page detection uses a `$currentPage` string variable set at top of each page before the include
- [ ] `assets/style.css` updated with `.team-nav`, `.active`, `.back-link`, `.breadcrumb` styles
- [ ] No access control logic in `team-nav.php` — it renders what it is given; callers set `$isOwner` etc.
- [ ] All Path B modifications are additive (include statement + link additions only)

## Files

| Action | File | Risk |
|---|---|---|
| Create | `templates/team-nav.php` | — |
| Modify | `public/teams/index.php` | ⚠️ Path B — add per-team action links |
| Modify | `public/teams/edit.php` | ⚠️ Path B — add team-nav include |
| Modify | `public/teams/members.php` | ⚠️ Path B — add team-nav include + "View responses" links |
| Modify | `public/teams/questions.php` | ⚠️ Path B — add team-nav include |
| Modify | `public/teams/recipients.php` | ⚠️ Path B — add team-nav include |
| Modify | `public/teams/dashboard.php` | ⚠️ Path B — add team-nav include |
| Modify | `public/teams/responses.php` | ⚠️ Path B — add team-nav include |
| Modify | `public/orgs/edit.php` | ⚠️ Path B — add back link |
| Modify | `public/orgs/delete.php` | ⚠️ Path B — add back link |
| Modify | `public/assets/style.css` | ⚠️ Path B — additive CSS rules |

## Implementation Details

### `templates/team-nav.php`

Variables required from the calling page (set before `include`):

```php
// Required before including team-nav.php:
$currentPage       // string: 'dashboard'|'members'|'questions'|'recipients'|'edit'|'responses'
$teamId            // int
$orgId             // int
$teamName          // string
$orgName           // string
$isOwner           // bool — from team_members.is_owner for current user
```

Template renders:

```html
<nav class="team-nav">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/orgs/index.php">Organisations</a> &rsaquo;
        <a href="/teams/index.php?org_id=<?= $orgId ?>"><?= h($orgName) ?></a> &rsaquo;
        <span><?= h($teamName) ?></span>
    </div>

    <!-- Page links -->
    <ul class="team-nav-links">
        <li class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <a href="/teams/dashboard.php?team_id=<?= $teamId ?>">Dashboard</a>
        </li>
        <?php if ($isOwner): ?>
        <li class="<?= $currentPage === 'responses' ? 'active' : '' ?>">
            <a href="/teams/responses.php?team_id=<?= $teamId ?>">Responses</a>
        </li>
        <li class="<?= $currentPage === 'members' ? 'active' : '' ?>">
            <a href="/teams/members.php?team_id=<?= $teamId ?>">Members</a>
        </li>
        <li class="<?= $currentPage === 'questions' ? 'active' : '' ?>">
            <a href="/teams/questions.php?team_id=<?= $teamId ?>">Questions</a>
        </li>
        <li class="<?= $currentPage === 'recipients' ? 'active' : '' ?>">
            <a href="/teams/recipients.php?team_id=<?= $teamId ?>">Recipients</a>
        </li>
        <li class="<?= $currentPage === 'edit' ? 'active' : '' ?>">
            <a href="/teams/edit.php?team_id=<?= $teamId ?>">Settings</a>
        </li>
        <?php endif; ?>
    </ul>
</nav>
```

`h()` helper function: `function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }` — define in `src/View.php` or inline in `templates/layout/header.php`.

### Integration pattern in each team page

Add at top of each team page, after loading `$team` and `$currentUser`:

```php
// Example: teams/members.php
$teamId     = (int)($_GET['team_id'] ?? 0);
$team       = getTeamById($pdo, $teamId);           // already loaded
$membership = getTeamMembership($pdo, $teamId, $currentUser['id']);
$isOwner    = (bool)($membership['is_owner'] ?? false);
$org        = getOrgById($pdo, $team['org_id']);

$currentPage = 'members';  // <-- set per page
include __DIR__ . '/../../templates/team-nav.php';
```

### "View responses" links on `teams/members.php`

In the member list render loop, add conditionally for developer members:

```php
<?php if ($isOwner && $member['is_developer']): ?>
    <a href="/teams/responses.php?team_id=<?= $teamId ?>&member_id=<?= (int)$member['id'] ?>">
        View responses
    </a>
<?php endif; ?>
```

### Per-team action links on `teams/index.php`

For each team in the list, replace the existing single "Manage" link (or add to it) with a row of action links:

```php
<a href="/teams/dashboard.php?team_id=<?= $t['id'] ?>">Dashboard</a>
<?php if ($t['is_owner']): ?>
    | <a href="/teams/members.php?team_id=<?= $t['id'] ?>">Members</a>
    | <a href="/teams/questions.php?team_id=<?= $t['id'] ?>">Questions</a>
    | <a href="/teams/recipients.php?team_id=<?= $t['id'] ?>">Recipients</a>
    | <a href="/teams/edit.php?team_id=<?= $t['id'] ?>">Settings</a>
    | <a href="/teams/responses.php?team_id=<?= $t['id'] ?>">Responses</a>
<?php endif; ?>
```

`$t['is_owner']` sourced from the team list query (already includes `team_members` row per US-7).

### Org back link (`orgs/edit.php` and `orgs/delete.php`)

Add at top of page content (after `requireLogin()`, before form):

```html
<p class="back-link"><a href="/orgs/index.php">&larr; Back to organisations</a></p>
```

### CSS additions (`public/assets/style.css`)

```css
/* Breadcrumb */
.breadcrumb { font-size: 0.9em; color: #666; margin-bottom: 8px; }
.breadcrumb a { color: #1976d2; text-decoration: none; }
.breadcrumb a:hover { text-decoration: underline; }

/* Team nav bar */
.team-nav { background: #f5f5f5; border-bottom: 1px solid #ddd; padding: 8px 16px; margin-bottom: 16px; }
.team-nav-links { list-style: none; margin: 4px 0 0; padding: 0; display: flex; gap: 16px; }
.team-nav-links a { color: #1976d2; text-decoration: none; font-size: 0.95em; }
.team-nav-links a:hover { text-decoration: underline; }
.team-nav-links li.active a { font-weight: bold; color: #333; text-decoration: none; cursor: default; }

/* Back link */
.back-link { margin-bottom: 16px; font-size: 0.9em; }
.back-link a { color: #1976d2; text-decoration: none; }
.back-link a:hover { text-decoration: underline; }
```

### `getTeamById()` and `getOrgById()` helpers

These may already exist from previous stories. If not, add to `TeamRepository.php` and `OrgRepository.php`:

```php
function getTeamById(PDO $pdo, int $teamId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getOrgById(PDO $pdo, int $orgId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM organisations WHERE id = ?');
    $stmt->execute([$orgId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

## Implementation Notes

- **No access control in `team-nav.php`**: the partial only renders what `$isOwner` tells it; the calling page is responsible for enforcing access via `isTeamOwner()` / `forbid()`
- **`$currentPage` convention**: each page sets a plain string before the include — no magic detection; consistent and explicit
- **teams/index.php does not include team-nav.php**: it is a list page, not a single-team context page; it gets per-team action links instead
- **CSS file creation**: if `public/assets/style.css` does not yet exist (not created in Phase 0), create it; link from `templates/layout/header.php` via `<link rel="stylesheet" href="/assets/style.css">`
- **`h()` helper**: define once in a shared location (`src/View.php` or `templates/layout/header.php`) — do not redefine per page
