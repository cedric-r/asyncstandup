# TASKS — US-33: Public REST API

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-33-public-api`  
**Agent**: PHP Developer (`fa2e6dbf`)

---

## Phase 1 — Branch + schema (AC-1)

**T-1** `backend-dev` — Create branch
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout -b feature/us-33-public-api
```

**T-2** `backend-dev` — Add `api_keys` and `api_request_log` to all 3 schema files

`db/schema.sql` — append MySQL CREATE TABLE statements (see STORY.md AC-1).

`tests/schema-sqlite.sql` — append SQLite equivalents:
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

`db/schema-postgresql.sql` — append PostgreSQL version (`SERIAL`, `TIMESTAMP`, `CONSTRAINT`).

---

## Phase 2 — Core support files (AC-2, AC-3, AC-4)

**T-3** `backend-dev` — Create `src/ApiAuth.php`

Full implementation from STORY.md AC-2 — `authenticateApiKey()` and `checkRateLimit()`.

**T-4** `backend-dev` — Create `src/ApiResponse.php`

Full implementation from STORY.md AC-3 — `apiOk()`, `apiList()`, `apiError()`. All functions have `never` return type (they `exit` after output).

**T-5** `backend-dev` — Create `src/ApiRouter.php`

`routeApi()` dispatcher from STORY.md AC-4 + all 5 handler functions (AC-6):

`handleGetTeams(PDO $pdo, array $apiKey): never`
```php
$teams = getTeamsForUser($pdo, (int) $apiKey['user_id']);
$out   = array_map(fn($t) => [
    'id' => (int)$t['id'], 'name' => $t['name'],
    'timezone' => $t['timezone'], 'standup_time' => substr($t['standup_time'], 0, 5),
    'org_name' => $t['org_name'],
], $teams);
apiOk($out);
```

`handleGetQuestions(PDO $pdo, array $apiKey, int $teamId): never`
```php
if (!isTeamMember($pdo, $teamId, (int)$apiKey['user_id'])) { apiError('forbidden', 403); }
$questions = getQuestions($pdo, $teamId);
apiOk(array_map(fn($q) => ['id'=>(int)$q['id'],'question'=>$q['question'],'position'=>(int)$q['position']], $questions));
```

`handleGetSubmissions(PDO $pdo, array $apiKey, int $teamId): never`
```php
if (!isTeamMember($pdo, $teamId, (int)$apiKey['user_id'])) { apiError('forbidden', 403); }
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
$from    = $_GET['from'] ?? null;
$to      = $_GET['to']   ?? null;
// Validate date format if provided
$teamTz  = (new PDO(...))->... // load team to get timezone
// Use getResponseData() with date range; paginate in PHP (or add SQL LIMIT/OFFSET variant)
// Return apiList($items, $page, $perPage, $total);
```

Note: `getResponseData()` doesn't paginate natively — for v1, load all matching rows then `array_slice()`. If performance is a concern, add a `getSubmissionsApi(PDO, int $teamId, ?string $from, ?string $to, int $limit, int $offset): array` function that adds LIMIT/OFFSET.

`handleGetSubmission(PDO $pdo, array $apiKey, int $teamId, int $submissionId): never`
```php
if (!isTeamMember($pdo, $teamId, (int)$apiKey['user_id'])) { apiError('forbidden', 403); }
// Load standup_submissions + standup_answers + standup_tokens WHERE team_id = $teamId AND submission.id = $submissionId
// If not found or wrong team: apiError('not found', 404);
// Return apiOk([...]);
```

`handlePostSubmission(PDO $pdo, array $apiKey, int $teamId): never`
```php
if (!isDeveloperMember($pdo, $teamId, (int)$apiKey['user_id'])) { apiError('forbidden', 403); }
$body = json_decode(file_get_contents('php://input'), true);
if (!isset($body['answers']) || !is_array($body['answers'])) { apiError('invalid body', 400); }
// Insert into standup_submissions and standup_answers
// Use existing saveSubmission() / similar functions from SubmissionRepository.php
// Return apiOk(['submission_id' => $id], 201);
```

---

## Phase 3 — Entry point + routing (AC-5)

**T-6** `backend-dev` — Create `public/api/v1/` directory and `index.php`

Full content from STORY.md AC-5.

**T-7** `backend-dev` — Add rewrite rule to `public/.htaccess`

```apache
# API routing — must be before other RewriteRules
RewriteRule ^api/v1/(.*)$ api/v1/index.php [L,QSA]
```

Verify existing `.htaccess` has `RewriteEngine On` and `RewriteBase /` (or equivalent). Do not break existing routes.

---

## Phase 4 — API key management UI (AC-7)

**T-8** `backend-dev` — Create `public/profile/api-keys.php`

Structure mirrors `public/profile/` pattern (if profile pages exist) or is a standalone page:

**GET** — list existing keys (label + last_used_at; no raw key); link from user nav.

**POST `action=generate`** — generate raw key, store hash, show raw key once in a flash-style notice with: "This key will only be shown once. Copy it now."

**POST `action=revoke&key_id=N`** — DELETE FROM api_keys WHERE id = ? AND user_id = ? (scoped to current user).

Authentication: `startSession(); requireLogin();` — same as all other pages.

---

## Phase 5 — Tests (AC-8)

**T-9** `backend-dev` — Create `tests/PublicApiTest.php` (5 tests)

```php
class PublicApiTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");
    }

    public function testNoAuthHeaderReturnsNull(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $result = authenticateApiKey($this->pdo);
        $this->assertNull($result);
    }

    public function testValidKeyAuthenticates(): void
    {
        $raw  = 'testkey123';
        $hash = hash('sha256', $raw);
        $this->pdo->exec("INSERT INTO api_keys (user_id, key_hash) VALUES (1, '$hash')");
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $raw";
        $result = authenticateApiKey($this->pdo);
        $this->assertNotNull($result);
        $this->assertEquals($hash, $result['key_hash']);
    }

    public function testInvalidKeyReturnsNull(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer notavalidkey';
        $result = authenticateApiKey($this->pdo);
        $this->assertNull($result);
    }

    public function testRateLimitAllows100Requests(): void
    {
        $hash = hash('sha256', 'testkey');
        $this->pdo->exec("INSERT INTO api_keys (user_id, key_hash) VALUES (1, '$hash')");
        // Insert 99 log entries within the last hour
        $ts = gmdate('Y-m-d H:i:s', time() - 60);
        for ($i = 0; $i < 99; $i++) {
            $this->pdo->exec("INSERT INTO api_request_log (key_hash, requested_at) VALUES ('$hash', '$ts')");
        }
        $this->assertTrue(checkRateLimit($this->pdo, $hash));  // 100th request — allowed
    }

    public function testRateLimitBlocks101stRequest(): void
    {
        $hash = hash('sha256', 'testkey2');
        $ts   = gmdate('Y-m-d H:i:s', time() - 60);
        for ($i = 0; $i < 100; $i++) {
            $this->pdo->exec("INSERT INTO api_request_log (key_hash, requested_at) VALUES ('$hash', '$ts')");
        }
        $this->assertFalse(checkRateLimit($this->pdo, $hash));
    }
}
```

**T-10** `backend-dev` — Run full test suite; target ≥99 tests (94 prior + 5 new)

---

## Phase 6 — Commit and signal

**T-11** `backend-dev` — Commit
```bash
git add \
  db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
  src/ApiAuth.php src/ApiResponse.php src/ApiRouter.php \
  public/api/v1/index.php public/.htaccess \
  public/profile/api-keys.php \
  tests/PublicApiTest.php \
  .specifications/asyncstandup/us-33-public-api/
git commit -m "feat(us-33): public REST API — api_keys, 5 endpoints, rate limiting, key management UI"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (schema) | T-2 |
| AC-2 (ApiAuth) | T-3 |
| AC-3 (ApiResponse) | T-4 |
| AC-4 (ApiRouter dispatch) | T-5 |
| AC-5 (entry point) | T-6, T-7 |
| AC-6 (handler implementations) | T-5 |
| AC-7 (key management UI) | T-8 |
| AC-8 (5 tests) | T-9, T-10 |

**Estimate**: ~12h total
