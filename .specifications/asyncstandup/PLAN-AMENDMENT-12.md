# PLAN-AMENDMENT-12 — register.php invite fix between US-22 and US-23

**Status**: APPROVED
**Branch**: feature/asyncstandup-admin-delete

## Unplanned file

| Action | File | Note |
|---|---|---|
| Modify | `public/register.php` | Post-US-17 bug fix: restore acceptInvitationForUser() call on registration (commit 76bc3bf); applied as hot-fix before US-23 IMPL-PLAN |

## Rationale

Bug fix restoring invitation acceptance at registration time. Committed to main
between US-22 and US-23 merges. Appears in this branch's diff due to ancestry.
No scope addition to US-23; pure bug fix for a regression introduced in US-17.
