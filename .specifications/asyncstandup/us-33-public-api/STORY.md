# US-33: Public REST API

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-33-public-api`

---

## Story

**As a** developer or integrator  
**I want** a JSON REST API authenticated by a per-user API key  
**So that** I can read team standup data and submit standups programmatically from external tools

---

## Acceptance Criteria

### AC-1 — Schema: `api_keys` table + `api_request_log` for rate limiting

```sql
-- db/schema.sql (append)
CREATE TABLE IF NOT EXISTS api_keys (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    key_hash   VARCHAR(64) NOT NULL UNIQUE,    -- SHA-256 hex of the raw key
    label      VARCHAR(100) NULL,              -- optional human name
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    last_used_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_request_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    key_hash    VARCHAR(64) NOT NULL,
    requested_at DATETIME NOT NULL,
    INDEX idx_api_log_key_time (key_hash, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

SQLite (`tests/schema-sqlite.sql`): `INTEGER PRIMARY KEY AUTOINCREMENT`, `TEXT`, `DEFAULT ''`, no `ENGINE`.

---

### AC-2 — `src/ApiAuth.php` — API key authentication + rate limiting

```php
/**
 * Authenticate from Authorization: Bearer <key> header.
 * Returns the api_keys row (including user_id) or null if invalid.
 */
function authenticateApiKey(PDO $pdo): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) { return null; }

    $rawKey  = trim(substr($header, 7));
    $keyHash = hash('sha256', $rawKey);

    $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE key_hash = ?');
    $stmt->execute([$keyHash]);
    $row = $stmt->fetch();
    if (!$row) { return null; }

    // Update last_used_at
    $pdo->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s'), $row['id']]);

    return $row;
}

/**
 * Check rate limit: 100 requests per hour per key_hash.
 * Returns true if within limit; false if exceeded.
 */
function checkRateLimit(PDO $pdo, string $keyHash): bool
{
    $oneHourAgo = gmdate('Y-m-d H:i:s', time() - 3600);
    $count = $pdo->prepare('SELECT COUNT(*) FROM api_request_log WHERE key_hash = ? AND requested_at > ?');
    $count->execute([$keyHash, $oneHourAgo]);
    if ((int) $count->fetchColumn() >= 100) { return false; }

    $pdo->prepare('INSERT INTO api_request_log (key_hash, requested_at) VALUES (?, ?)')
        ->execute([$keyHash, gmdate('Y-m-d H:i:s')]);
    return true;
}
```

---

### AC-3 — `src/ApiResponse.php` — JSON response helpers

```php
function apiOk(array|object $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function apiList(array $items, int $page, int $perPage, int $total): never
{
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'data' => $items,
        'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function apiError(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
```

---

### AC-4 — `src/ApiRouter.php` — path dispatcher

```php
function routeApi(string $method, string $path, PDO $pdo, array $apiKey): never
{
    // Normalise: strip /api/v1 prefix and trailing slash
    $path = rtrim(preg_replace('#^/api/v1#', '', $path), '/');

    // GET /teams
    if ($method === 'GET' && $path === '/teams') { handleGetTeams($pdo, $apiKey); }

    // GET /teams/{id}/questions
    if ($method === 'GET' && preg_match('#^/teams/(\d+)/questions$#', $path, $m)) {
        handleGetQuestions($pdo, $apiKey, (int) $m[1]);
    }

    // GET /teams/{id}/submissions
    if ($method === 'GET' && preg_match('#^/teams/(\d+)/submissions$#', $path, $m)) {
        handleGetSubmissions($pdo, $apiKey, (int) $m[1]);
    }

    // GET /teams/{id}/submissions/{sid}
    if ($method === 'GET' && preg_match('#^/teams/(\d+)/submissions/(\d+)$#', $path, $m)) {
        handleGetSubmission($pdo, $apiKey, (int) $m[1], (int) $m[2]);
    }

    // POST /teams/{id}/submissions
    if ($method === 'POST' && preg_match('#^/teams/(\d+)/submissions$#', $path, $m)) {
        handlePostSubmission($pdo, $apiKey, (int) $m[1]);
    }

    apiError('Not found', 404);
}
```

---

### AC-5 — `public/api/v1/index.php` — entry point

```php
<?php
declare(strict_types=1);

$config = require __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../src/Db.php';
require_once __DIR__ . '/../../../src/ApiAuth.php';
require_once __DIR__ . '/../../../src/ApiResponse.php';
require_once __DIR__ . '/../../../src/ApiRouter.php';
require_once __DIR__ . '/../../../src/TeamRepository.php';
require_once __DIR__ . '/../../../src/DashboardRepository.php';
require_once __DIR__ . '/../../../src/SubmissionRepository.php';

$pdo    = getDb($config);
$apiKey = authenticateApiKey($pdo);
if ($apiKey === null) { apiError('unauthorized', 401); }

if (!checkRateLimit($pdo, $apiKey['key_hash'])) {
    header('Retry-After: 3600');
    apiError('rate limit exceeded', 429);
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

routeApi($method, $path, $pdo, $apiKey);
```

URL routing: add to `public/.htaccess`:
```apache
RewriteRule ^api/v1/(.*)$ api/v1/index.php [L,QSA]
```

---

### AC-6 — Endpoint implementations in `src/ApiRouter.php`

**`handleGetTeams`** — returns teams the API key's user is a member of (reuse `getTeamsForUser()`). Strips internal fields.

**`handleGetQuestions`** — validates team membership; returns questions from `getQuestions()`.

**`handleGetSubmissions`** — validates team membership; reads `?page`, `?per_page`, `?from`, `?to`; uses `getResponseData()` or a new `getSubmissionsForApi()` function; returns paginated list.

**`handleGetSubmission`** — validates team membership + submission belongs to team; returns single submission with answers.

**`handlePostSubmission`** — validates developer membership; reads JSON body (`{"answers":[{"question_id":1,"text":"..."}]}`); inserts token (if no token exists for today — or allows tokenless API submission); inserts submission + answers; returns 201.

---

### AC-7 — API key management UI: `public/profile/api-keys.php` (minimal)

Accessible from the user profile page. Allows:
- View existing API keys (label + last_used_at, never the raw key)
- Generate a new API key (raw key shown once; stored as hash)
- Revoke an API key

Key generation:
```php
$rawKey  = bin2hex(random_bytes(32));  // 64-char hex key
$keyHash = hash('sha256', $rawKey);
// Store $keyHash; display $rawKey once
```

---

### AC-8 — PHPUnit tests: 5 new tests

New test class `tests/PublicApiTest.php`:

| Test | What it verifies |
|---|---|
| `testUnauthenticatedRequestReturns401` | No `Authorization` header → `authenticateApiKey()` returns null |
| `testValidApiKeyAuthenticates` | Insert key_hash; send matching raw key → returns api_keys row |
| `testTeamListFilteredToUserMemberships` | API key user is member of 2 teams; non-member team excluded from `handleGetTeams` result |
| `testSubmissionListPaginates` | Insert 25 submissions; `?page=2&per_page=10` → returns items 11–20 |
| `testRateLimitTriggersAfter100Requests` | Insert 100 log entries within the hour; `checkRateLimit()` returns false on 101st call |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Add `api_keys` + `api_request_log` tables |
| `db/schema-postgresql.sql` | Same |
| `tests/schema-sqlite.sql` | SQLite equivalents |
| `src/ApiAuth.php` (new) | `authenticateApiKey()`, `checkRateLimit()` |
| `src/ApiResponse.php` (new) | `apiOk()`, `apiList()`, `apiError()` |
| `src/ApiRouter.php` (new) | `routeApi()` + 5 handler functions |
| `public/api/v1/index.php` (new) | Entry point |
| `public/api/v1/` (new dir) | |
| `public/.htaccess` | Add API rewrite rule |
| `public/profile/api-keys.php` (new) | Key management UI |
| `tests/PublicApiTest.php` (new) | 5 PHPUnit tests |
