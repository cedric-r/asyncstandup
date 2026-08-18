# US-38: Bot DM Prompts (Proactive Message via Bot Framework)

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-38-teams-bot-prompts`  
**Depends on**: US-36 merged (`teams_conversation_ref` on `users`, `notification_channel` on `teams`)

---

## Story

**As a** standup participant on a Teams-enabled team  
**I want** to receive my daily standup prompt as a Teams DM Adaptive Card  
**So that** I can answer directly in Teams without opening a browser

---

## Acceptance Criteria

### AC-1 — `config/config.php` / `config/config.example.php`: `teams_bot` block

Add to `config/config.example.php`:
```php
'teams_bot' => [
    'app_id'           => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',  // Azure Bot AppId
    'app_secret'       => 'your-bot-app-secret',
    'service_url'      => 'https://smba.trafficmanager.net/emea/',  // varies by region
    'bot_webhook_path' => '/bot/webhook',  // HTTPS path Teams calls back for card submissions
],
```

`config/config.php` must already have this key (devs add their own values; not committed). Ensure `config/.gitignore` or the main `.gitignore` excludes `config/config.php`.

---

### AC-2 — `src/TeamsBot.php`: access token acquisition

```php
/**
 * Acquire Bot Framework access token via client credentials grant.
 * Caches in APC or a file-based JSON cache for the token lifetime.
 */
function getBotAccessToken(array $botConfig): string
{
    // File-based token cache (no APCu required)
    $cacheFile = sys_get_temp_dir() . '/asyncstandup_bot_token.json';
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    $payload = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $botConfig['app_id'],
        'client_secret' => $botConfig['app_secret'],
        'scope'         => 'https://api.botframework.com/.default',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 10,
    ]]);
    $url  = 'https://login.microsoftonline.com/botframework.com/oauth2/v2.0/token';
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { throw new \RuntimeException('Failed to fetch bot access token'); }

    $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $token = $data['access_token'] ?? '';
    if ($token === '') { throw new \RuntimeException('Empty access token: ' . $body); }

    file_put_contents($cacheFile, json_encode([
        'access_token' => $token,
        'expires_at'   => time() + (int) ($data['expires_in'] ?? 3600),
    ]));
    return $token;
}
```

---

### AC-3 — `src/TeamsBot.php`: `buildPromptCard(array $team, array $questions, string $token): array`

Returns Adaptive Card v1.4 with:
- Header: `"🤖 AsyncStandUp — Daily Standup"`, team name, date
- Per-question `Input.Text` block (multiline) with question text as label, id = `"q_{$question['id']}"`
- Hidden data field: `"token" => $token` in `Action.Submit`'s `data` object
- Submit button: `"Submit Standup"`
- Footer: `"⏰ Expires {$expiryLocal}"` — compute expiry from `standup_tokens.expires_at` in team timezone

Card outermost structure:
```php
[
    'type'        => 'message',
    'attachments' => [[
        'contentType' => 'application/vnd.microsoft.card.adaptive',
        'content'     => [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type'    => 'AdaptiveCard',
            'version' => '1.4',
            'body'    => [...],
            'actions' => [[
                'type'  => 'Action.Submit',
                'title' => 'Submit Standup',
                'data'  => ['token' => $token],
            ]],
        ],
    ]],
]
```

---

### AC-4 — `src/TeamsBot.php`: `sendDmPrompt(PDO $pdo, array $user, array $team, array $questions, string $token, array $botConfig): bool`

```php
function sendDmPrompt(PDO $pdo, array $user, array $team, array $questions, string $token, array $botConfig): bool
{
    $convRef = $user['teams_conversation_ref'] ? json_decode($user['teams_conversation_ref'], true) : null;
    if ($convRef === null) {
        // No conversation ref yet — cannot send DM; caller falls back to email
        return false;
    }

    try {
        $accessToken = getBotAccessToken($botConfig);
    } catch (\Throwable $e) {
        error_log("[AsyncStandUp] Bot token error for user {$user['id']}: " . $e->getMessage());
        return false;
    }

    $card = buildPromptCard($team, $questions, $token);

    $serviceUrl  = rtrim($convRef['serviceUrl'] ?? $botConfig['service_url'], '/');
    $convId      = $convRef['conversation']['id'] ?? '';
    $endpoint    = "{$serviceUrl}/v3/conversations/{$convId}/activities";

    $payload = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
        'content'       => $payload,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);

    $response = @file_get_contents($endpoint, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) { $code = (int) $m[1]; }
    }

    if ($code < 200 || $code >= 300) {
        error_log("[AsyncStandUp] Bot DM failed for user {$user['id']}: HTTP {$code} — {$response}");
        return false;
    }
    return true;
}
```

---

### AC-5 — `src/StandupEmailer.php`: branch on `notification_channel`

In the function that sends per-user standup prompts (cron loop over members), add:

```php
require_once __DIR__ . '/TeamsBot.php';

$channel  = $team['notification_channel'] ?? 'email';
$botConfig = $config['teams_bot'] ?? [];

if ($channel === 'teams' && !empty($botConfig)) {
    $sent = sendDmPrompt($pdo, $user, $team, $questions, $tokenStr, $botConfig);
    if ($sent) { continue; }  // DM sent — skip email
    // Fall through to email on failure
    error_log("[AsyncStandUp] Teams DM failed for user {$user['id']} team {$team['id']} — falling back to email");
}
// email path (unchanged)
sendStandupPromptEmail($user, $team, $questions, $tokenStr);
```

`teams-summary` mode: prompt is always email (channel summary only via Teams) — no branching needed here.

---

### AC-6 — PHPUnit tests: 3 new tests

New test class `tests/TeamsBotTest.php`:

| Test | What it verifies |
|---|---|
| `testBuildPromptCardHasAllQuestions` | Card body contains an `Input.Text` element per question; count matches `count($questions)` |
| `testBuildPromptCardEmbeddsToken` | `Action.Submit.data.token` equals the supplied token string |
| `testSendDmPromptReturnsFalseWithNoConvRef` | `$user['teams_conversation_ref'] = null` → `sendDmPrompt()` returns `false` without making HTTP calls |

`getBotAccessToken()` is not unit-tested (requires live OAuth) — integration test is manual.

---

## Files Changed

| File | Change |
|---|---|
| `config/config.example.php` | Add `teams_bot` block |
| `src/TeamsBot.php` (new) | `getBotAccessToken()`, `buildPromptCard()`, `sendDmPrompt()` |
| `src/StandupEmailer.php` | Branch on `teams` mode in prompt-send loop |
| `tests/TeamsBotTest.php` (new) | 3 tests |
