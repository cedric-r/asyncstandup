# IMPL-PLAN — US-38: Bot DM Prompts

**Status**: PENDING GATE C APPROVAL
**Branch**: `feature/us-38-teams-bot-prompts`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-38 — Bot DM Prompts (Proactive Message via Bot Framework)

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-6 and TASKS.md T-1 through T-10.
No schema changes. No Composer dependencies.

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `sendStandupPrompt()` signature | `(PDO $pdo, array $config, array $team, array $member, string $token, string $sendDate): void` |
| `sendStandupPrompt()` question fetch | `SELECT question FROM team_questions ... ORDER BY position ASC` with `FETCH_COLUMN` → returns `string[]`. For Teams DM, need `id + question` → must change to `SELECT id, question FROM ... FETCH_ASSOC` for both paths |
| `$member` shape | Has `email`, `display_name`, plus `teams_conversation_ref` (added US-36). `sendDmPrompt()` uses `$user['teams_conversation_ref']` — `$member` maps directly to `$user` param |
| `$config['teams_bot']` | Not yet in `config.example.php` — must add |
| `buildPromptCard()` expiry | `expires_at` not passed to `sendStandupPrompt()`. Use `standup_time + 2 hours` as display-only expiry; convert to team timezone |
| `$http_response_header` in `sendDmPrompt()` | Spec uses `$http_response_header ?? []` — PHPStan may flag. Use `/** @var string[] $http_response_header */` + iterate directly (same fix as US-37) |
| `config/.gitignore` | Check if exists; `config.php` exclusion may already be in root `.gitignore` |

---

## Files to Create / Change

| File | Change |
|---|---|
| `config/config.example.php` | Append `teams_bot` block |
| `src/TeamsBot.php` (new) | `getBotAccessToken()`, `buildPromptCard()`, `sendDmPrompt()` |
| `src/StandupEmailer.php` | `require_once TeamsBot.php` + Teams branch in `sendStandupPrompt()` + question SELECT changed |
| `tests/TeamsBotTest.php` (new) | 3 PHPUnit tests |
| `tests/bootstrap.php` | Add `require_once` for `TeamsBot.php` |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-38-teams-bot-prompts` created from `main`.

---

### T-2 — `config/config.example.php` (AC-1)

Replace the closing `];` with the `teams_bot` block then close:
```php
// ── MS Teams Bot (optional — required for notification_channel = 'teams') ──
'teams_bot' => [
    'app_id'           => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    'app_secret'       => 'your-bot-app-secret',
    'service_url'      => 'https://smba.trafficmanager.net/emea/',
    'bot_webhook_path' => '/bot/webhook',
],
```

Confirm `config.php` excluded from git (check root `.gitignore`).

---

### T-3 — `src/TeamsBot.php`: `getBotAccessToken()` (AC-2)

Full implementation from STORY.md AC-2:
- Cache file: `sys_get_temp_dir() . '/asyncstandup_bot_token.json'`
- 60-second buffer: `$cached['expires_at'] > time() + 60`
- `file_get_contents` + `stream_context_create` (no cURL)
- `JSON_THROW_ON_ERROR` on decode
- Throws `\RuntimeException` on failure (callers catch and return false)

---

### T-4 — `src/TeamsBot.php`: `buildPromptCard()` (AC-3)

Signature: `buildPromptCard(array $team, array $questions, string $token): array`

- Header TextBlocks: `"🤖 AsyncStandUp — Daily Standup"`, team name, today's date `date('l j F Y')`
- Per question: TextBlock (question text, `weight=Bolder`) + `Input.Text` (`id = "q_{$q['id']}"`, `isMultiline=true`)
- Expiry footer: compute `standup_time + 2 hours` as a `DateTimeImmutable` in team timezone, format `'H:i T'`. Use `date_create_immutable($team['standup_time'])` — if that fails (HH:MM:SS format), parse as `H:i:s`
- `actions`: single `Action.Submit` with `title='Submit Standup'`, `data=['token' => $token]`
- Returns Teams Incoming Webhook outer wrapper (`type: 'message'`)

---

### T-5 — `src/TeamsBot.php`: `sendDmPrompt()` (AC-4)

Full implementation from STORY.md AC-4:
- Immediate `return false` if `$user['teams_conversation_ref']` is null/empty
- `getBotAccessToken()` in try/catch → return false on exception + `error_log`
- HTTP POST to `{serviceUrl}/v3/conversations/{convId}/activities`
- Parse HTTP status from `$http_response_header` — use `/** @var string[] $http_response_header */` annotation (same PHPStan fix as US-37)
- Return false + `error_log` on non-2xx

---

### T-6 + T-7 — `src/StandupEmailer.php` (AC-5)

**Add at top** (after `<?php declare...`):
```php
require_once __DIR__ . '/TeamsBot.php';
```

**Inside `sendStandupPrompt()`** — change question fetch from `FETCH_COLUMN` to `FETCH_ASSOC` with `id + question` for both email and Teams paths (email template only uses `question` text; Teams needs `id`):
```php
$stmt = $pdo->prepare('SELECT id, question FROM team_questions WHERE team_id = ? ORDER BY position ASC');
$stmt->execute([$team['id']]);
$questions = $stmt->fetchAll(); // array of ['id'=>int, 'question'=>string]
```

Update email template rendering: change `$questions` usage (currently strings via FETCH_COLUMN) to extract question text. Check `templates/email/standup_prompt.php` to see how `$questions` is used — if it iterates over strings, adapt.

**Teams branch** (before the `sendMail()` call):
```php
$channel   = (string) ($team['notification_channel'] ?? 'email');
$botConfig = $config['teams_bot'] ?? [];

if ($channel === 'teams' && !empty($botConfig)) {
    $sent = sendDmPrompt($pdo, $member, $team, $questions, $token, $botConfig);
    if ($sent) {
        return; // DM sent — skip email
    }
    error_log("[AsyncStandUp] Teams DM failed for user {$member['id']} team {$team['id']} — falling back to email");
}
// email path continues below...
```

---

### T-8 — `tests/TeamsBotTest.php` (AC-6)

3 tests per TASKS.md T-8. Note: `createTestPdo()` not needed for first two tests (pure card builder unit tests). Third test does need a PDO for signature compatibility.

**`tests/bootstrap.php`**: add `require_once` for `TeamsBot.php`.

---

### T-9 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥118 tests (115 + 3), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors.

PHPStan risks:
- `$http_response_header` in `sendDmPrompt()` — use `/** @var string[] $http_response_header */` before loop
- `file_get_contents` return type `string|false` — guard with `=== false`
- `json_decode` may return `null` on invalid JSON — guard array checks before indexing

---

### T-10 — Commit

```bash
git add config/config.example.php src/TeamsBot.php src/StandupEmailer.php \
        tests/TeamsBotTest.php tests/bootstrap.php \
        .specifications/asyncstandup/us-38-teams-bot-prompts/
git commit -m "feat(us-38): Teams bot DM prompts — access token, Adaptive Card, proactive DM, email fallback"
```

---

## Risk Notes

1. **Question fetch change** (`FETCH_COLUMN` → `FETCH_ASSOC`): `$questions` in email template `standup_prompt.php` currently receives strings. Must check template and adapt extraction (`$q['question']` instead of `$q`).
2. **`buildPromptCard()` expiry** — `standup_time` stored as `HH:MM:SS`. Parse with `DateTimeImmutable::createFromFormat('H:i:s', $team['standup_time'])`, modify `+2 hours`, set timezone, format `'H:i T'`. Handle parse failure (return generic `'end of day'`).
3. **`sendDmPrompt()` `$http_response_header`** — PHPStan level 5 flags `isset()` as always-true; use `/** @var string[] */` annotation + direct iteration (same fix as US-37's `postChannelSummary()`).
4. **`$member['id']` in error_log** — confirm `$member` array has `'id'` field. Check cron to see what's passed as `$member` to `sendStandupPrompt()`. If missing, use `$member['email']` instead.
