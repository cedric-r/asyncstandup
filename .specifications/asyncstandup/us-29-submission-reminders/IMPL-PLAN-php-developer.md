# IMPL-PLAN — US-29: Submission Reminders

**Status**: APPROVED
**Branch**: `feature/us-29-submission-reminders`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-29 — Submission Reminders

---

## Scope

All changes are within the bounds of STORY.md AC-1 through AC-6 and TASKS.md T-1 through T-10.

No new Composer dependencies. No public API changes. No changes outside the 7 files listed below.

---

## Files to Create or Modify

| File | Type | Change |
|---|---|---|
| `tests/schema-sqlite.sql` | Modify | Add `reminder_sent_at TEXT NULL` inside `CREATE TABLE standup_tokens` after `used_at` |
| `db/schema.sql` | Modify | Append `ALTER TABLE standup_tokens ADD COLUMN reminder_sent_at DATETIME NULL;` |
| `db/schema-postgresql.sql` | Modify | Add `reminder_sent_at TIMESTAMP NULL` in CREATE TABLE; append ALTER TABLE IF NOT EXISTS at bottom |
| `src/StandupEmailer.php` | Modify | Add `getPendingUnremindedTokens()`, `markReminderSent()`, `sendSubmissionReminder()` |
| `templates/email/standup_reminder.php` | Create | Plain-text reminder email template |
| `cron/send_standups.php` | Modify | Add Pass 3 block after the closing `}` of the main teams loop |
| `tests/SubmissionReminderTest.php` | Create | 3 PHPUnit tests |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-29-submission-reminders` created from `main`.

---

### T-2 — Schema (AC-1)

**`tests/schema-sqlite.sql`** — inside `CREATE TABLE standup_tokens`, add after `used_at TEXT NULL,`:
```sql
reminder_sent_at TEXT NULL,
```

**`db/schema.sql`** — append after the last migration block:
```sql
-- US-29: submission reminder tracking
ALTER TABLE standup_tokens ADD COLUMN reminder_sent_at DATETIME NULL;
```

**`db/schema-postgresql.sql`** — add `reminder_sent_at TIMESTAMP NULL` in the CREATE TABLE body; append at file bottom:
```sql
-- US-29
ALTER TABLE standup_tokens ADD COLUMN IF NOT EXISTS reminder_sent_at TIMESTAMP NULL;
```

---

### T-3 — `getPendingUnremindedTokens()` (AC-2)

Add to `src/StandupEmailer.php`. Queries tokens where:
- `used_at IS NULL` (not submitted)
- `reminder_sent_at IS NULL` (not yet reminded)
- `expires_at > $now` AND `expires_at <= $now + 2h`

Joins `users` and `teams` to return email, display_name, team_name, timezone.

---

### T-4 — `markReminderSent()` (AC-3)

Add to `src/StandupEmailer.php`. Single `UPDATE standup_tokens SET reminder_sent_at = ? WHERE id = ?`.

---

### T-5 — `sendSubmissionReminder()` (AC-5)

Add to `src/StandupEmailer.php`. Pattern mirrors `sendStandupPrompt()`:
- Format `expires_at` (UTC string from DB) → team timezone → `H:i T`
- Build `$standupUrl = $config['app_url'] . '/submit.php?token=' . urlencode($token['token'])`
- Subject: `"Reminder: submit your standup for {$token['team_name']}"`
- Render `templates/email/standup_reminder.php` via `ob_start()` / `extract()` / `include`
- Call `sendMail()`

Note: `$standupUrl` uses `/submit.php` (not `/standup.php`) — confirmed from existing `sendStandupPrompt()` at line 109.

---

### T-6 — Email template (AC-4)

Create `templates/email/standup_reminder.php` — plain-text PHP template as specified in STORY.md AC-4.

---

### T-7 — Cron Pass 3 (AC-5)

Add after the closing `}` of the `foreach ($teams as $team)` loop in `cron/send_standups.php` (before the Helpers section):
```php
// ── Pass 3: Submission reminders ─────────────────────────────────────────────
$reminderTokens = getPendingUnremindedTokens($pdo, $nowUtc);
foreach ($reminderTokens as $rt) {
    try {
        sendSubmissionReminder($pdo, $config, $rt, $nowUtc);
        markReminderSent($pdo, (int) $rt['id'], $nowUtc);
    } catch (RuntimeException $e) {
        logCronError('[Reminder] Token ' . $rt['id'] . ': ' . $e->getMessage());
    }
}
```

---

### T-8 — Tests (AC-6)

Create `tests/SubmissionReminderTest.php` with 3 tests exactly as specified in TASKS.md T-8:
1. `testTokenInWindowIsReturnedForReminder` — token with expires_at = now+1h → returned
2. `testAlreadyRemindedTokenIsSkipped` — reminder_sent_at set → not returned
3. `testAlreadyUsedTokenIsSkipped` — used_at set → not returned

---

### T-9 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml --testdox
```
Target: ≥85 tests (82 prior + 3 new), all pass.

```bash
php83/php.exe vendor/phpstan/phpstan/phpstan.phar analyse src/ --level=5
```
Target: 0 errors.

---

### T-10 — Commit

```bash
git add db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
        src/StandupEmailer.php templates/email/standup_reminder.php \
        cron/send_standups.php tests/SubmissionReminderTest.php \
        .specifications/asyncstandup/us-29-submission-reminders/
git commit -m "feat(us-29): submission reminders — reminder_sent_at column, pass 3 cron, email template"
```

---

## Risk Notes

- **`$standupUrl` path** — STORY.md AC-5 mentions `/standup.php?token=` but existing `sendStandupPrompt()` uses `/submit.php?token=`. Implementation will use `/submit.php` to match the live codebase.
- **PHPStan** — `sendSubmissionReminder()` must not use `$token['display_name']` without a null-safe fallback; the column is nullable.
- **No new dependencies** — `sendMail()` is already available in `src/Mailer.php` (required by cron via existing require_once chain).
