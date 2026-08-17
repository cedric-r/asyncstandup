# AsyncStandUp MCP Server

Connect AI agents and LLMs to AsyncStandUp via the [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) over stdio.

## Prerequisites

- PHP 8.3+ on `$PATH`
- An AsyncStandUp API key (generate one at **Settings → API Keys**)
- AsyncStandUp database accessible from the machine running the server

## Tools exposed

| Tool | Description |
|---|---|
| `list_teams` | List teams the API key owner is a member of |
| `list_questions` | List standup questions for a team |
| `get_submissions` | Recent standup submissions for a team (paginated) |
| `get_submission` | Single submission with full answers |
| `submit_standup` | Submit today's standup answers programmatically |
| `get_team_stats` | 30-day participation statistics for a team |

## Setup — Claude Desktop

Add to `~/Library/Application Support/Claude/claude_desktop_config.json` (macOS) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):

```json
{
  "mcpServers": {
    "asyncstandup": {
      "command": "php",
      "args": ["/absolute/path/to/asyncstandup/mcp/server.php"],
      "env": {
        "ASYNCSTANDUP_API_KEY": "your-64-char-api-key-here"
      }
    }
  }
}
```

## Setup — Pi SDK / custom MCP client

```json
{
  "command": "php",
  "args": ["/absolute/path/to/asyncstandup/mcp/server.php"],
  "env": { "ASYNCSTANDUP_API_KEY": "your-64-char-api-key-here" }
}
```

## Authentication

The server reads the API key from the `ASYNCSTANDUP_API_KEY` environment variable at startup. The key is hashed with SHA-256 and compared against the `api_keys` table. Tools that require team membership perform an additional membership check per call.

## Protocol

- Transport: stdio (newline-delimited JSON)
- MCP protocol version: `2024-11-05`
- Methods supported: `initialize`, `tools/list`, `tools/call`
