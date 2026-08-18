# AsyncStandUp

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-8892BF?logo=php&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)
![Tests: 127](https://img.shields.io/badge/tests-127%20passing-brightgreen)
![PHPStan: level 5](https://img.shields.io/badge/PHPStan-level%205-blue)

Self-hosted async standup tool. No Azure subscription required for core features. Runs on PHP 8.3 + SQLite (or MySQL / PostgreSQL). Delivers daily standup prompts and summaries by email or MS Teams.

📖 **[Full documentation →](https://cedric-r.github.io/asyncstandup/)**

---

## Features

| | Feature |
|---|---|
| ✅ | Team management — create, edit, suspend, delete teams |
| ✅ | Configurable standup questions per team (order, blockers flag, mood flag) |
| ✅ | Timezone-aware scheduling with configurable frequency (daily / weekdays / weekly) |
| ✅ | Standup prompt delivery — email **or** MS Teams Adaptive Card DM |
| ✅ | Web response form + Teams card submission via Bot Framework |
| ✅ | Submission reminders (configurable window before expiry) |
| ✅ | Blocker question flagging — ⚠️ highlighted in all summaries |
| ✅ | Mood / sentiment tracking — 5-level score, 30-day trend graph |
| ✅ | Daily summary delivery — email **or** Teams channel Incoming Webhook (Adaptive Card) |
| ✅ | Response browser per team |
| ✅ | Public REST API v1 — API key auth, rate-limited (100 req/hr) |
| ✅ | MCP server — stdio transport, 6 tools, Claude Desktop + Pi SDK ready |
| ✅ | API key management UI — generate, list, revoke (soft-delete) |
| ✅ | MS Teams integration — 3 notification modes |
| ✅ | Teams admin overview — mode badges, last delivery error tracking |

---

## Requirements

- **PHP** 8.3+ (CLI for cron; FPM/Apache/Nginx for web)
- **Database**: SQLite 3 (default) · MySQL 5.7+ · PostgreSQL 13+
- **SMTP**: any plain relay (localhost Postfix, SendGrid, etc.)
- **HTTPS**: required only for Teams Bot DM webhook endpoint (`/bot/webhook`)

---

## Installation

```bash
# 1. Clone
git clone https://github.com/cedric-r/asyncstandup.git
cd asyncstandup

# 2. Copy and edit config
cp config/config.example.php config/config.php
$EDITOR config/config.php

# 3. Create the database (SQLite example)
touch asyncstandup.sqlite
php -r "require 'src/Db.php'; getDb(require 'config/config.php');"

# 4. Apply schema
sqlite3 asyncstandup.sqlite < db/schema.sql
# MySQL: mysql asyncstandup < db/schema.sql
# PostgreSQL: psql asyncstandup < db/schema-postgresql.sql

# 5. Set up cron (see Cron Jobs section)
# 6. Point your web server at public/
```

---

## Configuration

Edit `config/config.php` (copy from `config/config.example.php`):

```php
return [
    'app_url' => 'https://standup.example.com',

    'db' => [
        'driver'  => 'sqlite',   // sqlite | mysql | pgsql
        'path'    => '/var/data/asyncstandup.sqlite',  // SQLite only
        // MySQL / PostgreSQL:
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'asyncstandup',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'smtp' => [
        'host'      => 'localhost',
        'port'      => 25,
        'from'      => 'standup@example.com',
        'from_name' => 'AsyncStandUp',
    ],

    // Optional — required for notification_channel = 'teams' (Bot DM prompts)
    'teams_bot' => [
        'app_id'           => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
        'app_secret'       => 'your-bot-app-secret',
        'service_url'      => 'https://smba.trafficmanager.net/emea/',
        'bot_webhook_path' => '/bot/webhook',
    ],
];
```

---

## Notification Modes

Configure per team in **Team Settings → Notification Channel**.

| Mode | Prompt delivery | Summary delivery | Requires |
|---|---|---|---|
| `email` | Email | Email | SMTP |
| `teams` | Teams Adaptive Card DM | Email | Azure Bot registration + HTTPS |
| `teams-summary` | Email | Teams Incoming Webhook (Adaptive Card) | Teams Incoming Webhook URL only |

> **teams-summary** is the easiest Teams integration — no Azure account needed, just a Webhook URL from Teams admin.

---

## Cron Jobs

Three independent passes. Recommended schedule (adjust times to suit):

```cron
# Pass 1 — Send standup prompts at each team's configured standup_time
* * * * * php /var/www/asyncstandup/cron/send_standups.php >> /var/log/asyncstandup.log 2>&1

# Pass 2 — Send submission reminders (fires ~2 h before token expiry)
* * * * * php /var/www/asyncstandup/cron/send_standups.php reminder >> /var/log/asyncstandup.log 2>&1

# Pass 3 — Send daily summaries after standup window closes
* * * * * php /var/www/asyncstandup/cron/send_standups.php summary >> /var/log/asyncstandup.log 2>&1
```

---

## REST API

**Base URL**: `https://standup.example.com/api/v1`  
**Auth**: `Authorization: Bearer sk-<api-key>`  
**Rate limit**: 100 requests / hour per key

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/teams` | List teams accessible to the authenticated user |
| `GET` | `/teams/{id}/questions` | List standup questions for a team |
| `GET` | `/teams/{id}/submissions` | Recent submissions (paginated) |
| `GET` | `/submissions/{id}` | Single submission with answers |
| `POST` | `/submissions` | Submit a standup (`token` + `answers[]`) |

**Example:**

```bash
curl -H "Authorization: Bearer sk-abc123" \
     https://standup.example.com/api/v1/teams

curl -X POST \
     -H "Authorization: Bearer sk-abc123" \
     -H "Content-Type: application/json" \
     -d '{"token":"tok-xyz","answers":{"1":"Finished the PR","2":"Code review today"}}' \
     https://standup.example.com/api/v1/submissions
```

---

## MCP Server

Exposes 6 tools over stdio JSON-RPC 2.0 for use with AI assistants.

```bash
ASYNCSTANDUP_API_KEY=sk-<key> php mcp/server.php
```

**Tools:**

| Tool | Description |
|---|---|
| `list_teams` | List all teams |
| `list_questions` | List questions for a team |
| `get_submissions` | Recent submissions for a team |
| `get_submission` | Single submission detail |
| `submit_standup` | Submit answers for a token |
| `get_team_stats` | Participation and mood statistics |

**Claude Desktop** (`~/.config/claude/claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "asyncstandup": {
      "command": "php",
      "args": ["/var/www/asyncstandup/mcp/server.php"],
      "env": { "ASYNCSTANDUP_API_KEY": "sk-<key>" }
    }
  }
}
```

See `mcp/README.md` for Pi SDK configuration.

---

## MS Teams Setup

### Channel summaries — no Azure account required

Delivers a formatted Adaptive Card to a Teams channel after standup closes.

1. In Teams: go to the channel → **⋯ More options → Connectors → Incoming Webhook**
2. Create a webhook, copy the URL
3. In AsyncStandUp: **Team Settings → Notification Channel → Teams Summary**
4. Paste the webhook URL into **Webhook URL**
5. Done — summaries will be posted to the channel automatically

### Bot DM prompts — Azure Bot registration required

Delivers an interactive Adaptive Card directly to each team member's Teams DMs.

1. Register an Azure Bot (free tier): [Azure Portal → Bot Services](https://portal.azure.com)
2. Note the **AppId** and generate an **App Secret** (client secret)
3. Add to `config/config.php` under `teams_bot`
4. Point the bot's **Messaging Endpoint** to `https://your-domain.com/bot/webhook`
5. In AsyncStandUp: **Team Settings → Notification Channel → Teams DM**

> **Note:** Members must first message the bot (or be added to a conversation) to establish a conversation reference before proactive DMs can be sent. First-time users automatically fall back to email.

> **Security:** RS256 JWT signature verification is not enabled in v1. See `public/bot/webhook.php` for the TODO. Add JWKS verification before production deployment.

---

## Database

All three backends share the same schema structure. Migration files:

| File | Purpose |
|---|---|
| `db/schema.sql` | MySQL schema (also used as reference) |
| `db/schema-postgresql.sql` | PostgreSQL schema (`IF NOT EXISTS`, `SERIAL`, `TIMESTAMP`) |
| `tests/schema-sqlite.sql` | SQLite schema (used in test suite) |

---

## Test Suite

```bash
php tests/phpunit.phar --configuration tests/phpunit.xml
```

**127 tests · 255 assertions · PHPStan level 5 · zero errors**

Test classes cover: DB abstraction, team lifecycle, answer history, reminders, frequency, blocker flagging, mood tracking, public API, MCP server, API key management, Teams schema, Teams channel summary, Teams bot prompts, bot webhook, Teams fallback/error tracking.

---

## Security Notes

| Area | Status |
|---|---|
| Bot webhook JWT | ⚠️ Audience + issuer + expiry checked; RS256 JWKS signature verification **TODO before production** — see `public/bot/webhook.php` |
| Bot access token cache | Stored in system temp dir as JSON; `chmod 0600` applied after write |
| API keys | Stored as SHA-256 hash only; raw key displayed once at creation, never again |
| Webhook serviceUrl | Validated against allowlist (`smba.trafficmanager.net`, `webchat.botframework.com`) before use — prevents Bearer token exfiltration |
| Teams error tracking | Last delivery error stored per team; visible in admin overview only |

---

## Admin Panel

Access at `/admin/` (admin users only).

| Page | URL | Description |
|---|---|---|
| User management | `/admin/users.php` | List, invite, promote users |
| Teams overview | `/admin/teams.php` | All teams with mode badges and last Teams error |

---

## License

MIT — see [LICENSE](LICENSE)
