# IMPL-PLAN — PHP Developer
## US-19: UI Redesign with Tailwind CSS

**Status**: APPROVED
**Branch**: `feature/asyncstandup-ui`
**Agent**: PHP Developer

---

## File List (exhaustive — 28 files)

All modifications are cosmetic (HTML class/markup changes) — Path B but no characterisation required (no logic change).

### Layout partials
| Action | File |
|---|---|
| Modify | `templates/layout.php` |
| Modify | `templates/team-nav.php` |
| Modify | `public/assets/style.css` |

### Auth pages
| Action | File |
|---|---|
| Modify | `public/login.php` |
| Modify | `public/register.php` |
| Modify | `public/profile.php` |
| Modify | `public/forgot-password.php` |
| Modify | `public/reset-password.php` |

### Org pages
| Action | File |
|---|---|
| Modify | `public/orgs/index.php` |
| Modify | `public/orgs/create.php` |
| Modify | `public/orgs/edit.php` |
| Modify | `public/orgs/delete.php` |

### Team pages
| Action | File |
|---|---|
| Modify | `public/teams/index.php` |
| Modify | `public/teams/create.php` |
| Modify | `public/teams/edit.php` |
| Modify | `public/teams/delete.php` |
| Modify | `public/teams/members.php` |
| Modify | `public/teams/questions.php` |
| Modify | `public/teams/recipients.php` |
| Modify | `public/teams/dashboard.php` |
| Modify | `public/teams/responses.php` |

### Other pages
| Action | File |
|---|---|
| Modify | `public/dashboard.php` |
| Modify | `public/submit.php` |
| Modify | `public/invitations/send.php` |
| Modify | `public/invitations/accept.php` |
| Modify | `public/admin/users.php` |

### Spec
| Action | File |
|---|---|
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us19.md` |

**No `src/` files are touched. No DB changes. No session or query logic.**

---

## Tailwind Integration

`templates/layout.php` `<head>`:
```html
<script src="https://cdn.tailwindcss.com"></script>
```
Play CDN — no build step. Includes all Tailwind utility classes on demand.

---

## Design System Classes

| Element | Classes |
|---|---|
| Card | `bg-white rounded-lg shadow-sm border border-gray-200 p-6` |
| Primary button | `bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm` |
| Secondary button | `bg-white hover:bg-gray-50 text-gray-700 font-medium py-2 px-4 rounded-lg text-sm border border-gray-300` |
| Danger button | `bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg text-sm` |
| Input | `w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none` |
| Label | `block text-sm font-medium text-gray-700 mb-1` |
| Container | `max-w-5xl mx-auto px-4 py-8` |
| Flash success | `bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-6` |
| Flash error | `bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6` |
| Status badge pending | `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800` |
| Status badge approved | `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800` |
| Status badge rejected | `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800` |

---

## `style.css` Retained Rules

Strip all hand-written component/layout CSS. Retain:
1. `@media print` rules (for report pages)
2. `.pending-standups` amber section (amber styling is in Tailwind but the left-border + specific amber palette is preserved)

---

## Additional Requirement (added during implementation)

Nav bar must appear on **every page** including previously nav-suppressed pages.
- `templates/layout.php`: removed `$hideNav` conditional — nav always renders
- Nav is context-aware: when `$currentUser` is not set, shows logo + "Log in" / "Register" links
- `$hideNav = true` removed from: `login.php`, `register.php`, `forgot-password.php`, `reset-password.php`, `submit.php`, `invitations/accept.php` and all inline error templates

---

## Self-Check

- [ ] Tailwind CDN `<script>` in `layout.php` `<head>`
- [ ] No `src/*.php` files modified
- [ ] No DB, session, or query logic changes
- [ ] All 48 existing tests pass (no src/ changes)
- [ ] `htmlspecialchars()` / `h()` calls preserved on all output
- [ ] Mobile: `<details>/<summary>` or responsive Tailwind classes for nav
- [ ] Admin status badges: amber/green/red per account_status
- [ ] Flash messages: success=green, error=red, info/pending=blue-amber
