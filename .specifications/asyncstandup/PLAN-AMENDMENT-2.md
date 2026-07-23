# PLAN-AMENDMENT-2 — US-10 extension + US-11 Access Control

**Status**: APPROVED (Team Lead pre-approved; user approved scope at 2026-07-23T11:30)
**Branch**: feature/asyncstandup-tests-pwreset
**Stories covered**: US-10 (extension), US-11 (new)

## Additional files beyond IMPL-PLAN-php-developer-us9-10.md

### US-10 extension — change password from profile

| Action | File | Risk | Note |
|---|---|---|---|
| Modify | `public/profile.php` | ⚠️ Path B | Add change-password form; changePassword() call |
| Modify | `src/Auth.php` | ⚠️ Path B | Add changePassword() function |

No new files — both already in the IMPL-PLAN file list for US-10.
changePassword(PDO $pdo, int $userId, string $current, string $new): bool

### US-11 — Access control

| Action | File | Risk | Note |
|---|---|---|---|
| Modify | `public/teams/dashboard.php` | ⚠️ Path B | Add is_owner check → forbid() |
| Modify | `public/teams/index.php` | ⚠️ Path B | Hide dashboard link if !is_owner |
| Modify | `public/orgs/edit.php` | ⚠️ Path B | Add isOrgCreator() check → forbid() |
| Modify | `public/teams/edit.php` | ⚠️ Path B | Verify isTeamOwner() → forbid() (may already be present) |
| Modify | `src/OrgRepository.php` | ⚠️ Path B | Add isOrgCreator(PDO, int, int): bool |
| Modify | `src/Auth.php` | ⚠️ Path B | Add forbid(): never helper |
