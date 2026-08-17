# US-29: Submission Reminders

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-29-submission-reminders`

---

## Story

**As a** developer team member  
**I want** to receive a reminder email when I haven't submitted my standup and the deadline is approaching  
**So that** I don't miss the submission window without realising it

---

## Acceptance Criteria

### AC-1 — Schema: `reminder_sent_at` column on `standup_tokens`

Add `reminder_sent_at DATETIME NULL` (MySQL) / `TIMESTAMP NULL` (PostgreSQL) / `TEXT NULL` (SQLite) to `standup_tokens`.

- `db/schema.sql` — append:
  ```sql
  -- US-29: submission reminder tracking
  ALTER TABLE standup_tokens ADD COLUMN reminder_sent_at DATETIME NULL;
  ```
- `db/schema-postgresql.sql` — add column in `CREATE TABLE` + `ALTER TABLE IF NOT EXISTS` at bottom
- `tests/schema-sqlite.sql` — add `reminder_sent_at TEXT NULL` in `CREATE TABLE standup_tokens`

---

### AC-2 — `getPendingUnremindedTokens()` in `src/StandupEmailer.php`

```php
/**
 * Return tokens that should receive a reminder:
 *   - not yet submitted (used_at IS NULL)
 *   - not yet reminded (reminder_sent_at IS NULL)
 *   - expires within the next 2 hours from $nowUtc
 *
 * @return array{id: int, token: string, user_id: int, team_id: int,
 *               send_date: string, expires_at: string,
 *               email: string, display_name: string|null,
 *               team_name: string, timezone: string}[]
 */
function getPendingUnremindedTokens(PDO $pdo, DateTimeImmutable $nowUtc): array
{
    $nowStr      = $nowUtc->format('Y-m-d H:i:s');
    $windowStr   = $nowUtc->modify('+2 hours')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        SELECT st.id, st.token, st.user_id, st.team_id, st.send_date, st.expires_at,
               u.email, u.display_name, t.name AS team_name, t.timezone
        FROM standup_tokens st
        JOIN users u  ON u.id  = st.user_id
        JOIN teams t  ON t.id  = st.team_id
        WHERE st.used_at          IS NULL
          AND st.reminder_sent_at IS NULL
          AND st.expires_at > ?
          AND st.expires_at <= ?
    ');
    $stmt->execute([$nowStr, $windowStr]);
    return $stmt->fetchAll();
}
```

---

### AC-3 — `markReminderSent()` in `src/StandupEmailer.php`

```php
function markReminderSent(PDO $pdo, int $tokenId, DateTimeImmutable $nowUtc): void
{
    $pdo->prepare('UPDATE standup_tokens SET reminder_sent_at = ? WHERE id = ?')
        ->execute([$nowUtc->format('Y-m-d H:i:s'), $tokenId]);
}
```

---

### AC-4 — Reminder email template: `templates/email/standup_reminder.php`

Plain-text template. Variables injected via `extract()`:
- `$userName` — developer's display name
- `$teamName` — team name
- `$standupUrl` — submission link (same token URL as the original prompt)
- `$expiresAt` — formatted local time when token expires
- `$teamTimezone` — team timezone string

Content:
```
Hi {userName},

A quick reminder — you haven't submitted your standup for {teamName} yet.

Your response window closes at {expiresAt} ({teamTimezone}).

Submit here: {standupUrl}

If you've already submitted, please ignore this message.
```

---

### AC-5 — Pass 3 (reminder pass) added to `cron/send_standups.php`

After the existing Pass 2 (summary) `foreach` loop, add a separate pass that iterates over all pending unreminded tokens (not team-by-team — the query already returns the full cross-team list):

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

`sendSubmissionReminder(PDO $pdo, array $config, array $token, DateTimeImmutable $nowUtc): void` — new function in `src/StandupEmailer.php`. Formats `$expiresAt` in the team's timezone, builds `$standupUrl`, calls `sendMail()` (same pattern as `sendStandupPrompt()`).

---

### AC-6 — PHPUnit tests: 3 new tests

New test class `tests/SubmissionReminderTest.php`:

| Test | What it verifies |
|---|---|
| `testTokenInWindowIsReturnedForReminder` | Token with `used_at=NULL`, `reminder_sent_at=NULL`, `expires_at` = now+1h → returned by `getPendingUnremindedTokens()` |
| `testAlreadyRemindedTokenIsSkipped` | Token with `reminder_sent_at` set → not returned |
| `testAlreadyUsedTokenIsSkipped` | Token with `used_at` set → not returned |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append `reminder_sent_at` migration |
| `db/schema-postgresql.sql` | Add column + migration |
| `tests/schema-sqlite.sql` | Add column inline |
| `src/StandupEmailer.php` | Add `getPendingUnremindedTokens()`, `markReminderSent()`, `sendSubmissionReminder()` |
| `templates/email/standup_reminder.php` (new) | Reminder email template |
| `cron/send_standups.php` | Add Pass 3 after Pass 2 |
| `tests/SubmissionReminderTest.php` (new) | 3 PHPUnit tests |
