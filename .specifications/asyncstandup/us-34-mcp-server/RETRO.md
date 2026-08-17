# RETRO — US-34: MCP Server Integration

**Story**: US-34 — MCP Server Integration
**Branch**: `feature/us-34-mcp-server`
**Merge commit**: `e650f2b`
**Review cycles**: 1
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `src/McpServer.php` | JSON-RPC 2.0 dispatcher; `handle()` public for unit testing (docblocked); constructor `(PDO, ?array $apiKey = null)` for test injection; `run()` authenticates from `ASYNCSTANDUP_API_KEY` env var |
| `src/McpTools.php` | 6 MCP tools + `match()` dispatcher; thin wrappers over existing repo functions |
| `mcp/server.php` | CLI entry point (`php_sapi_name !== 'cli'` guard) |
| `mcp/README.md` | Claude Desktop + Pi SDK config instructions; tool table; auth notes |
| `tests/McpServerTest.php` | 4 tests: initialize serverInfo, 6 tools in tools/list, no-key → -32001, list_teams result |
| `tests/bootstrap.php` | Added `McpServer.php` + `McpTools.php` requires |

**Test result**: 105 tests, 208 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle**

---

## Notes

1. **`McpServer::handle()` visibility** — changed from `private` (STORY.md spec) to `public` with docblock: "Public visibility is intentional — allows direct unit testing without stdin/stdout mocking." Avoids needing a test subclass while staying clear to future readers.

2. **`McpServer` constructor — `$config` parameter dropped** — initial design accepted `array $config` (for potential future use: app_url, etc.). PHPStan level 5 flagged "unused parameter". Removed entirely to keep clean; can be re-added if McpTools ever needs config values.

3. **`submitStandup` token int PK** — same fix as US-33 CRITICAL: `createStandupToken()` returns `?string` (hex token), not int. Token PK fetched with a separate SELECT after creation. Documented in IMPL-PLAN risk notes.

4. **PHPStan nullCoalesce on typed array** — `@param array{question_id: int, text: string}[]` caused two `nullCoalesce.offset` warnings on `?? 0` / `?? ''` (keys always exist per type). Fixed by broadening annotation to `array<string, mixed>`.

5. **`assertMember()` pattern** — all 5 data-access tools call a single private `assertMember(int $teamId): void` that throws `\RuntimeException('forbidden')`. McpServer catches `\Throwable` in the run loop → MCP `-32603` error response. Consistent with how US-33's `handleGetTeams()` guards work.
