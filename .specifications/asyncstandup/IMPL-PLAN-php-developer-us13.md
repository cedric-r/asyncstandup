# IMPL-PLAN — PHP Developer
## US-13: Navigation Improvements

**Status**: APPROVED
**Branch**: `feature/asyncstandup-navigation`
**Agent**: PHP Developer

---

## File List (exhaustive — 12 files)

| Action | File | Path B? |
|---|---|---|
| Create | `src/View.php` | No — new; defines `h()` helper |
| Create | `templates/team-nav.php` | No — new partial |
| Modify | `public/teams/index.php` | ⚠️ Yes — per-team action links (additive) |
| Modify | `public/teams/edit.php` | ⚠️ Yes — team-nav include (additive) |
| Modify | `public/teams/members.php` | ⚠️ Yes — team-nav include + "View responses" links (additive) |
| Modify | `public/teams/questions.php` | ⚠️ Yes — team-nav include (additive) |
| Modify | `public/teams/recipients.php` | ⚠️ Yes — team-nav include (additive) |
| Modify | `public/teams/dashboard.php` | ⚠️ Yes — team-nav include (additive) |
| Modify | `public/teams/responses.php` | ⚠️ Yes — team-nav include (additive) |
| Modify | `public/orgs/edit.php` | ⚠️ Yes — back link (additive) |
| Modify | `public/orgs/delete.php` | ⚠️ Yes — back link (additive) |
| Modify | `public/assets/style.css` | ⚠️ Yes — CSS rules appended (additive) |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us13.md` | No — this file |

**No other files will be created.** `getTeamById()` and `getOrgById()` already exist in `TeamRepository.php` / `OrgRepository.php` — no new query functions needed.

**All Path B modifications are purely additive** — no existing logic removed. No characterisation commit required.

---

## New File: `src/View.php`

```php
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
```

Centralised output-escaping helper. `require_once`'d by `templates/team-nav.php`. All existing pages continue to call `htmlspecialchars()` directly — no refactor needed; `h()` is additive for new templates.

---

## New Partial: `templates/team-nav.php`

Variables required from the calling page (set before include):

| Variable | Type | Description |
|---|---|---|
| `$currentPage` | `string` | `'dashboard'|'members'|'questions'|'recipients'|'edit'|'responses'` |
| `$teamId` | `int` | Team ID |
| `$orgId` | `int` | Organisation ID |
| `$teamName` | `string` | Raw team name (escaped by `h()` in template) |
| `$orgName` | `string` | Raw org name (escaped by `h()` in template) |
| `$isOwner` | `bool` | Whether current user is team owner |

**No access control in the template** — caller is responsible. Template only renders `$isOwner`-gated links.

---

## Integration Pattern (all 7 team pages)

After `$team` and session user ID are available, before `ob_start()`:

```php
require_once __DIR__ . '/../../src/OrgRepository.php'; // if not already loaded
$org         = getOrgById($pdo, (int) $team['org_id']);
$orgId       = (int) $team['org_id'];
$orgName     = (string) ($org['name'] ?? '');
$teamName    = (string) $team['name'];
$isOwner     = isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id']);
$currentPage = '<pagename>';  // explicit string constant per page
```

Inside `ob_start()` block, immediately after `<h1>`:

```php
include __DIR__ . '/../../templates/team-nav.php';
```

---

## Path B Change Summary

All modifications are one-liners or small insertions:
- 7 team pages: add `require_once OrgRepository.php` (if not loaded), 5 variable assignments, 1 `include` call
- `teams/members.php`: add `if ($isOwner && $m['is_developer'])` link in member loop
- `teams/index.php`: replace action cell content with expanded link set
- `orgs/edit.php` + `orgs/delete.php`: add `<p class="back-link">` at top of content
- `style.css`: 4 CSS rule blocks appended

---

## Self-Check

- [ ] `h()` defined once in `src/View.php`; not redefined per page
- [ ] `$currentPage` set as explicit string before include on every team page
- [ ] `$isOwner` derived from `isTeamOwner()` — same function used for access control
- [ ] No access control logic in `templates/team-nav.php`
- [ ] All org/team names output via `h()` in template
- [ ] "View responses" links gated by `$isOwner && $member['is_developer']`
- [ ] `declare(strict_types=1)` in `src/View.php`
- [ ] CSS: `.team-nav`, `.active`, `.back-link`, `.breadcrumb` appended to existing `style.css`
- [ ] No `var_dump`/`print_r`/`die`
- [ ] 33 existing tests still pass
