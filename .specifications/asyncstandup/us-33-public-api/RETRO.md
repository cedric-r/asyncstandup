# RETRO — US-33: Public REST API

**Story**: US-33 — Public REST API
**Branch**: `feature/us-33-public-api`
**Merge commit**: `4ce051c`
**Review cycles**: 2
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `tests/schema-sqlite.sql` | Appended `api_keys` + `api_request_log` CREATE TABLE (SQLite) |
| `db/schema.sql` | Appended `api_keys` + `api_request_log` CREATE TABLE (MySQL, ENGINE=InnoDB, INDEX) |
| `db/schema-postgresql.sql` | Appended `api_keys` + `api_request_log` (SERIAL, TIMESTAMP, CREATE INDEX) |
| `src/ApiAuth.php` | `authenticateApiKey()` (Bearer → SHA-256 → lookup → last_used_at update) + `checkRateLimit()` (100 req/h sliding window) |
| `src/ApiResponse.php` | `apiOk()`, `apiList()` (data + meta pagination), `apiError()` — all `never` |
| `src/ApiRouter.php` | `routeApi()` dispatcher + 5 handlers (GET teams, GET questions, GET/POST submissions, GET submission by id) |
| `public/api/v1/index.php` | Entry point: auth → rate-limit → routeApi() |
| `public/.htaccess` | Created fresh: `RewriteRule ^api/v1/(.*)$ api/v1/index.php [L,QSA]` |
| `public/profile/api-keys.php` | List/generate/revoke UI; raw key shown once on generate; CSRF protected |
| `tests/PublicApiTest.php` | 5 tests: no auth → null, valid key, team list filter, submissions pagination, rate limit boundary |

**Test result**: 101 tests, 194 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**2 cycles**

---

## Cycle 1 → Cycle 2 findings

**CRITICAL — `handlePostSubmission()` TypeError** (reviewer)
`createStandupToken()` returns `?string` (hex token string), not an int. Code was passing the string directly as `$tokenId` to `saveSubmission(PDO, int $tokenId, ...)`. With `strict_types=1`, this throws `TypeError` at runtime.

Fix: after `createStandupToken()` returns non-null string, SELECT the integer PK:
```php
$idStmt = $pdo->prepare('SELECT id FROM standup_tokens WHERE team_id = ? AND user_id = ? AND send_date = ?');
$idStmt->execute([$teamId, $userId, $sendDate]);
$tokenId = (int) $idStmt->fetchColumn();
```

**SHOULD-FIX — Tests deviated from AC-8 spec** (reviewer)
`testInvalidKeyReturnsNull` not in AC-8. Replaced with:
- `testTeamListFilteredToUserMemberships` — `getTeamsForUser()` returns only user's teams
- `testSubmissionListPaginates` — pivot + `array_slice` returns correct page/total

Handler functions (`handleGetTeams`, `handleGetSubmissions`) call `apiOk()`/`apiList()` which invoke `exit` — direct PHPUnit calls terminate the process. Tests exercise the same underlying data logic (filter + pivot + pagination) that the handlers delegate to.

---

## Notes

1. **`public/.htaccess` created fresh** — no existing file; only API rewrite rule added. Includes `RewriteCond %{REQUEST_FILENAME} !-f` to let direct file requests pass through unaffected.
2. **Rate limit boundary confirmed** — `checkRateLimit()` COUNTs BEFORE inserting. 99 pre-inserted → count=99 < 100 → allowed (100th). 100 pre-inserted → count=100 ≥ 100 → blocked.
3. **CSRF function name** — `validateCsrfToken(string): void` and `generateCsrfToken(): string` confirmed from `src/Csrf.php` before writing `api-keys.php`.
4. **`standup_submissions.token_id NOT NULL UNIQUE`** — API POST submissions must always create or reuse a token. Re-fetch logic: SELECT existing token for today first; only call `createStandupToken()` if none exists; then fetch int PK. 409 returned if submission already recorded for that token.
