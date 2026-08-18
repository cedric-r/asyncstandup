# US-39: Bot Webhook Endpoint & Card Submission Handler

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-39-teams-bot-webhook`  
**Depends on**: US-38 merged (`TeamsBot.php`, `teams_conversation_ref`, `sendDmPrompt`)

---

## Story

**As a** standup participant who filled in a Teams Adaptive Card  
**I want** my answers saved immediately when I click Submit  
**So that** I don't need to open a browser to complete my standup

---

## Acceptance Criteria

### AC-1 — `public/bot/webhook.php`: secure HTTPS entry point

```php
<?php
declare(strict_types=1);

// Must be HTTPS in production — enforce if needed
$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/BotActivityHandler.php';
require_once __DIR__ . '/../../src/TeamsBot.php';

$pdo    = getDb($config);
$botCfg = $config['teams_bot'] ?? [];

// Validate Bot Framework JWT
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!validateBotJwt($authHeader, $botCfg)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Read activity
$body = file_get_contents('php://input');
$activity = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

header('Content-Type: application/json');
$handler = new BotActivityHandler($pdo, $botCfg);
[$code, $responseBody] = $handler->handle($activity);

http_response_code($code);
echo json_encode($responseBody);
```

---

### AC-2 — `src/TeamsBot.php`: `validateBotJwt(string $authHeader, array $botConfig): bool`

Bot Framework JWT validation (lightweight — no ext-jwt library required):

```php
function validateBotJwt(string $authHeader, array $botConfig): bool
{
    if (!str_starts_with($authHeader, 'Bearer ')) { return false; }
    $jwt = substr($authHeader, 7);

    // Decode header + payload (no signature verification in dev — MUST verify in prod)
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) { return false; }

    $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 === 0 ? 0 : 4 - strlen($parts[1]) % 4, '=')), true);
    if (!$payload) { return false; }

    // Audience must be our Bot AppId
    $aud = $payload['aud'] ?? '';
    if ($aud !== $botConfig['app_id']) { return false; }

    // Issuer must be Bot Framework
    $iss = $payload['iss'] ?? '';
    if (!str_contains($iss, 'botframework.com') && !str_contains($iss, 'microsoftonline.com')) {
        return false;
    }

    // Expiry check
    $exp = $payload['exp'] ?? 0;
    if ($exp < time()) { return false; }

    return true;
    // NOTE: Full RS256 signature verification via Microsoft JWKS endpoint is required for production.
    // JWKS: https://login.botframework.com/v1/.well-known/openidconfiguration
    // Implementation: fetch JWKS, find key by `kid`, verify RSA signature.
    // For v1 (dev/test): audience + issuer + expiry checks provide meaningful but incomplete validation.
    // Flag this as a legacy-risk task if deploying to production before signature verification is added.
}
```

**⚠️ Legacy-risk**: full RS256 signature verification omitted from v1. Acceptable for internal/dev deployments. Production deployments must add signature verification before go-live.

---

### AC-3 — `src/BotActivityHandler.php`: activity router

```php
class BotActivityHandler
{
    public function __construct(
        private PDO   $pdo,
        private array $botConfig
    ) {}

    /** @return array{int, array} [httpStatusCode, responseBody] */
    public function handle(array $activity): array
    {
        $type = $activity['type'] ?? '';

        return match ($type) {
            'invoke'             => $this->handleInvoke($activity),
            'conversationUpdate' => $this->handleConversationUpdate($activity),
            default              => [200, ['status' => 'ignored']],
        };
    }
    ...
}
```

---

### AC-4 — `BotActivityHandler::handleInvoke()`: card submission → save answers

```php
private function handleInvoke(array $activity): array
{
    $data  = $activity['value'] ?? [];
    $token = $data['token'] ?? '';

    if ($token === '') { return [400, ['error' => 'Missing token']]; }

    // Load token from DB
    $tokenRow = getTokenByValue($this->pdo, $token);
    if (!$tokenRow) { return [404, ['error' => 'Token not found']]; }

    // Replay guard: already submitted
    if (!empty($tokenRow['used_at'])) {
        $this->sendConfirmationDm($activity, 'already_submitted');
        return [409, ['error' => 'Already submitted']];
    }

    // Extract answers: keys q_{id} → values
    $answers = [];
    foreach ($data as $key => $value) {
        if (preg_match('/^q_(\d+)$/', $key, $m)) {
            $answers[] = ['question_id' => (int) $m[1], 'text' => (string) $value];
        }
    }
    if (empty($answers)) { return [400, ['error' => 'No answers']]; }

    // Save submission (reuse existing SubmissionRepository function)
    $userId       = $this->resolveUser($activity);
    $submissionId = saveSubmission($this->pdo, $tokenRow, $answers, $userId);

    // Mark token used
    markTokenUsed($this->pdo, $tokenRow['id']);

    // Send confirmation DM
    $this->sendConfirmationDm($activity, 'submitted', $submissionId);

    return [200, ['status' => 'submitted', 'submission_id' => $submissionId]];
}
```

`resolveUser()`: look up `users.teams_aad_id` matching `$activity['from']['aadObjectId']`; if not found, returns null (anonymous submit).

---

### AC-5 — `BotActivityHandler::handleConversationUpdate()`: save conversation ref

```php
private function handleConversationUpdate(array $activity): array
{
    $membersAdded = $activity['membersAdded'] ?? [];
    foreach ($membersAdded as $member) {
        $aadId = $member['aadObjectId'] ?? '';
        if ($aadId === '') { continue; }

        $convRef = json_encode([
            'serviceUrl'   => $activity['serviceUrl'] ?? '',
            'conversation' => $activity['conversation'] ?? [],
            'bot'          => $activity['recipient']   ?? [],
            'channelId'    => $activity['channelId']   ?? 'msteams',
        ]);

        // Save to users.teams_conversation_ref where teams_aad_id matches
        $this->pdo->prepare(
            'UPDATE users SET teams_conversation_ref = ? WHERE teams_aad_id = ?'
        )->execute([$convRef, $aadId]);
    }
    return [200, ['status' => 'ok']];
}
```

---

### AC-6 — `BotActivityHandler::sendConfirmationDm()`: DM back to user

```php
private function sendConfirmationDm(array $activity, string $status, int $submissionId = 0): void
{
    $serviceUrl = $activity['serviceUrl'] ?? '';
    $convId     = $activity['conversation']['id'] ?? '';
    if ($serviceUrl === '' || $convId === '') { return; }

    $text = match ($status) {
        'submitted'        => "✅ Standup submitted! Thank you.",
        'already_submitted'=> "ℹ️ You have already submitted your standup for today.",
        default            => "Done.",
    };

    try {
        $accessToken = getBotAccessToken($this->botConfig);
        $payload     = json_encode(['type' => 'message', 'text' => $text], JSON_UNESCAPED_UNICODE);
        $endpoint    = rtrim($serviceUrl, '/') . "/v3/conversations/{$convId}/activities";
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 5,
        ]]);
        @file_get_contents($endpoint, false, $ctx);
    } catch (\Throwable $e) {
        error_log("[AsyncStandUp] Confirmation DM failed: " . $e->getMessage());
        // Non-critical — submission is already saved
    }
}
```

---

### AC-7 — `public/bot/` `.htaccess` or routing

If `public/.htaccess` has existing rewrites, add:
```apache
# Bot webhook — no rewrite needed (direct PHP file)
RewriteRule ^bot/webhook$ bot/webhook.php [L]
```

Or ensure the file is directly accessible at `/bot/webhook` through existing routing.

---

### AC-8 — PHPUnit tests: 4 new tests

New test class `tests/BotWebhookTest.php`:

| Test | What it verifies |
|---|---|
| `testHandleUnknownActivityReturns200Ignored` | `type = 'message'` (not invoke/conversationUpdate) → returns `[200, ['status'=>'ignored']]` |
| `testHandleInvokeRejectsAlreadyUsedToken` | Token with `used_at` set → returns `[409, ...]` |
| `testHandleInvokeSavesAnswers` | Valid token + 2 answers → `saveSubmission` called (check row in DB); returns `[200, ['status'=>'submitted', ...]]` |
| `testHandleConversationUpdateSavesConvRef` | `membersAdded[0].aadObjectId` matches a user's `teams_aad_id` → `users.teams_conversation_ref` updated |

---

## Files Changed

| File | Change |
|---|---|
| `public/bot/webhook.php` (new) | HTTPS entry point |
| `public/bot/` (new dir) | |
| `src/BotActivityHandler.php` (new) | `handle()`, `handleInvoke()`, `handleConversationUpdate()`, `sendConfirmationDm()` |
| `src/TeamsBot.php` | Add `validateBotJwt()` |
| `tests/BotWebhookTest.php` (new) | 4 tests |

---

## Security Note (legacy-risk)

`validateBotJwt()` in v1 validates `aud`, `iss`, and `exp` only — RS256 signature is NOT verified against Microsoft's JWKS. This is documented and acceptable for dev/staging. Before production go-live, a follow-up task must add signature verification using the public keys from `https://login.botframework.com/v1/.well-known/openidconfiguration`. Flag in `public/bot/webhook.php` with a `// TODO(security):` comment.
