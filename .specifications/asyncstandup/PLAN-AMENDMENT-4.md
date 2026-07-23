# PLAN-AMENDMENT-4 — Security Auditor M-1: orgs/delete.php access control

**Status**: APPROVED
**Branch**: feature/asyncstandup-tests-pwreset
**Commit**: cf929fd
**Raised by**: Code Reviewer (Cycle 2 scope check)

## Unplanned file modification

| Action | File | Risk | Note |
|---|---|---|---|
| Modify | `public/orgs/delete.php` | ⚠️ Path B | Add isOrgCreator() check + forbid() helper |

## Rationale

Security Auditor M-1 finding identified that orgs/delete.php was missing the
isOrgCreator() access control check applied to orgs/edit.php in US-11 (PLAN-AMENDMENT-2).
The fix adds the same guard pattern: isOrgMember() → isOrgCreator() → forbid() if not creator.
PLAN-AMENDMENT-2 listed orgs/edit.php but orgs/delete.php was omitted — plan-writing oversight.
No production behaviour change beyond enforcing the intended access control.
