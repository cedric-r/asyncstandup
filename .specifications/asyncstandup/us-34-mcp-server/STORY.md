# US-34: MCP Server Integration

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-34-mcp-server`

---

## Story

**As an** AI agent or LLM tool  
**I want** to connect to AsyncStandUp via MCP (Model Context Protocol) over stdio  
**So that** I can list teams, retrieve standup responses, and submit standups without a human browser session

---

## Acceptance Criteria

### AC-1 — MCP server entry point: `mcp/server.php`

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { exit(1); }

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/ApiAuth.php';   // reuse key auth logic
require_once __DIR__ . '/../src/McpServer.php';
require_once __DIR__ . '/../src/McpTools.php';
require_once __DIR__ . '/../src/TeamRepository.php';
require_once __DIR__ . '/../src/DashboardRepository.php';
require_once __DIR__ . '/../src/SubmissionRepository.php';

$config = require __DIR__ . '/../config/config.php';
$pdo    = getDb($config);

$server = new McpServer($pdo, $config);
$server->run();  // reads from STDIN, writes to STDOUT in a loop
```

Invocation by MCP client (Claude Desktop, Pi SDK, etc.):
```json
{
  "command": "php",
  "args": ["/path/to/asyncstandup/mcp/server.php"],
  "env": { "ASYNCSTANDUP_API_KEY": "your-api-key-here" }
}
```

API key read from `getenv('ASYNCSTANDUP_API_KEY')` (not `$_SERVER` — CLI has no HTTP headers).

---

### AC-2 — `src/McpServer.php` — JSON-RPC 2.0 dispatcher

```php
class McpServer
{
    private PDO    $pdo;
    private array  $config;
    private ?array $apiKey = null;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo    = $pdo;
        $this->config = $config;
    }

    public function run(): void
    {
        // Authenticate from env var at startup
        $rawKey        = getenv('ASYNCSTANDUP_API_KEY') ?: '';
        $keyHash       = hash('sha256', $rawKey);
        $stmt          = $this->pdo->prepare('SELECT * FROM api_keys WHERE key_hash = ?');
        $stmt->execute([$keyHash]);
        $this->apiKey  = $stmt->fetch() ?: null;

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') { continue; }
            try {
                $request  = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $response = $this->handle($request);
            } catch (\JsonException $e) {
                $response = $this->errorResponse(null, -32700, 'Parse error');
            } catch (\Throwable $e) {
                $response = $this->errorResponse($request['id'] ?? null, -32603, $e->getMessage());
            }
            fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
            fflush(STDOUT);
        }
    }

    private function handle(array $req): array
    {
        $id     = $req['id'] ?? null;
        $method = $req['method'] ?? '';
        $params = $req['params'] ?? [];

        return match ($method) {
            'initialize'  => $this->handleInitialize($id),
            'tools/list'  => $this->handleToolsList($id),
            'tools/call'  => $this->handleToolsCall($id, $params),
            default       => $this->errorResponse($id, -32601, 'Method not found'),
        };
    }

    private function handleInitialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => '2024-11-05',
                'serverInfo'      => ['name' => 'AsyncStandUp', 'version' => '1.0.0'],
                'capabilities'    => ['tools' => new \stdClass()],
            ],
        ];
    }

    private function handleToolsList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => ['tools' => McpTools::getToolDefinitions()],
        ];
    }

    private function handleToolsCall(mixed $id, array $params): array
    {
        if ($this->apiKey === null) {
            return $this->errorResponse($id, -32001, 'Unauthorized — set ASYNCSTANDUP_API_KEY env var');
        }

        $toolName  = $params['name']      ?? '';
        $arguments = $params['arguments'] ?? [];

        $tools  = new McpTools($this->pdo, $this->apiKey);
        $result = $tools->call($toolName, $arguments);

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => ['content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]],
        ];
    }

    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
```

---

### AC-3 — `src/McpTools.php` — tool definitions and implementations

**Tool definitions** (returned by `tools/list`):

| Tool name | Description | Input schema |
|---|---|---|
| `list_teams` | List teams the API key owner is a member of | `{}` |
| `list_questions` | List questions for a team | `{team_id: integer}` |
| `get_submissions` | Recent submissions for a team | `{team_id: integer, limit?: integer, from_date?: string}` |
| `get_submission` | Single submission with answers | `{submission_id: integer}` |
| `submit_standup` | Submit standup answers for today | `{team_id: integer, answers: [{question_id: integer, text: string}]}` |
| `get_team_stats` | 30-day participation stats | `{team_id: integer}` |

All tools validate that the API key's user is a member of the requested team before returning data.

**`McpTools::call(string $toolName, array $args): array`** — dispatches to method per tool name; throws `\InvalidArgumentException` for unknown tools (caught by `McpServer` → MCP error response).

**Implementation approach**: Thin wrappers over existing repository functions:
- `list_teams` → `getTeamsForUser($this->pdo, $userId)`
- `list_questions` → `getQuestions($this->pdo, $teamId)` after membership check
- `get_submissions` → `getResponseData(...)` with date range from `from_date`; limit applied in PHP
- `get_submission` → direct query on `standup_submissions JOIN standup_answers`
- `submit_standup` → reuse submission logic from `handlePostSubmission` in US-33 (or the underlying repository functions)
- `get_team_stats` → `getParticipationStats($this->pdo, $teamId, $dateFrom, $dateTo)`

---

### AC-4 — PHPUnit tests: 4 new tests

New test class `tests/McpServerTest.php`:

| Test | What it verifies |
|---|---|
| `testInitializeResponseHasCorrectServerInfo` | `McpServer::handle(['method'=>'initialize','id'=>1])` returns `result.serverInfo.name = 'AsyncStandUp'` |
| `testToolsListReturnsAllSixTools` | `tools/list` response `result.tools` has 6 items with expected names |
| `testToolsCallWithoutApiKeyReturnsError` | No env key set → `tools/call` returns MCP error code `-32001` |
| `testListTeamsReturnsUserTeams` | Insert team + membership; set env key; `tools/call list_teams` → returns team in result |

Tests call `McpServer::handle()` directly (unit-level) — no stdin/stdout mocking needed.

---

## Files Changed

| File | Change |
|---|---|
| `mcp/server.php` (new) | CLI entry point |
| `src/McpServer.php` (new) | JSON-RPC dispatcher |
| `src/McpTools.php` (new) | Tool definitions + implementations |
| `tests/McpServerTest.php` (new) | 4 PHPUnit tests |

**No schema changes** — reuses `api_keys` from US-33 (`api_keys` table required; US-33 must be implemented first or run in same branch).

---

## Dependency

US-34 depends on `api_keys` table (added in US-33). Deployment order: US-33 schema migration first, then US-34. On the implementation branch, verify `api_keys` table exists in `tests/schema-sqlite.sql` (will be present after US-33 is merged).
