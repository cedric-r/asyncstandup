# IMPL-PLAN — US-34: MCP Server Integration

**Status**: APPROVED
**Branch**: `feature/us-34-mcp-server`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-34 — MCP Server Integration

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-4 and TASKS.md T-1 through T-7.

No schema changes — reuses `api_keys` table from US-33 (confirmed present in `tests/schema-sqlite.sql` after merge).
No new Composer dependencies. 5 new files.

---

## Files to Create

| File | Change |
|---|---|
| `src/McpServer.php` | JSON-RPC 2.0 dispatcher class — `handle()` public for unit tests |
| `src/McpTools.php` | Tool definitions (6) + implementations — thin wrappers over repo functions |
| `mcp/server.php` | CLI entry point; reads `ASYNCSTANDUP_API_KEY` from env |
| `mcp/README.md` | Claude Desktop / Pi SDK config instructions |
| `tests/McpServerTest.php` | 4 PHPUnit tests |

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `getTeamsForUser(PDO, int): array` | ✓ exists — `src/DashboardRepository.php` line 8; includes `org_name` |
| `getParticipationStats(PDO, int, string, string): array` | ✓ exists — `src/DashboardRepository.php` line 101 |
| `getQuestions(PDO, int): array` | ✓ exists — `src/TeamRepository.php`; returns `id, question, position, is_blocker, is_mood` |
| `isTeamMember(PDO, int, int): bool` | ✓ exists — `src/TeamRepository.php` |
| `isDeveloperMember(PDO, int, int): bool` | ✓ exists — `src/TeamRepository.php` |
| `createStandupToken()` | ✓ exists — returns `?string` (token string); int PK fetched separately |
| `saveSubmission()` | ✓ exists — `src/SubmissionRepository.php`; takes int `$tokenId` |
| `api_keys` table | ✓ present in `tests/schema-sqlite.sql` (US-33 merged) |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-34-mcp-server` created from `main` after US-33 merge.

---

### T-2 — Create `src/McpTools.php` (AC-3)

**Class structure**:
```
McpTools::__construct(PDO $pdo, array $apiKey)
McpTools::getToolDefinitions(): array   // static
McpTools::call(string $toolName, array $args): array
// Private methods: listTeams(), listQuestions(), getSubmissions(), getSubmission(), submitStandup(), getTeamStats()
```

**Tool definitions**: 6 tools as specified in TASKS.md T-2. `inputSchema.properties` for tools with no required params uses `new \stdClass()` (MCP spec requires `{}` not `null`).

**`listTeams()`**: `getTeamsForUser($this->pdo, $this->userId)` → map to `[id, name, timezone, standup_time, org_name]`.

**`listQuestions(int $teamId)`**: `isTeamMember()` guard (throw `\RuntimeException('forbidden')` if not member) → `getQuestions($this->pdo, $teamId)` → map to `[id, question, position]`.

**`getSubmissions(int $teamId, int $limit, ?string $fromDate)`**: `isTeamMember()` guard → `getResponseData($this->pdo, $teamId, null, null, $from, $to)` → pivot into per-submission objects → `array_slice($all, 0, $limit)`.

**`getSubmission(int $submissionId)`**: Direct query `standup_submissions JOIN standup_tokens JOIN standup_answers WHERE ss.id = ?`. Verify `standup_tokens.team_id` membership via `isTeamMember()`. Return `[submission_id, send_date, user_id, answers[]]`.

**`submitStandup(int $teamId, array $answers)`**: `isDeveloperMember()` guard → build `$answersMap [questionId => text]` → re-fetch or create token (same pattern as `handlePostSubmission` in US-33, fetching int PK separately after `createStandupToken()`) → 409-style error if already submitted → `saveSubmission()` → `['submission_id' => $id]`.

**`getTeamStats(int $teamId)`**: `isTeamMember()` guard → `getParticipationStats($this->pdo, $teamId, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'))`.

Error handling: `isTeamMember()` failures throw `\RuntimeException('forbidden')` — caught by `McpServer::handleToolsCall()` via `McpServer::errorResponse(-32001)`.

---

### T-3 — Create `src/McpServer.php` (AC-2)

Exact implementation from STORY.md AC-2. Key implementation notes:

- `handle()` must be `public` (needed for unit tests per TASKS.md T-5).
- Authentication in `run()`: read `getenv('ASYNCSTANDUP_API_KEY')`, hash with SHA-256, query `api_keys`.
- For tests calling `handle()` directly without going through `run()`: `$this->apiKey` must be set before `handleToolsCall()` is reached. Add a `authenticate()` method called at the top of `run()`, also callable in tests by injecting the key via the constructor or a setter. Simplest: add `public function setApiKey(?array $apiKey): void` for test injection.

Actually — cleaner: make `run()` call `$this->apiKey = $this->resolveApiKey()` where `resolveApiKey()` is a private method. Tests that call `handle()` directly still need `$this->apiKey` set. Solution: constructor accepts optional `?array $apiKey = null`; `run()` overwrites it from env. Tests pass a pre-resolved key to the constructor.

---

### T-4 — Create `mcp/server.php` + `mcp/README.md` (AC-1)

`mcp/server.php`: exact content from STORY.md AC-1.
`mcp/README.md`: Claude Desktop / Pi SDK config instructions as specified in TASKS.md T-4.

---

### T-5 — Create `tests/McpServerTest.php` (AC-4)

4 tests as specified in TASKS.md T-5. Constructor approach: pass resolved `$apiKey` array to `McpServer` (via the optional constructor param from T-3 note above). This avoids env var state leaking between tests.

Specifically:
- `testToolsCallWithoutApiKeyReturnsError`: construct `McpServer($pdo, [], null)` — null apiKey triggers `-32001`
- `testListTeamsReturnsUserTeams`: construct `McpServer($pdo, [], $keyRow)` where `$keyRow` is the fetched row

---

### T-6 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥105 tests (101 prior + 4 new), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors.

---

### T-7 — Commit

```bash
git add src/McpServer.php src/McpTools.php mcp/server.php mcp/README.md \
        tests/McpServerTest.php \
        .specifications/asyncstandup/us-34-mcp-server/
git commit -m "feat(us-34): MCP server — stdio JSON-RPC 2.0, 6 tools, api_keys auth"
```

---

## Risk Notes

1. **`McpServer::handle()` visibility** — must be `public` for direct unit testing (TASKS.md T-5 calls it directly). Spec code shows `private function handle()` — changing to `public`.
2. **`apiKey` injection for tests** — `run()` reads from env; tests call `handle()` directly. Constructor will accept optional `?array $apiKey = null`; `run()` overwrites. Tests pass pre-resolved key row.
3. **`submitStandup` token int PK** — same pattern as US-33 CRITICAL fix: `createStandupToken()` returns string; SELECT int PK separately. Must not repeat the bug.
4. **PHPStan `never` + `match`** — `McpServer::handle()` and `McpTools::call()` both return `array`, not `never`. PHPStan level 5 accepts this.
5. **`getResponseData()` requires `is_developer = 1`** — the JOIN on `team_members` filters to developers only. For `get_submissions` tool, this is correct behaviour (only developer submissions visible).
