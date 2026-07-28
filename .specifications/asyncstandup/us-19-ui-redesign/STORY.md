# US-19: UI Redesign with Tailwind CSS

**Feature**: asyncstandup-core  
**Story**: US-19  
**Branch**: `feature/asyncstandup-ui`

## User Story

**As a** user of AsyncStandUp  
**I can** interact with a clean, modern UI inspired by Geekbot's minimal SaaS aesthetic  
**So that** the application feels polished and is easy to use on any device

## Acceptance Criteria

1. **Given** any page loads, **When** rendered, **Then** Tailwind CSS (CDN) is active and all pages use Tailwind utility classes — no legacy hand-written CSS except approved overrides in `style.css`
2. **Given** the nav bar renders, **When** viewed, **Then** logo is left-aligned, nav links are centre/right, user display name + logout are far right; on mobile (≤ 640px), nav collapses to a hamburger menu or stacked layout
3. **Given** any form renders, **When** viewed, **Then** all inputs, labels, and buttons use the consistent design system classes defined in this story
4. **Given** a flash message is present, **When** rendered, **Then** success = green banner, error = red banner, info/pending = blue or amber banner — all at the top of the content area
5. **Given** any CRUD list page (orgs, teams, members, questions, recipients), **When** viewed, **Then** items are shown in a card or styled table layout; action buttons are visible and correctly styled (primary / secondary / danger)
6. **Given** the dashboard landing page, **When** viewed, **Then** teams are shown in a card grid with status summary; pending standups section (if present) uses amber card styling
7. **Given** the admin users page, **When** viewed, **Then** user status is shown as a coloured badge: `pending` = amber, `approved` = green, `rejected` = red
8. **Given** any page is viewed on a 375px-wide viewport, **When** rendered, **Then** content is not cut off or horizontally scrollable; all interactive elements are tappable

## Definition of Done

- [ ] All ACs met
- [ ] Tailwind CDN `<script>` tag in `templates/layout.php` `<head>` (CDN, not npm — no build step)
- [ ] `public/assets/style.css` retained for custom overrides only; hand-written layout/component CSS removed
- [ ] No changes to any `src/*.php` logic files — presentation only
- [ ] No changes to DB schema, queries, or session logic
- [ ] All existing functional behaviour preserved — redesign is cosmetic only
- [ ] Mobile responsiveness tested at 375px and 1280px

## Files in Scope (all ⚠️ Path B — class/markup changes only)

### Layout partials
| File | Change |
|---|---|
| `templates/layout.php` (or `header.php`/`footer.php`) | Add Tailwind CDN; restyle nav bar |
| `templates/team-nav.php` | Restyle with Tailwind classes |
| `public/assets/style.css` | Reduce to custom overrides only |

### Auth pages
| File |
|---|
| `public/login.php` |
| `public/register.php` |
| `public/profile.php` |
| `public/forgot-password.php` |
| `public/reset-password.php` |

### Org pages
| File |
|---|
| `public/orgs/index.php` |
| `public/orgs/create.php` |
| `public/orgs/edit.php` |
| `public/orgs/delete.php` |

### Team pages
| File |
|---|
| `public/teams/index.php` |
| `public/teams/create.php` |
| `public/teams/edit.php` |
| `public/teams/delete.php` |
| `public/teams/members.php` |
| `public/teams/questions.php` |
| `public/teams/recipients.php` |
| `public/teams/dashboard.php` |
| `public/teams/responses.php` |

### Other pages
| File |
|---|
| `public/dashboard.php` |
| `public/submit.php` |
| `public/invitations/send.php` |
| `public/invitations/accept.php` |
| `public/admin/users.php` |

## Design System Reference

### Tailwind CDN (add to `<head>`)

```html
<script src="https://cdn.tailwindcss.com"></script>
```

No build step. Works server-side rendered PHP with no tooling.

### Colour tokens (Tailwind classes)

| Element | Class(es) |
|---|---|
| Page background | `bg-gray-50 min-h-screen` |
| Card / panel | `bg-white rounded-lg shadow-sm border border-gray-200` |
| Primary button | `bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg transition-colors` |
| Secondary button | `bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium py-2 px-4 rounded-lg transition-colors` |
| Danger button | `bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg transition-colors` |
| Small button variant | replace `py-2 px-4` with `py-1 px-3 text-sm` |
| Text input | `w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500` |
| Label | `block text-sm font-medium text-gray-700 mb-1` |
| Heading H1 | `text-2xl font-semibold text-gray-900` |
| Heading H2 | `text-xl font-semibold text-gray-900` |
| Body text | `text-sm text-gray-600` |
| Container | `max-w-5xl mx-auto px-4 py-6` |

### Flash messages

```html
<!-- Success -->
<div class="bg-green-50 border border-green-200 text-green-800 rounded-lg px-4 py-3 mb-4 text-sm">
    <?= h($flashMessage) ?>
</div>

<!-- Error -->
<div class="bg-red-50 border border-red-200 text-red-800 rounded-lg px-4 py-3 mb-4 text-sm">
    <?= h($errorMessage) ?>
</div>

<!-- Info / Pending -->
<div class="bg-blue-50 border border-blue-200 text-blue-800 rounded-lg px-4 py-3 mb-4 text-sm">
    <?= h($infoMessage) ?>
</div>
```

### Nav bar structure

```html
<nav class="bg-white border-b border-gray-200 shadow-sm sticky top-0 z-10">
    <div class="max-w-5xl mx-auto px-4 flex items-center justify-between h-14">
        <!-- Logo left -->
        <a href="/dashboard.php" class="flex items-center gap-2">
            <img src="/assets/logo.png" alt="AsyncStandUp" class="h-7 w-auto">
            <span class="font-semibold text-gray-900 text-sm">AsyncStandUp</span>
        </a>

        <!-- Nav links centre/right -->
        <div class="flex items-center gap-4">
            <a href="/orgs/index.php" class="text-sm text-gray-600 hover:text-indigo-600">Organisations</a>
            <a href="/dashboard.php" class="text-sm text-gray-600 hover:text-indigo-600">Dashboard</a>
        </div>

        <!-- User menu far right -->
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-500"><?= h($currentUser['display_name'] ?? $currentUser['email']) ?></span>
            <form method="POST" action="/logout.php" class="inline">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <button type="submit"
                    class="text-sm text-gray-500 hover:text-red-600 underline bg-transparent border-0 cursor-pointer p-0">
                    Log out
                </button>
            </form>
        </div>
    </div>
</nav>
```

Mobile: on screens `< sm` (640px), the centre links can wrap or be hidden behind a disclosure. Simple approach: use `hidden sm:flex` on the centre links and a `sm:hidden` toggle button. Avoid JavaScript if possible — a CSS-only hamburger using `<details>/<summary>` is acceptable.

### Team nav bar (Tailwind replacement for `templates/team-nav.php`)

```html
<div class="bg-white border-b border-gray-100 mb-6">
    <div class="max-w-5xl mx-auto px-4">
        <!-- Breadcrumb -->
        <nav class="flex text-xs text-gray-500 py-2 gap-1">
            <a href="/orgs/index.php" class="hover:text-indigo-600">Organisations</a>
            <span>/</span>
            <a href="/teams/index.php?org_id=<?= $orgId ?>" class="hover:text-indigo-600"><?= h($orgName) ?></a>
            <span>/</span>
            <span class="text-gray-900 font-medium"><?= h($teamName) ?></span>
        </nav>
        <!-- Tab links -->
        <div class="flex gap-1 -mb-px overflow-x-auto">
            <?php
            $tabs = [
                'dashboard'  => ['label' => 'Dashboard',  'href' => "/teams/dashboard.php?team_id={$teamId}",  'owner' => false],
                'responses'  => ['label' => 'Responses',  'href' => "/teams/responses.php?team_id={$teamId}",  'owner' => true],
                'members'    => ['label' => 'Members',    'href' => "/teams/members.php?team_id={$teamId}",    'owner' => true],
                'questions'  => ['label' => 'Questions',  'href' => "/teams/questions.php?team_id={$teamId}",  'owner' => true],
                'recipients' => ['label' => 'Recipients', 'href' => "/teams/recipients.php?team_id={$teamId}", 'owner' => true],
                'edit'       => ['label' => 'Settings',   'href' => "/teams/edit.php?team_id={$teamId}",       'owner' => true],
            ];
            foreach ($tabs as $key => $tab):
                if ($tab['owner'] && !$isOwner) continue;
                $active = $currentPage === $key;
            ?>
            <a href="<?= $tab['href'] ?>"
               class="px-4 py-2 text-sm whitespace-nowrap border-b-2 transition-colors
                      <?= $active
                          ? 'border-indigo-600 text-indigo-600 font-medium'
                          : 'border-transparent text-gray-500 hover:text-gray-900 hover:border-gray-300' ?>">
                <?= $tab['label'] ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
```

Tab-style underline navigation — active tab has `border-indigo-600` bottom border.

### Auth page layout (login / register)

```html
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <!-- Logo / title -->
        <div class="text-center mb-8">
            <h1 class="text-2xl font-semibold text-gray-900">AsyncStandUp</h1>
            <p class="text-sm text-gray-500 mt-1">Async standups for distributed teams</p>
        </div>
        <!-- Card -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8">
            <!-- form content here -->
        </div>
    </div>
</body>
```

### Status badges (admin page)

```php
function statusBadge(string $status): string {
    $classes = match($status) {
        'pending'  => 'bg-amber-100 text-amber-800',
        'approved' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-red-100   text-red-800',
        default    => 'bg-gray-100  text-gray-700',
    };
    return '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ' . $classes . '">'
         . htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8')
         . '</span>';
}
```

Rendered inline in the admin users table.

### `public/assets/style.css` — custom overrides only

After this story, `style.css` retains only classes Tailwind cannot express via utilities:

```css
/* Custom prose / layout not expressible in Tailwind utilities */
.pending-standups { /* Keep amber left-border from US-18 — Tailwind CDN can't use border-left shorthand easily */ }

/* Print media — no Tailwind print utility covers all needed cases */
@media print {
    nav, .team-nav, .no-print { display: none !important; }
    .container { max-width: 100%; padding: 0; }
}
```

### Dashboard — team card grid

```html
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php foreach ($teams as $team): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
        <h3 class="font-semibold text-gray-900 text-sm"><?= h($team['name']) ?></h3>
        <p class="text-xs text-gray-500 mt-1"><?= h($team['org_name']) ?></p>
        <div class="mt-3 flex gap-2">
            <a href="/teams/dashboard.php?team_id=<?= $team['id'] ?>"
               class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">View dashboard →</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
```

## Implementation Notes

- **CDN approach**: Tailwind CDN (play CDN) is acceptable for a self-hosted PHP application with no build pipeline. Performance trade-off is acceptable for this scope.
- **No JavaScript requirement**: All Tailwind utility classes work without JS. The only JS needed is an optional hamburger toggle — use `<details>/<summary>` for a zero-JS collapse on mobile.
- **Incremental approach**: Implement phase by phase (Phase 1 → 2 → 3 → 4 → 5). Each phase is self-contained; earlier phases can be reviewed visually before proceeding.
- **`statusBadge()` function**: define once in a shared include (e.g. `src/View.php` or inline in `admin/users.php`) — not duplicated across pages.
- **No logic changes**: This story touches only HTML structure and CSS classes. All PHP logic, session handling, form validation, and SQL queries are identical to their pre-redesign state.
- **Test**: Visual review at 375px (Chrome DevTools mobile preset) and 1280px. No automated UI tests for this story.
