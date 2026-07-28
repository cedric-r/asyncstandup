# PLAN-AMENDMENT-9 — Post-merge hot-fixes for US-16/17 in branch diff

**Status**: APPROVED
**Branch**: feature/asyncstandup-dashboard-pending

## Unplanned file modifications

| Action | File | Note |
|---|---|---|
| Modify | `public/admin/users.php` | Hot-fix: reject cascade FK safety, transaction, debug log label |
| Modify | `src/Auth.php` | Hot-fix: team_recipients.added_by added to deleteUserAccount() cascade |
| Modify | `src/View.php` | Hot-fix: renderEmailTemplate() helper to fix empty email bodies |
| Modify | `public/register.php` | Hot-fix: use renderEmailTemplate() for admin notification email |
| Modify | `templates/layout.php` | Hot-fix: Admin nav link for is_admin users |

## Rationale

These files were modified in hot-fix commits on `main` after the US-16/17 merge
(`feature/asyncstandup-admin`). They appear in this branch's diff because
`feature/asyncstandup-dashboard-pending` was branched from `main` after those
hot-fixes landed. The modifications fix confirmed production bugs in US-16/17
(reject FK violations, missing team_recipients cleanup, empty email bodies).
No US-18 functionality is changed by these files.
