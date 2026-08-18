# RETRO — US-38: Bot DM Prompts

**Story**: US-38 — Bot DM Prompts (Proactive Message via Bot Framework)
**Branch**: `feature/us-38-teams-bot-prompts`
**Merge commit**: `be913da`
**Review cycles**: 2
**Date**: 2026-08-18

---

## What was built

| File | Change |
|---|---|
| `config/config.example.php` | `teams_bot` block (app_id, app_secret, service_url, bot_webhook_path) |
| `src/TeamsBot.php` (new) | `getBotAccessToken()`, `buildPromptCard()`, `sendDmPrompt()` |
| `src/StandupEmailer.php` | `require_once TeamsBot.php`; SELECT id+question FETCH_ASSOC; `$questionTexts` for email; Teams branch before sendMail; `getDeveloperMembers()` + `teams_conversation_ref` column |
| `tests/TeamsBotTest.php` (new) | 4 tests (3 initial + 1 added in cycle 2) |
| `tests/bootstrap.php` | Added `TeamsBot.php` require |

**Test result**: 119 tests, 234 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**2 cycles**

---

## Cycle 1 findings (CODE REVIEW)

1. **[CRITICAL] `getDeveloperMembers()` missing `teams_conversation_ref`** — `sendDmPrompt()` checks `$user['teams_conversation_ref']`; the column was absent from the SELECT so it always hit `?? null` → return false silently. Fix: added `u.teams_conversation_ref` to the SELECT in `getDeveloperMembers()`.
2. **[MAJOR] Missing `$response === false` guard in `sendDmPrompt()`** — on network failure `$http_response_header` is unpopulated; the `@var` annotation has no runtime effect so the foreach triggers `E_WARNING`. Fix: added `if ($response === false)` guard + error_log + return false before the foreach (mirrors US-37 `postChannelSummary()` pattern).
3. **[SHOULD] Empty string conv ref** — added `testSendDmPromptReturnsFalseWithEmptyConvRef()` to confirm `''` also returns false.

---

## Notes

1. **Template compatibility** — `standup_prompt.php` iterates `$q` as plain string. Changed `getDeveloperMembers()` question fetch to `FETCH_ASSOC` (`id + question`), kept `$questionTexts = array_column($questionRows, 'question')` for the email template; `$questionRows` (assoc) passed to Teams path only.
2. **`$member['id']` confirmed** — cron line 43 uses `$member['id']` so safe to reference in error_log.
3. **`@chmod(0600)` on token cache file** — added after `file_put_contents()`; no-op on Windows but correct on Linux/macOS.
