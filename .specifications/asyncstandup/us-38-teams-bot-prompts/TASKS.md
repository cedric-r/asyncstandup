# TASKS — US-38: Bot DM Prompts

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-38-teams-bot-prompts`  
**Agent**: PHP Developer (`fa2e6dbf`)  
**Dependency**: US-36 merged

---

## Phase 1 — Branch

**T-1** `backend-dev`
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout main && git pull && git checkout -b feature/us-38-teams-bot-prompts
```

---

## Phase 2 — Config (AC-1)

**T-2** `backend-dev` — Add `teams_bot` block to `config/config.example.php`

Open `config/config.example.php` and append (or add inline) the block from STORY.md AC-1. Confirm `config.php` is in `.gitignore`.

---

## Phase 3 — `src/TeamsBot.php` (AC-2, AC-3, AC-4)

**T-3** `backend-dev` — Create `src/TeamsBot.php` with `getBotAccessToken()`

Full implementation from STORY.md AC-2. Key points:
- Token cache at `sys_get_temp_dir() . '/asyncstandup_bot_token.json'`
- 60-second buffer before expiry in cache check
- OAuth endpoint: `https://login.microsoftonline.com/botframework.com/oauth2/v2.0/token`
- Scope: `https://api.botframework.com/.default`
- Uses `file_get_contents` + `stream_context_create` (no cURL)
- On failure: throw `\RuntimeException` (caller catches and falls back to email)

**T-4** `backend-dev` — Add `buildPromptCard(array $team, array $questions, string $token): array`

In `src/TeamsBot.php`. Implementation from STORY.md AC-3.

Question input blocks:
```php
foreach ($questions as $q) {
    $body[] = [
        'type'        => 'TextBlock',
        'text'        => $q['question'],
        'wrap'        => true,
        'weight'      => 'Bolder',
    ];
    $body[] = [
        'type'        => 'Input.Text',
        'id'          => 'q_' . $q['id'],
        'isMultiline' => true,
        'placeholder' => 'Your answer...',
    ];
}
```

Expiry footer: load `expires_at` from the token string (caller passes it). Convert to team timezone:
```php
$expiry = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $tokenExpiresAt, new DateTimeZone('UTC'));
$localExpiry = $expiry->setTimezone(new DateTimeZone($team['timezone']))->format('H:i T');
```
Note: `buildPromptCard` may need `string $tokenExpiresAt` as an additional parameter, or derive expiry from standup convention (standup_time + 2h). Use `standup_time + 2 hours` if `expires_at` is not easily passed.

**T-5** `backend-dev` — Add `sendDmPrompt(...)` 

Full implementation from STORY.md AC-4. Key points:
- Returns `false` immediately if `teams_conversation_ref` is null (no conversation ref yet)
- `getBotAccessToken()` wrapped in try/catch — return `false` on exception
- HTTP POST to `{serviceUrl}/v3/conversations/{convId}/activities`
- Uses `file_get_contents` + `stream_context_create` + parse `$http_response_header`
- Returns `false` on non-2xx; logs via `error_log`

---

## Phase 4 — `src/StandupEmailer.php` branch (AC-5)

**T-6** `backend-dev` — Locate the cron prompt-sending loop

```bash
grep -n "function.*send\|sendStandup\|foreach.*member\|foreach.*user" "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup/src/StandupEmailer.php" | head -20
```

**T-7** `backend-dev` — Insert Teams branch before email send

Add `require_once __DIR__ . '/TeamsBot.php';` at the top of `StandupEmailer.php`.

In the per-user dispatch logic, add the branch from STORY.md AC-5. The `$config` array must be available in scope (pass as parameter or use a global — match the existing pattern in the codebase).

`teams-summary` mode teams: no change here — prompts stay email-only.

---

## Phase 5 — Tests (AC-6)

**T-8** `backend-dev` — Create `tests/TeamsBotTest.php` (3 tests)

```php
class TeamsBotTest extends TestCase
{
    private array $team;
    private array $questions;

    protected function setUp(): void
    {
        $this->team = ['id' => 1, 'name' => 'Engineering', 'timezone' => 'UTC', 'standup_time' => '09:00:00'];
        $this->questions = [
            ['id' => 1, 'question' => 'What did you do yesterday?', 'is_blocker' => 0],
            ['id' => 2, 'question' => 'What will you do today?',    'is_blocker' => 0],
        ];
    }

    public function testBuildPromptCardHasAllQuestions(): void
    {
        $card = buildPromptCard($this->team, $this->questions, 'tok123');
        $body = json_encode($card);
        $this->assertStringContainsString('q_1', $body);
        $this->assertStringContainsString('q_2', $body);
        $this->assertStringContainsString('What did you do yesterday?', $body);
    }

    public function testBuildPromptCardEmbeddsToken(): void
    {
        $card    = buildPromptCard($this->team, $this->questions, 'my-secret-token');
        $content = $card['attachments'][0]['content'];
        $found   = false;
        foreach ($content['actions'] as $action) {
            if ($action['type'] === 'Action.Submit' && ($action['data']['token'] ?? '') === 'my-secret-token') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Token not found in Action.Submit data');
    }

    public function testSendDmPromptReturnsFalseWithNoConvRef(): void
    {
        $pdo  = createTestPdo();
        $user = ['id' => 1, 'teams_conversation_ref' => null];
        $result = sendDmPrompt($pdo, $user, $this->team, $this->questions, 'tok', []);
        $this->assertFalse($result);
    }
}
```

**T-9** `backend-dev` — Run full test suite; target ≥118 tests (115 prior + 3 new)

---

## Phase 6 — Commit and signal

**T-10** `backend-dev`
```bash
git add \
  config/config.example.php \
  src/TeamsBot.php src/StandupEmailer.php \
  tests/TeamsBotTest.php \
  .specifications/asyncstandup/us-38-teams-bot-prompts/
git commit -m "feat(us-38): Teams bot DM prompts — access token, Adaptive Card, proactive DM, email fallback"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (config) | T-2 |
| AC-2 (getBotAccessToken) | T-3 |
| AC-3 (buildPromptCard) | T-4 |
| AC-4 (sendDmPrompt) | T-5 |
| AC-5 (StandupEmailer branch) | T-6, T-7 |
| AC-6 (3 tests) | T-8, T-9 |

**Estimate**: ~10h
