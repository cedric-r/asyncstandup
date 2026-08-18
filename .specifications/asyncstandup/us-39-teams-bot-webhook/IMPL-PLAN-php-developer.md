# IMPL-PLAN — US-39: Bot Webhook Endpoint & Card Submission Handler

**Status**: PENDING GATE C APPROVAL
**Branch**: `feature/us-39-teams-bot-webhook`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-39 — Bot Webhook Endpoint & Card Submission Handler

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-8 and TASKS.md T-1 through T-13.
No schema changes. No Composer dependencies.

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `getTokenData()` | Exists in `src/SubmissionRepository.php`. Signature: `getTokenData(PDO $pdo, string $token): ?array`. Returns `t.*, u.display_name, u.email` — includes `id`, `team_id`, `user_id`, `used_at`, `expires_at`. Use this instead of spec's `getTokenByValue()`. |
| `saveSubmission()` | Signature: `saveSubmission(PDO $pdo, int $tokenId, int $userId, int $teamId, array $answers): void`. `$answers` is `array<int, string>` keyed by `question_id → text` (not objects). **Internally marks token used** — no separate `markTokenUsed()` call needed. Returns void. |
| `markTokenUsed()` | Does NOT exist separately — `saveSubmission()` already does `UPDATE standup_tokens SET used_at = ?`. Spec's AC-4 step 6 to call `markTokenUsed()` is redundant; omit it. |
| Submission ID after `saveSubmission()` | `saveSubmission()` is void. Retrieve ID after: `SELECT id FROM standup_submissions WHERE token_id = ?`. |
| `$answers` format | From `q_N` activity keys → `[(int)N => (string)value]` array. Matches `saveSubmission()` signature. |
| `$userId` for `saveSubmission()` | Use `(int)$tokenRow['user_id']` (token knows the user). `resolveUser()` via AAD is a bonus lookup but not needed for `saveSubmission()`. |
| `.htaccess` | Only rewrites `api/v1/*`. No catch-all. Direct file access `/bot/webhook.php` works. Add a clean `RewriteRule ^bot/webhook$ bot/webhook.php [L,QSA]` rule for tidy URL. |
| `serviceUrl` security | STORY.md security note: validate `serviceUrl` from incoming activity against `^https://smba\.trafficmanager\.net/` before storing. Add validation in `handleConversationUpdate()`. |

---

## Files to Create / Change

| File | Change |
|---|---|
| `src/TeamsBot.php` | Add `validateBotJwt()` |
| `src/BotActivityHandler.php` (new) | Full class: `handle()`, `handleInvoke()`, `handleConversationUpdate()`, `sendConfirmationDm()`, `resolveUser()` |
| `public/bot/webhook.php` (new) | HTTPS entry point |
| `public/.htaccess` | Add `RewriteRule ^bot/webhook$` |
| `tests/BotWebhookTest.php` (new) | 4 PHPUnit tests |
| `tests/bootstrap.php` | Add `require_once` for `BotActivityHandler.php` |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-39-teams-bot-webhook` created from `main`.

---

### T-2 — `src/TeamsBot.php`: add `validateBotJwt()` (AC-2)

Append to `src/TeamsBot.php`:
```php
function validateBotJwt(string $authHeader, array $botConfig): bool
{
    if (!str_starts_with($authHeader, 'Bearer ')) { return false; }
    $jwt = substr($authHeader, 7);

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) { return false; }

    $padded  = str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - strlen($parts[1]) % 4, '=');
    $payload = json_decode(base64_decode($padded), true);
    if (!is_array($payload)) { return false; }

    $aud = (string)($payload['aud'] ?? '');
    if ($aud !== ($botConfig['app_id'] ?? '')) { return false; }

    $iss = (string)($payload['iss'] ?? '');
    if (!str_contains($iss, 'botframework.com') && !str_contains($iss, 'microsoftonline.com')) { return false; }

    $exp = (int)($payload['exp'] ?? 0);
    if ($exp < time()) { return false; }

    // TODO(security): Add RS256 signature verification via JWKS before production go-live.
    // JWKS: https://login.botframework.com/v1/.well-known/openidconfiguration
    return true;
}
```

---

### T-3 — T-8: `src/BotActivityHandler.php` (AC-3 through AC-6)

**require_once block:**
```php
require_once __DIR__ . '/SubmissionRepository.php';
require_once __DIR__ . '/TeamsBot.php';
```

**`handle(array $activity): array{int, array}`** — match on `$activity['type']`:
- `'invoke'` → `handleInvoke()`
- `'conversationUpdate'` → `handleConversationUpdate()`
- default → `[200, ['status' => 'ignored']]`

**`handleInvoke(array $activity): array`** (key deviations from STORY.md):
1. `$token = $activity['value']['token'] ?? ''` — return `[400, ...]` if empty
2. `getTokenData($this->pdo, $token)` — return `[404, ...]` if null
3. If `$tokenRow['used_at'] !== null` → `sendConfirmationDm($activity, 'already_submitted')` → return `[409, ...]`
4. Build `$answers = []` (int→string): iterate `$activity['value']`, `preg_match('/^q_(\d+)$/', $key, $m)` → `$answers[(int)$m[1]] = (string)$value`
5. If `$answers` empty → return `[400, ['error' => 'No answers']]`
6. `$userId = (int)$tokenRow['user_id']`; `resolveUser()` as optional AAD override
7. `saveSubmission($this->pdo, (int)$tokenRow['id'], $userId, (int)$tokenRow['team_id'], $answers)`
   → token already marked used internally; no separate call
8. Get submission ID: `SELECT id FROM standup_submissions WHERE token_id = ? ORDER BY id DESC LIMIT 1`
9. `sendConfirmationDm($activity, 'submitted')`
10. Return `[200, ['status' => 'submitted', 'submission_id' => $submissionId]]`

**`resolveUser(array $activity): ?int`** — query `users WHERE teams_aad_id = ?` from `$activity['from']['aadObjectId']`.

**`handleConversationUpdate(array $activity): array`**:
- Iterate `$activity['membersAdded']`; skip if `aadObjectId` empty
- **Security**: validate `$activity['serviceUrl']` matches `^https://smba\.trafficmanager\.net/` OR known BF domains before storing
- Build JSON blob: `{serviceUrl, conversation, bot, channelId}`
- `UPDATE users SET teams_conversation_ref = ? WHERE teams_aad_id = ?`
- Return `[200, ['status' => 'ok']]`

**`sendConfirmationDm(array $activity, string $status): void`** (per STORY.md AC-6):
- Extract `$serviceUrl`, `$convId`; return early if either empty
- `getBotAccessToken($this->botConfig)` in try/catch
- POST simple `{type: 'message', text: $text}` to `/v3/conversations/{convId}/activities`
- Wrapped in try/catch — failure must never throw (non-critical)

---

### T-9 — `public/bot/webhook.php` (AC-1)

Create `public/bot/` directory + `webhook.php` per STORY.md AC-1.

Require chain:
```php
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/TeamsBot.php';
require_once __DIR__ . '/../../src/BotActivityHandler.php';
require_once __DIR__ . '/../../src/SubmissionRepository.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
```

Add `// TODO(security): Enable RS256 JWKS signature verification before production.` comment near `validateBotJwt()` call.

---

### T-10 — `public/.htaccess` (AC-7)

Add before the existing `api/v1` rule:
```apache
RewriteRule ^bot/webhook$ bot/webhook.php [L,QSA]
```

---

### T-11 — `tests/BotWebhookTest.php` (AC-8)

4 tests per TASKS.md T-11. Key notes:
- SQLite `date('now')` / `datetime('now', '+2 hours')` for token seeding
- `testHandleInvokeSavesAnswers`: check `standup_submissions` count = 1 after invoke
- `testHandleConversationUpdateSavesConvRef`: validate `serviceUrl` in decoded JSON blob
- `tests/bootstrap.php`: add `require_once` for `BotActivityHandler.php`

---

### T-12 — Quality gate

Target: ≥123 tests (119 + 4), all pass; phpstan level 5 — 0 errors.

PHPStan risks:
- `saveSubmission()` return type is `void` — don't capture its return
- `json_decode` result — type-narrow with `is_array()` before indexing
- `$activity['value']` is `mixed` — cast before iterating
- `sendConfirmationDm()` return is `void` — annotation confirms

---

### T-13 — Commit

```bash
git add src/TeamsBot.php src/BotActivityHandler.php \
        public/bot/webhook.php public/.htaccess \
        tests/BotWebhookTest.php tests/bootstrap.php \
        .specifications/asyncstandup/us-39-teams-bot-webhook/
git commit -m "feat(us-39): bot webhook — JWT validation, invoke handler, answer save, confirmation DM, conv ref"
```
