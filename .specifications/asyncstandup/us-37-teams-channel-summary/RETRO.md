# RETRO — US-37: Teams Channel Summary (Incoming Webhook)

**Story**: US-37 — Teams Channel Summary (Incoming Webhook)
**Branch**: `feature/us-37-teams-channel-summary`
**Merge commit**: `de20ae6`
**Review cycles**: 1
**Date**: 2026-08-18

---

## What was built

| File | Change |
|---|---|
| `src/TeamsMessageBuilder.php` (new) | `buildSummaryCard()` — Adaptive Card v1.4; header + participation count; members sorted (submitted first); blocker prefix ⚠️; `submitted_at` null-safe; mood footer null-safe |
| `src/TeamsNotifier.php` (new) | `postChannelSummary()` — `@file_get_contents` + `stream_context_create`; HTTP status parsed from `$http_response_header`; returns bool |
| `src/SummaryEmailer.php` | `require_once` + `$membersForCard` parallel build loop + Teams branch inside `sendSummaryEmail()` after `assembleSummaryData()`, before `$subject` |
| `tests/TeamsChannelSummaryTest.php` (new) | 3 tests — card structure, mood present, mood absent |
| `tests/bootstrap.php` | Added `TeamsMessageBuilder.php` + `TeamsNotifier.php` requires |

**Test result**: 115 tests, 228 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle**

---

## Notes

1. **`assembleSummaryData()` shape mismatch** — returns `{developers[], questions[], answerMap[]}`, not `{members[], participation_pct, avg_mood}`. Parallel `$membersForCard` loop added inside `sendSummaryEmail()` after the existing `$submitterData`/`$nonSubmitters` loop. Does not modify the email path variables.
2. **`submitted_at` not available** — `assembleSummaryData()` query doesn't fetch submission timestamp. Passed as `null`; card builder renders `"✅ Alice"` without time suffix. Acceptable for US-37.
3. **`avg_mood` wired as `null`** — US-37 does not connect mood tracking. Footer omitted. US-38+ can extend.
4. **PHPStan `isset()` + `!== null` redundant** — Fixed via `$raw = $x ?? null; $y = $raw !== null ? ... : null` pattern (×2). `$http_response_header` — used `/** @var string[] */` annotation + direct iteration (PHPStan knows the var is always set after `file_get_contents`).
5. **Test: `json_encode` slash escaping** — `3.5/5` encoded as `3.5\/5` by default. Tests use `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` to match the plain string.
