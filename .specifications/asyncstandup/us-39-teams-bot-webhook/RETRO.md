# RETRO — US-39: Bot Webhook Endpoint & Card Submission Handler

**Story**: US-39 — Bot Webhook Endpoint & Card Submission Handler
**Branch**: `feature/us-39-teams-bot-webhook`
**Merge commit**: `b8d32c9`
**Review cycles**: 2
**Date**: 2026-08-18

---

## What was built

| File | Change |
|---|---|
| `src/TeamsBot.php` | `validateBotJwt()` — aud/iss/exp checks; base64url padding `(4-len%4)%4`; TODO(security) RS256 JWKS |
| `src/BotActivityHandler.php` (new) | `handle()` dispatcher; `handleInvoke()` + `resolveUser()`; `handleConversationUpdate()` + `sanitiseServiceUrl()`; `sendConfirmationDm()` |
| `public/bot/webhook.php` (new) | JWT guard + handler dispatch |
| `public/.htaccess` | `RewriteRule ^bot/webhook$` |
| `tests/BotWebhookTest.php` (new) | 5 tests (4 initial + 1 added in cycle 1) |
| `tests/bootstrap.php` | Added `BotActivityHandler.php` require |

**Test result**: 124 tests, 248 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**2 cycles**

---

## Cycle 1 findings (Test Validator)

1. **[MAJOR] `testHandleInvokeRejectsAlreadyUsedToken` missing DB assertion** — added `COUNT(standup_submissions) = 0` to confirm replay guard fires before `saveSubmission()`.
2. **[RECOM] Invalid serviceUrl negative test** — added `testHandleConversationUpdateRejectsInvalidServiceUrl`: `http://evil.com/` → `sanitiseServiceUrl()` returns null → stored as null in conv ref JSON.

## Cycle 2 findings (Code Reviewer + Security Auditor)

3. **[MAJOR] `sendConfirmationDm()` serviceUrl not allowlisted** — Bearer token could be exfiltrated to attacker-controlled endpoint via crafted activity. Fixed: `$rawServiceUrl` routed through `sanitiseServiceUrl()` before constructing endpoint. Returns early if null/empty. Consistent with `handleConversationUpdate()`.

---

## Notes

1. **`getTokenByValue()` doesn't exist** — used `getTokenData()` from `SubmissionRepository.php` (same function, different name from spec).
2. **`markTokenUsed()` doesn't exist** — `saveSubmission()` already marks the token used inside its transaction. No separate call needed.
3. **`saveSubmission()` is void** — submission ID retrieved via `SELECT id FROM standup_submissions WHERE token_id = ? ORDER BY id DESC LIMIT 1` after the call.
4. **`$answers` format** — `saveSubmission()` expects `array<int, string>` keyed by question_id; extracted directly from `q_N` activity keys as `[(int)$m[1] => (string)$value]`.
5. **`sanitiseServiceUrl()` allowlist** — `smba.trafficmanager.net` + `webchat.botframework.com`; null + error_log for anything else.
