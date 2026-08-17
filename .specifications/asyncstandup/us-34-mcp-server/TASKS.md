# TASKS — US-34: MCP Server Integration

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-34-mcp-server`  
**Agent**: PHP Developer (`fa2e6dbf`)  
**Dependency**: US-33 must be merged before starting — `api_keys` table required

---

## Phase 1 — Branch setup

**T-1** `backend-dev` — Create branch (from main, after US-33 is merged)
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout -b feature/us-34-mcp-server
```

Confirm `tests/schema-sqlite.sql` contains the `api_keys` table (added by US-33). If not, pull latest main first.

---

## Phase 2 — `src/McpTools.php`: tool definitions + implementations (AC-3)

**T-2** `backend-dev` — Create `src/McpTools.php`

**Static tool definitions** (returned as-is by `tools/list`):

```php
public static function getToolDefinitions(): array
{
    return [
        [
            'name'        => 'list_teams',
            'description' => 'List standup teams the authenticated user is a member of.',
            'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ],
        [
            'name'        => 'list_questions',
            'description' => 'List standup questions for a team.',
            'inputSchema' => ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer']], 'required' => ['team_id']],
        ],
        [
            'name'        => 'get_submissions',
            'description' => 'Recent standup submissions for a team.',
            'inputSchema' => ['type' => 'object',
                'properties' => [
                    'team_id'   => ['type' => 'integer'],
                    'limit'     => ['type' => 'integer', 'default' => 10],
                    'from_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
                'required' => ['team_id'],
            ],
        ],
        [
            'name'        => 'get_submission',
            'description' => 'Get a single standup submission with all answers.',
            'inputSchema' => ['type' => 'object', 'properties' => ['submission_id' => ['type' => 'integer']], 'required' => ['submission_id']],
        ],
        [
            'name'        => 'submit_standup',
            'description' => "Submit today's standup answers for a team.",
            'inputSchema' => ['type' => 'object',
                'properties' => [
                    'team_id' => ['type' => 'integer'],
                    'answers' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question_id' => ['type' => 'integer'],
                            'text'        => ['type' => 'string'],
                        ],
                    ]],
                ],
                'required' => ['team_id', 'answers'],
            ],
        ],
        [
            'name'        => 'get_team_stats',
            'description' => '30-day participation stats for a team.',
            'inputSchema' => ['type' => 'object', 'properties' => ['team_id' => ['type' => 'integer']], 'required' => ['team_id']],
        ],
    ];
}
```

**`call()` dispatcher**:

```php
public function call(string $toolName, array $args): array
{
    return match ($toolName) {
        'list_teams'      => $this->listTeams(),
        'list_questions'  => $this->listQuestions((int) ($args['team_id'] ?? 0)),
        'get_submissions' => $this->getSubmissions((int) ($args['team_id'] ?? 0), (int) ($args['limit'] ?? 10), $args['from_date'] ?? null),
        'get_submission'  => $this->getSubmission((int) ($args['submission_id'] ?? 0)),
        'submit_standup'  => $this->submitStandup((int) ($args['team_id'] ?? 0), $args['answers'] ?? []),
        'get_team_stats'  => $this->getTeamStats((int) ($args['team_id'] ?? 0)),
        default           => throw new \InvalidArgumentException("Unknown tool: $toolName"),
    };
}
```

**Individual tool implementations** — thin wrappers over existing repository functions:

- `listTeams()` → `getTeamsForUser($this->pdo, $this->userId)` → return trimmed array
- `listQuestions($teamId)` → membership check → `getQuestions($this->pdo, $teamId)`
- `getSubmissions($teamId, $limit, $fromDate)` → membership check → query `standup_tokens + standup_submissions` limited by `$limit` and `$fromDate`; return structured array
- `getSubmission($submissionId)` → query submission + answers; verify team membership via `standup_tokens.team_id`
- `submitStandup($teamId, $answers)` → developer membership check → insert `standup_submissions` + `standup_answers`; return `['submission_id' => $id]`
- `getTeamStats($teamId)` → membership check → `getParticipationStats($this->pdo, $teamId, $dateFrom, $dateTo)` (30 days)

---

## Phase 3 — `src/McpServer.php`: JSON-RPC dispatcher (AC-2)

**T-3** `backend-dev` — Create `src/McpServer.php`

Full implementation from STORY.md AC-2. Key points:
- `run()` loop: `fgets(STDIN)` → decode JSON → `handle()` → encode → `fwrite(STDOUT)`; `fflush(STDOUT)` after each response
- API key authenticated once at `run()` start from `getenv('ASYNCSTANDUP_API_KEY')`
- `handle()` uses `match($method)` dispatching `initialize`, `tools/list`, `tools/call`
- `tools/call` requires `$this->apiKey !== null` — returns error code `-32001` if not authenticated

---

## Phase 4 — Entry point (AC-1)

**T-4** `backend-dev` — Create `mcp/server.php`

Full content from STORY.md AC-1. Make executable:
```bash
# On Linux/macOS:
chmod +x mcp/server.php
```

Add a `mcp/` directory with a `README.md` documenting how to configure the server in Claude Desktop and Pi SDK:

```markdown
# AsyncStandUp MCP Server

## Setup

1. Generate an API key in AsyncStandUp → Settings → API Keys
2. Add to your MCP client config:

```json
{
  "mcpServers": {
    "asyncstandup": {
      "command": "php",
      "args": ["/absolute/path/to/asyncstandup/mcp/server.php"],
      "env": { "ASYNCSTANDUP_API_KEY": "your-64-char-key" }
    }
  }
}
```
```

---

## Phase 5 — Tests (AC-4)

**T-5** `backend-dev` — Create `tests/McpServerTest.php` (4 tests)

```php
class McpServerTest extends TestCase
{
    private PDO $pdo;
    private McpServer $server;

    protected function setUp(): void
    {
        $this->pdo    = createTestPdo();
        $this->server = new McpServer($this->pdo, []);
        // Insert test user + api key
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");
        $rawKey  = 'testmcpkey';
        $keyHash = hash('sha256', $rawKey);
        $this->pdo->exec("INSERT INTO api_keys (user_id, key_hash) VALUES (1, '$keyHash')");
        putenv("ASYNCSTANDUP_API_KEY=testmcpkey");
    }

    public function testInitializeResponseHasCorrectServerInfo(): void
    {
        $resp = $this->server->handle(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>[]]);
        $this->assertEquals('AsyncStandUp', $resp['result']['serverInfo']['name']);
        $this->assertEquals('2024-11-05', $resp['result']['protocolVersion']);
    }

    public function testToolsListReturnsAllSixTools(): void
    {
        $resp  = $this->server->handle(['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list','params'=>[]]);
        $names = array_column($resp['result']['tools'], 'name');
        $this->assertCount(6, $names);
        $this->assertContains('list_teams', $names);
        $this->assertContains('submit_standup', $names);
    }

    public function testToolsCallWithoutApiKeyReturnsError(): void
    {
        putenv('ASYNCSTANDUP_API_KEY=invalid_key_xyz');
        $serverNoKey = new McpServer($this->pdo, []);
        // Re-authenticate with bad key
        $resp = $serverNoKey->handle(['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call',
                                      'params'=>['name'=>'list_teams','arguments'=>[]]]);
        $this->assertArrayHasKey('error', $resp);
        $this->assertEquals(-32001, $resp['error']['code']);
        putenv('ASYNCSTANDUP_API_KEY=testmcpkey'); // restore
    }

    public function testListTeamsReturnsUserTeams(): void
    {
        // Insert org, team, membership
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'My Team', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 1, 1, 0)");

        $resp = $this->server->handle(['jsonrpc'=>'2.0','id'=>4,'method'=>'tools/call',
                                       'params'=>['name'=>'list_teams','arguments'=>[]]]);
        $data = json_decode($resp['result']['content'][0]['text'], true);
        $this->assertCount(1, $data);
        $this->assertEquals('My Team', $data[0]['name']);
    }
}
```

Note: `McpServer::handle()` must be made accessible for testing — either make it `public` or test via a test-subclass. Implement `handle()` as `public` in the class.

**T-6** `backend-dev` — Run full test suite; target ≥103 tests (99 prior + 4 new)

---

## Phase 6 — Commit and signal

**T-7** `backend-dev` — Commit
```bash
git add \
  src/McpServer.php src/McpTools.php \
  mcp/server.php mcp/README.md \
  tests/McpServerTest.php \
  .specifications/asyncstandup/us-34-mcp-server/
git commit -m "feat(us-34): MCP server — stdio JSON-RPC 2.0, 6 tools, api_keys auth"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (entry point) | T-4 |
| AC-2 (McpServer dispatcher) | T-3 |
| AC-3 (McpTools definitions + impls) | T-2 |
| AC-4 (4 tests) | T-5, T-6 |

**Estimate**: ~10h total
