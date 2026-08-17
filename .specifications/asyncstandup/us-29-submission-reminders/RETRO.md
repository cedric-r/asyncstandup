# RETRO — US-29: Submission Reminders

**Story**: US-29 — Submission Reminders (cron pass 3, reminder_sent_at, chase email)
**Branch**: `feature/us-29-submission-reminders`
**Merge commit**: `159b260`
**Review cycles**: 1
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `tests/schema-sqlite.sql` | Added `reminder_sent_at TEXT NULL` inside `CREATE TABLE standup_tokens` |
| `db/schema.sql` | Appended `ALTER TABLE standup_tokens ADD COLUMN reminder_sent_at DATETIME NULL;` |
| `db/schema-postgresql.sql` | Added `reminder_sent_at TIMESTAMP NULL` inline in CREATE TABLE + `ALTER TABLE IF NOT EXISTS` at bottom |
| `src/StandupEmailer.php` | Added `getPendingUnremindedTokens()`, `markReminderSent()`, `sendSubmissionReminder()` |
| `templates/email/standup_reminder.php` | New plain-text reminder email template |
| `cron/send_standups.php` | Pass 3 after the main teams loop — iterates pending unreminded tokens, calls send + mark |
| `tests/SubmissionReminderTest.php` | 3 tests: in-window returned, already-reminded excluded, already-used excluded |

**Test result**: 86 tests, 169 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle** — Gate D approved on first submission.

---

## Notes

1. **`/submit.php` vs `/standup.php`** — STORY.md AC-5 mentioned `/standup.php?token=` but the live codebase uses `/submit.php?token=` (confirmed in `sendStandupPrompt()` line 109). Risk noted in IMPL-PLAN; Team Lead confirmed correct URL before implementation began.
2. **Multi-line substitution in schema-postgresql.sql** — `sed -i` cannot do multi-line pattern replacement in Git Bash on Windows; used a PHP one-liner (`str_replace` on file contents) to add `reminder_sent_at` inline in the CREATE TABLE body.
3. **Pass 3 placement** — inserted between the closing `}` of the teams loop and the Helpers section comment. The existing `logCronError()` helper is reused for consistent error logging format.
4. **`display_name` nullable** — PHPStan flagged potential null in `sendSubmissionReminder()`; handled with `$token['display_name'] ?? $to` fallback for both `$toName` and `$userName`.
