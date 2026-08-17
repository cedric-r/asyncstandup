# IMPL-PLAN — US-33: Public REST API

**Status**: PENDING GATE C APPROVAL
**Branch**: `feature/us-33-public-api`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-33 — Public REST API

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-8 and TASKS.md T-1 through T-11.

No new Composer dependencies. 11 files created, 3 modified.

---

## Files to Create or Modify

| File | Type | Change |
|---|---|---|
| `tests/schema-sqlite.sql` | Modify | Append `api_keys` + `api_request_log` CREATE TABLE (SQLite) |
| `db/schema.sql` | Modify | Append `api_keys` + `api_request_log` CREATE TABLE (MySQL) |
| `db/schema-postgresql.sql` | Modify | Append `api_keys` + `api_request_log` CREATE TABLE (PostgreSQL) |
| `src/ApiAuth.php` | Create | `authenticateApiKey()`, `checkRateLimit()` |
| `src/ApiResponse.php` | Create | `apiOk()`, `apiList()`, `apiError()` |
| `src/ApiRouter.php` | Create | `routeApi()` + 5 handlers |
| `public/api/v1/index.php` | Create | Entry point |
| `public/.htaccess` | Create | Apache rewrite rule for API routing |
| `public/profile/api-keys.php` | Create | Key management UI |
| `tests/PublicApiTest.php` | Create | 5 PHPUnit tests |
| `tests/bootstrap.php` | Modify | Add `require_once src/ApiAuth.php` |

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `getTeamsForUser(PDO, int): array` | ✓ exists — `src/DashboardRepository.php` line 8 |
| `isTeamMember(PDO, int, int): bool` | ✓ exists — `src/TeamRepository.php` line 168 |
| `isDeveloperMember(PDO, int, int): bool` | ✓ exists — `src/TeamRepository.php` line 176 |
| `getResponseData()` | ✓ exists — returns flat JOIN rows (needs pivoting for API output) |
| `createStandupToken()` | ✓ exists — `src/StandupEmailer.php` line 80; used for POST submissions |
| `saveSubmission()` | ✓ exists — `src/SubmissionRepository.php` line 53; takes `$tokenId` (NOT NULL) |
| `public/.htaccess` | Does NOT exist — must create |
| `public/profile/` | Does NOT exist — must create dir + file |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-33-public-api` created from `main`.

---

### T-2 — Schema: `api_keys` + `api_request_log` (AC-1)

**`tests/schema-sqlite.sql`** — append:
```sql
CREATE TABLE IF NOT EXISTS api_keys (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    key_hash     TEXT NOT NULL UNIQUE,
    label        TEXT NULL,
    created_at   TEXT NOT NULL DEFAULT '',
    last_used_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS api_request_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash     TEXT NOT NULL,
    requested_at TEXT NOT NULL
);
```

**`db/schema.sql`** — append MySQL versions with `UTC_TIMESTAMP()` defaults, INDEX, ENGINE=InnoDB (from STORY.md AC-1).

**`db/schema-postgresql.sql`** — append PostgreSQL version: `SERIAL PRIMARY KEY`, `TIMESTAMP`, `DEFAULT NOW()`, `CREATE INDEX`.

---

### T-3 — Create `src/ApiAuth.php` (AC-2)

Exact implementation from STORY.md AC-2. Key points:
- `authenticateApiKey()`: reads `$_SERVER['HTTP_AUTHORIZATION']`, hashes raw key with SHA-256, queries `api_keys`, updates `last_used_at` on success
- `checkRateLimit()`: count `api_request_log` entries for key in last 3600s; return false if ≥100; else INSERT log row and return true

---

### T-4 — Create `src/ApiResponse.php` (AC-3)

Exact implementation from STORY.md AC-3. All three functions are `never` (call `exit` after output).

---

### T-5 — Create `src/ApiRouter.php` (AC-4, AC-6)

`routeApi()` dispatcher + 5 handler functions:

**`handleGetTeams`**: `getTeamsForUser($pdo, $apiKey['user_id'])` → map to `[id, name, timezone, standup_time, org_name]` → `apiOk($out)`.

**`handleGetQuestions`**: `isTeamMember()` guard → `getQuestions($pdo, $teamId)` → map to `[id, question, position]` → `apiOk()`.

**`handleGetSubmissions`**: `isTeamMember()` guard → read `?page`, `?per_page` (max 50), `?from`, `?to` → call `getResponseData($pdo, $teamId, null, $memberId=null, $from, $to)` → pivot flat rows into per-submission array → paginate with `array_slice()` → `apiList($items, $page, $perPage, $total)`.

**`handleGetSubmission`**: `isTeamMember()` guard → load single submission + answers from `standup_submissions`/`standup_answers` WHERE `id = $submissionId AND team_id = $teamId` → `apiError('not found', 404)` if missing → `apiOk($data)`.

**`handlePostSubmission`**: `isDeveloperMember()` guard → read + decode JSON body → validate `answers` array → check for existing token today via `hasSentTokenToday()` or query; if none: call `createStandupToken($pdo, $teamId, $userId, $today, $nowUtc)` → `saveSubmission($pdo, $tokenId, $userId, $teamId, $answersMap)` → `apiOk(['submission_id' => $id], 201)`.

Note: `standup_submissions.token_id` is NOT NULL UNIQUE, so API submissions must always have a token. `createStandupToken()` handles the UNIQUE constraint via INSERT IGNORE if a token already exists for the day — re-fetch if so.

---

### T-6 — Create `public/api/v1/index.php` (AC-5)

Exact content from STORY.md AC-5. Creates dir `public/api/v1/`.

---

### T-7 — Create `public/.htaccess` (AC-5)

No existing `.htaccess`. Create new file:
```apache
RewriteEngine On
RewriteBase /

# API routing
RewriteRule ^api/v1/(.*)$ api/v1/index.php [L,QSA]
```

---

### T-8 — Create `public/profile/api-keys.php` (AC-7)

New directory `public/profile/`. Page follows pattern of other public/ pages:
- `require_once` chain: `config.php`, `Db.php`, `Auth.php`, `Csrf.php`
- `requireLogin()` + `startSession()` at top
- **GET**: list user's keys with `SELECT id, label, last_used_at, created_at FROM api_keys WHERE user_id = ?`
- **POST `generate`**: `$rawKey = bin2hex(random_bytes(32))`, `$keyHash = hash('sha256', $rawKey)`, insert row, display raw key once in a styled notice
- **POST `revoke`**: `DELETE FROM api_keys WHERE id = ? AND user_id = ?` (scoped to user)
- All POST actions: CSRF check via `validateCsrfToken()`

---

### T-9 — Create `tests/PublicApiTest.php` (AC-8)

5 tests exactly as specified in TASKS.md T-9:
1. `testNoAuthHeaderReturnsNull` — unset `$_SERVER['HTTP_AUTHORIZATION']` → null
2. `testValidKeyAuthenticates` — insert hash, set Bearer header → non-null row with matching key_hash
3. `testInvalidKeyReturnsNull` — random Bearer → null
4. `testRateLimitAllows100Requests` — 99 pre-inserted log entries → `checkRateLimit()` true (100th)
5. `testRateLimitBlocks101stRequest` — 100 pre-inserted log entries → `checkRateLimit()` false

Add `require_once src/ApiAuth.php` to `tests/bootstrap.php`.

---

### T-10 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥100 tests (95 prior + 5 new), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors.

---

### T-11 — Commit

Single commit, all files:
`feat(us-33): public REST API — api_keys, 5 endpoints, rate limiting, key management UI`

---

## Risk Notes

1. **`handlePostSubmission` token requirement** — `standup_submissions.token_id NOT NULL UNIQUE`. API submissions must create a token via `createStandupToken()`. If a token already exists for today (from email), re-fetch it via `SELECT id FROM standup_tokens WHERE team_id=? AND user_id=? AND send_date=?` before proceeding.
2. **`getResponseData()` returns flat rows** — the pivot logic (group by submission_id, collect answers by question_id) must be added inline in `handleGetSubmissions` and `handleGetSubmission`. Reuse the same pivot pattern used in `responses.php`.
3. **`apiOk()` / `apiError()` are `never` — PHPStan** — `never` return type on functions that `exit` is correct PHP 8.1+ syntax; PHPStan level 5 accepts this.
4. **`public/.htaccess` does not exist** — Creating fresh. No risk of breaking existing routes since only the `api/v1/` path is captured.
5. **`validateCsrfToken()` function name** — Must verify the exact CSRF helper function name used across other profile pages before T-8.
