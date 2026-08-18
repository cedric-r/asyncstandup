# US-36: Teams Schema & Per-Team Mode Selector

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-36-teams-schema`

---

## Story

**As a** team owner  
**I want** to select a notification channel (Email, Teams, or Teams Summary) for my team and supply a webhook URL + channel name  
**So that** subsequent stories can route standup prompts and summaries to the right destination

---

## Acceptance Criteria

### AC-1 — Schema: `teams` table additions (all 3 schema files)

```sql
-- db/schema.sql (append — MS Teams integration US-36)
ALTER TABLE teams ADD COLUMN notification_channel VARCHAR(10)  NOT NULL DEFAULT 'email';
-- values: 'email' | 'teams' | 'teams-summary'
ALTER TABLE teams ADD COLUMN teams_webhook_url    VARCHAR(500) NULL;
ALTER TABLE teams ADD COLUMN teams_channel_name   VARCHAR(100) NULL;
ALTER TABLE teams ADD COLUMN teams_conversation_ref TEXT        NULL;
-- JSON blob: Bot Framework conversation reference for team-level proactive messages (US-38)
```

`db/schema-postgresql.sql` (append):
```sql
ALTER TABLE teams ADD COLUMN IF NOT EXISTS notification_channel VARCHAR(10)  NOT NULL DEFAULT 'email';
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_webhook_url    VARCHAR(500) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_channel_name   VARCHAR(100) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_conversation_ref TEXT        NULL;
```

`tests/schema-sqlite.sql` — add to `CREATE TABLE teams` block:
```sql
notification_channel  TEXT    NOT NULL DEFAULT 'email',
teams_webhook_url     TEXT    NULL,
teams_channel_name    TEXT    NULL,
teams_conversation_ref TEXT   NULL,
```

---

### AC-2 — Schema: `users` table additions (all 3 schema files)

```sql
-- db/schema.sql (append)
ALTER TABLE users ADD COLUMN teams_aad_id           VARCHAR(100) NULL;
-- Azure AD object ID — self-resolved on first bot interaction
ALTER TABLE users ADD COLUMN teams_conversation_ref TEXT         NULL;
-- JSON blob: Bot Framework conversation reference for per-user proactive DMs (US-38)
```

`db/schema-postgresql.sql` (append):
```sql
ALTER TABLE users ADD COLUMN IF NOT EXISTS teams_aad_id           VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS teams_conversation_ref TEXT          NULL;
```

`tests/schema-sqlite.sql` — add to `CREATE TABLE users` block:
```sql
teams_aad_id           TEXT NULL,
teams_conversation_ref TEXT NULL,
```

---

### AC-3 — `updateTeam()` in `src/TeamRepository.php` accepts new columns

Extend `updateTeam()` to persist the three UI-settable fields:
- `notification_channel` (validated: must be one of `email`, `teams`, `teams-summary`)
- `teams_webhook_url` (nullable; trimmed; max 500 chars)
- `teams_channel_name` (nullable; trimmed; max 100 chars)

`teams_conversation_ref` is NOT set via the UI — it is managed by `TeamsBot` (US-38). Do not include it in `updateTeam()`.

Validation (in page PHP or `updateTeam()`):
- If `notification_channel` is `teams` or `teams-summary`: `teams_webhook_url` must be a valid HTTPS URL (use `filter_var($url, FILTER_VALIDATE_URL)` + `str_starts_with($url, 'https://')`)
- If validation fails: return error; do not save

---

### AC-4 — `public/teams/edit.php` — mode selector + conditional fields

Add below the existing form fields:

**Notification Channel** (radio group):
```
○ Email (default)
○ Teams DM + Channel Summary  [requires bot setup — see docs]
○ Teams Channel Summary only  [no bot required]
```

When `teams` or `teams-summary` is selected (via JS/PHP re-render), show:

**Teams Webhook URL** (text input, required for teams modes):
```
Incoming Webhook URL
https://xxxxxxx.webhook.office.com/...
```

**Teams Channel Name** (text input, optional — label only):
```
Channel name (for display only)
e.g. #standup-alerts
```

When `email` is selected: hide webhook + channel fields. Implement visibility toggle with a minimal `<script>` block (no framework); fallback gracefully if JS is disabled (fields always shown, validated server-side).

```javascript
document.querySelectorAll('input[name="notification_channel"]').forEach(radio => {
    radio.addEventListener('change', function () {
        const show = (this.value === 'teams' || this.value === 'teams-summary');
        document.getElementById('teams-fields').style.display = show ? '' : 'none';
    });
});
// Set initial state on load:
const checked = document.querySelector('input[name="notification_channel"]:checked');
if (checked) checked.dispatchEvent(new Event('change'));
```

---

### AC-5 — PHPUnit tests: 3 new tests

New test class `tests/TeamsSchemaTest.php`:

| Test | What it verifies |
|---|---|
| `testDefaultNotificationChannelIsEmail` | New team inserted with no `notification_channel` → reads back as `'email'` |
| `testTeamStoresWebhookUrl` | `updateTeam()` with `notification_channel=teams-summary` + valid `teams_webhook_url` → persists both |
| `testUpdateTeamRejectsInvalidChannel` | `updateTeam()` with `notification_channel='slack'` returns/throws error; DB unchanged |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append `teams` + `users` column migrations |
| `db/schema-postgresql.sql` | Same |
| `tests/schema-sqlite.sql` | Add columns to both `CREATE TABLE` blocks |
| `src/TeamRepository.php` | Extend `updateTeam()` + validation |
| `public/teams/edit.php` | Mode selector + conditional Teams fields |
| `tests/TeamsSchemaTest.php` (new) | 3 tests |
