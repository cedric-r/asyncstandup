# TASKS — US-39: Bot Webhook Endpoint & Card Submission Handler

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-39-teams-bot-webhook`  
**Agent**: PHP Developer (`fa2e6dbf`)  
**Dependency**: US-38 merged

---

## Phase 1 — Branch

**T-1** `backend-dev`
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout main && git pull && git checkout -b feature/us-39-teams-bot-webhook
```

---

## Phase 2 — JWT validation in `src/TeamsBot.php` (AC-2)

**T-2** `backend-dev` — Add `validateBotJwt(string $authHeader, array $botConfig): bool` to `src/TeamsBot.php`

Full implementation from STORY.md AC-2. Key validations:
1. Starts with `"Bearer "`
2. Three-part JWT (`header.payload.signature`)
3. Payload `aud` equals `$botConfig['app_id']`
4. Payload `iss` contains `botframework.com` or `microsoftonline.com`
5. Payload `exp` > `time()`

Add `// TODO(security): add RS256 signature verification via JWKS before production go-live` comment.

Base64url decode helper:
```php
function base64urlDecode(string $input): string
{
    return base64_decode(str_pad(strtr($input, '-_', '+/'), strlen($input) % 4 === 0 ? strlen($input) : strlen($input) + 4 - strlen($input) % 4, '='));
}
```

---

## Phase 3 — `src/BotActivityHandler.php` (AC-3, AC-4, AC-5, AC-6)

**T-3** `backend-dev` — Create `src/BotActivityHandler.php`

Add `require_once` references for:
- `src/SubmissionRepository.php` (for `saveSubmission()`, `markTokenUsed()`)
- `src/TeamsBot.php` (for `getBotAccessToken()`)
- Any other repository functions needed

**T-4** `backend-dev` — Implement `handle()` dispatcher (AC-3)

Match on `$activity['type']`:
- `'invoke'` → `handleInvoke()`
- `'conversationUpdate'` → `handleConversationUpdate()`
- anything else → `[200, ['status' => 'ignored']]`

**T-5** `backend-dev` — Implement `handleInvoke()` (AC-4)

Steps:
1. Extract `$token = $activity['value']['token']` — return 400 if missing
2. `getTokenByValue($pdo, $token)` — need to add this helper or use existing lookup. Check `src/TeamRepository.php` or `src/SubmissionRepository.php` for a token-lookup function. Add one if missing:
   ```php
   function getTokenByValue(PDO $pdo, string $token): ?array
   {
       $stmt = $pdo->prepare('SELECT * FROM standup_tokens WHERE token = ?');
       $stmt->execute([$token]);
       return $stmt->fetch() ?: null;
   }
   ```
3. If `$tokenRow['used_at']` is not null → send "already submitted" DM → return 409
4. Extract answers: iterate `$activity['value']` keys matching `q_\d+`
5. Call `saveSubmission()` — pass `$tokenRow` and `$answers`; resolve `$userId` via `resolveUser()`
6. Call `markTokenUsed()` — check this function exists in `SubmissionRepository.php`; add if missing
7. `sendConfirmationDm()` with `'submitted'` status
8. Return `[200, ['status' => 'submitted', 'submission_id' => $id]]`

**T-6** `backend-dev` — Implement `resolveUser(array $activity): ?int`

```php
private function resolveUser(array $activity): ?int
{
    $aadId = $activity['from']['aadObjectId'] ?? '';
    if ($aadId === '') { return null; }
    $stmt = $this->pdo->prepare('SELECT id FROM users WHERE teams_aad_id = ?');
    $stmt->execute([$aadId]);
    $row = $stmt->fetch();
    return $row ? (int) $row['id'] : null;
}
```

**T-7** `backend-dev` — Implement `handleConversationUpdate()` (AC-5)

Full implementation from STORY.md AC-5. Iterate `membersAdded`; for each with a non-empty `aadObjectId`, build `$convRef` JSON and UPDATE `users.teams_conversation_ref`.

**T-8** `backend-dev` — Implement `sendConfirmationDm()` (AC-6)

Full implementation from STORY.md AC-6. Wrapped in try/catch — DM failure must never fail the HTTP response (submission is already saved).

---

## Phase 4 — Entry point (AC-1, AC-7)

**T-9** `backend-dev` — Create `public/bot/` directory + `webhook.php`

Full content from STORY.md AC-1.

`require_once` chain (adjust paths as needed):
```php
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/TeamsBot.php';
require_once __DIR__ . '/../../src/BotActivityHandler.php';
require_once __DIR__ . '/../../src/SubmissionRepository.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
```

**T-10** `backend-dev` — Verify `/bot/webhook` is routable

Check `public/.htaccess`:
- If there's a catch-all rewrite to `index.php`, add an exclusion rule before it:
  ```apache
  RewriteRule ^bot/webhook$ bot/webhook.php [L,QSA]
  ```
- If no catch-all: direct file access will work; no change needed.

---

## Phase 5 — Tests (AC-8)

**T-11** `backend-dev` — Create `tests/BotWebhookTest.php` (4 tests)

```php
class BotWebhookTest extends TestCase
{
    private PDO $pdo;
    private BotActivityHandler $handler;

    protected function setUp(): void
    {
        $this->pdo     = createTestPdo();
        $this->handler = new BotActivityHandler($this->pdo, ['app_id' => 'testapp']);
        // Seed minimal DB
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash, teams_aad_id) VALUES (1, 'u@x.com', 'h', 'aad-123')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by) VALUES (1, 1, 'T', 'UTC', '09:00', 1)");
        $this->pdo->exec("INSERT INTO standup_tokens (id, team_id, user_id, token, send_date, expires_at) VALUES (1, 1, 1, 'validtoken', date('now'), datetime('now', '+2 hours'))");
        $this->pdo->exec("INSERT INTO team_questions (id, team_id, question, position) VALUES (1, 1, 'Q1?', 1)");
    }

    public function testHandleUnknownActivityReturns200Ignored(): void
    {
        [$code, $body] = $this->handler->handle(['type' => 'message', 'text' => 'hello']);
        $this->assertEquals(200, $code);
        $this->assertEquals('ignored', $body['status']);
    }

    public function testHandleInvokeRejectsAlreadyUsedToken(): void
    {
        $this->pdo->exec("UPDATE standup_tokens SET used_at = datetime('now') WHERE id = 1");
        $activity = ['type'=>'invoke','value'=>['token'=>'validtoken'],'serviceUrl'=>'','conversation'=>['id'=>''],'from'=>['aadObjectId'=>'aad-123']];
        [$code, $body] = $this->handler->handle($activity);
        $this->assertEquals(409, $code);
    }

    public function testHandleInvokeSavesAnswers(): void
    {
        $activity = [
            'type'         => 'invoke',
            'value'        => ['token' => 'validtoken', 'q_1' => 'My answer'],
            'serviceUrl'   => '',
            'conversation' => ['id' => ''],
            'from'         => ['aadObjectId' => 'aad-123'],
        ];
        [$code, $body] = $this->handler->handle($activity);
        $this->assertEquals(200, $code);
        $this->assertEquals('submitted', $body['status']);
        $count = $this->pdo->query("SELECT COUNT(*) FROM standup_submissions")->fetchColumn();
        $this->assertEquals(1, (int)$count);
    }

    public function testHandleConversationUpdateSavesConvRef(): void
    {
        $activity = [
            'type'         => 'conversationUpdate',
            'membersAdded' => [['aadObjectId' => 'aad-123']],
            'serviceUrl'   => 'https://smba.trafficmanager.net/',
            'conversation' => ['id' => 'conv-abc'],
            'recipient'    => ['id' => 'bot-1'],
            'channelId'    => 'msteams',
        ];
        $this->handler->handle($activity);
        $row = $this->pdo->query("SELECT teams_conversation_ref FROM users WHERE id = 1")->fetch();
        $this->assertNotNull($row['teams_conversation_ref']);
        $ref = json_decode($row['teams_conversation_ref'], true);
        $this->assertEquals('https://smba.trafficmanager.net/', $ref['serviceUrl']);
    }
}
```

**T-12** `backend-dev` — Run full test suite; target ≥122 tests (118 prior + 4 new)

---

## Phase 6 — Commit and signal

**T-13** `backend-dev`
```bash
git add \
  src/TeamsBot.php src/BotActivityHandler.php \
  public/bot/webhook.php public/.htaccess \
  tests/BotWebhookTest.php \
  .specifications/asyncstandup/us-39-teams-bot-webhook/
git commit -m "feat(us-39): bot webhook — JWT validation, invoke handler, answer save, confirmation DM, conv ref"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (webhook.php entry point) | T-9 |
| AC-2 (validateBotJwt) | T-2 |
| AC-3 (handle dispatcher) | T-4 |
| AC-4 (handleInvoke) | T-5, T-6 |
| AC-5 (handleConversationUpdate) | T-7 |
| AC-6 (sendConfirmationDm) | T-8 |
| AC-7 (routing) | T-10 |
| AC-8 (4 tests) | T-11, T-12 |

**Estimate**: ~10h
