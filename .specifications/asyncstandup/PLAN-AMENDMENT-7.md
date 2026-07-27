# PLAN-AMENDMENT-7 — US-17: Admin new-registration notification email

**Status**: APPROVED
**Branch**: feature/asyncstandup-admin

## Additional files

| Action | File | Note |
|---|---|---|
| Create | `templates/email/admin_new_registration.php` | Notify admins of new pending registration |
| Modify | `public/register.php` | After INSERT, query is_admin=1 users and send notification |

## Rationale

New requirement added during implementation: all admins must receive an email
notification when a new user registers. This was not in the original IMPL-PLAN
brief. The email is a notification only — no one-click approve link (security
concern). Admin clicks `/admin/users.php` link to act.
