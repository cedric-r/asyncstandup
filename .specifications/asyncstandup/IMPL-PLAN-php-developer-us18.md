# IMPL-PLAN — PHP Developer
## US-18: Pending Standups on Dashboard Landing Page

**Status**: APPROVED
**Branch**: `feature/asyncstandup-dashboard-pending`
**Agent**: PHP Developer

---

## File List (exhaustive — 5 files)

| Action | File | Path B? |
|---|---|---|
| Modify | `src/DashboardRepository.php` | ⚠️ Yes — additive: new function only |
| Modify | `public/dashboard.php` | ⚠️ Yes — additive: pending section prepended |
| Modify | `public/assets/style.css` | ⚠️ Yes — additive: `.pending-standups` rules appended |
| Modify | `tests/DashboardRepositoryTest.php` | ⚠️ Yes — pre-listed per US-14 RETRO lesson |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us18.md` | No — this file |

**All Path B modifications are purely additive.** No characterisation commit required.

---

## New Function

### `src/DashboardRepository.php`

```php
function getPendingTokensForUser(PDO $pdo, int $userId): array
```
Returns all unexpired, unsubmitted standup tokens for `$userId` where the user is a developer on the team.

Query filters:
- `used_at IS NULL` — not yet submitted
- `datetime(expires_at) > datetime('now')` — not expired; `datetime()` wrapper for SQLite ISO 8601 compat (US-6 pattern)
- `tm.is_developer = 1` — via JOIN on `team_members` (not WHERE — same table, same row)

Returns: `[['token' => string, 'send_date' => string, 'team_name' => string, 'timezone' => string], ...]` ordered by `team_name ASC`.

---

## Test Plan — 5 cases in `tests/DashboardRepositoryTest.php`

| # | Method | Setup | Expected |
|---|---|---|---|
| 1 | `testGetPendingTokensReturnsUnsubmittedToken` | Valid token, used_at=NULL, expires future, is_developer=1 | 1 row with correct token |
| 2 | `testGetPendingTokensExcludesUsedToken` | Token with used_at set | 0 rows |
| 3 | `testGetPendingTokensExcludesExpiredToken` | Token with expires_at in past | 0 rows |
| 4 | `testGetPendingTokensExcludesNonDeveloper` | team_member with is_developer=0 | 0 rows |
| 5 | `testGetPendingTokensReturnsMultipleTeams` | Two pending tokens for different teams | 2 rows, ordered by team_name |

---

## Self-Check

- [ ] `datetime()` wrapper on `expires_at` — SQLite-compat
- [ ] `is_developer = 1` filter in JOIN condition (not WHERE)
- [ ] Section rendered only when `$pendingTokens` is non-empty
- [ ] `htmlspecialchars(ENT_QUOTES, 'UTF-8')` on token + team_name in output
- [ ] CSS `.pending-standups` rules appended to style.css
- [ ] 5 test cases cover: happy path, used token, expired, non-developer, multiple teams
- [ ] No new DB tables or schema changes
- [ ] All existing tests still pass
