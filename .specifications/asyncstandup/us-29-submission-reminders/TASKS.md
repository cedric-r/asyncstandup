# TASKS — US-29: Submission Reminders

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-29-submission-reminders`  
**Agent**: PHP Developer (`fa2e6dbf`)

---

## Phase 1 — Branch + schema (AC-1)

**T-1** `backend-dev` — Create branch
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout -b feature/us-29-submission-reminders
```

**T-2** `backend-dev` — Add `reminder_sent_at` to all 3 schema files

`tests/schema-sqlite.sql` — add inside `CREATE TABLE standup_tokens` after `used_at`:
```sql
reminder_sent_at TEXT NULL,
```

`db/schema.sql` — append:
```sql
-- US-29: submission reminder tracking
ALTER TABLE standup_tokens ADD COLUMN reminder_sent_at DATETIME NULL;
```

`db/schema-postgresql.sql` — add `reminder_sent_at TIMESTAMP NULL` inside `CREATE TABLE standup_tokens`; append at bottom:
```sql
-- US-29
ALTER TABLE standup_tokens ADD COLUMN IF NOT EXISTS reminder_sent_at TIMESTAMP NULL;
```

---

## Phase 2 — `src/StandupEmailer.php`: new functions (AC-2, AC-3, AC-5)

**T-3** `backend-dev` — Add `getPendingUnremindedTokens()` — full implementation as specified in STORY.md AC-2

**T-4** `backend-dev` — Add `markReminderSent()` — full implementation as specified in STORY.md AC-3

**T-5** `backend-dev` — Add `sendSubmissionReminder(PDO $pdo, array $config, array $token, DateTimeImmutable $nowUtc): void`

Pattern mirrors `sendStandupPrompt()` exactly. Key differences:
- Template: `templates/email/standup_reminder.php`
- `$expiresAt`: format `$token['expires_at']` in team timezone: `(new DateTimeImmutable($token['expires_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($token['timezone']))->format('H:i T')`
- `$standupUrl`: `$config['app_url'] . '/standup.php?token=' . $token['token']`
- Subject: `"Reminder: submit your standup for {$token['team_name']}"`
- To: `$token['email']`

---

## Phase 3 — Email template (AC-4)

**T-6** `backend-dev` — Create `templates/email/standup_reminder.php`

```php
<?php declare(strict_types=1);
/**
 * @var string $userName
 * @var string $teamName
 * @var string $standupUrl
 * @var string $expiresAt
 * @var string $teamTimezone
 */
?>
Hi <?= $userName ?>,

A quick reminder — you haven't submitted your standup for <?= $teamName ?> yet.

Your response window closes at <?= $expiresAt ?> (<?= $teamTimezone ?>).

Submit here: <?= $standupUrl ?>

If you've already submitted, please ignore this message.
```

---

## Phase 4 — Cron: Pass 3 (AC-5)

**T-7** `backend-dev` — Add Pass 3 to `cron/send_standups.php`

After the closing `}` of the main `foreach ($teams as $team)` loop (after Pass 2), add:

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

## Phase 5 — Tests (AC-6)

**T-8** `backend-dev` — Create `tests/SubmissionReminderTest.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/StandupEmailer.php';

class SubmissionReminderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        // Insert org, team, user, and a standup token fixture
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'T', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash, display_name) VALUES (1, 'dev@x.com', 'h', 'Dev')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 0, 1, 0)");
    }

    private function insertToken(string $expiresAt, ?string $usedAt, ?string $reminderSentAt): int
    {
        $this->pdo->exec("INSERT INTO standup_tokens
            (team_id, user_id, token, send_date, sent_at, expires_at, used_at, reminder_sent_at)
            VALUES (1, 1, 'tok" . uniqid() . "', '2026-08-17', '2026-08-17 09:00:00',
                    '$expiresAt',
                    " . ($usedAt     ? "'$usedAt'"     : 'NULL') . ",
                    " . ($reminderSentAt ? "'$reminderSentAt'" : 'NULL') . "
            )");
        return (int) $this->pdo->lastInsertId();
    }

    public function testTokenInWindowIsReturnedForReminder(): void
    {
        $now     = new DateTimeImmutable('2026-08-17 10:00:00', new DateTimeZone('UTC'));
        $expires = '2026-08-17 11:00:00'; // now+1h — within the 2h window
        $this->insertToken($expires, null, null);

        $results = getPendingUnremindedTokens($this->pdo, $now);
        $this->assertCount(1, $results);
    }

    public function testAlreadyRemindedTokenIsSkipped(): void
    {
        $now = new DateTimeImmutable('2026-08-17 10:00:00', new DateTimeZone('UTC'));
        $this->insertToken('2026-08-17 11:00:00', null, '2026-08-17 09:30:00');

        $results = getPendingUnremindedTokens($this->pdo, $now);
        $this->assertCount(0, $results);
    }

    public function testAlreadyUsedTokenIsSkipped(): void
    {
        $now = new DateTimeImmutable('2026-08-17 10:00:00', new DateTimeZone('UTC'));
        $this->insertToken('2026-08-17 11:00:00', '2026-08-17 09:45:00', null);

        $results = getPendingUnremindedTokens($this->pdo, $now);
        $this->assertCount(0, $results);
    }
}
```

**T-9** `backend-dev` — Run full test suite; target ≥85 tests (82 prior + 3 new)

---

## Phase 6 — Commit and signal

**T-10** `backend-dev` — Commit
```bash
git add db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
        src/StandupEmailer.php templates/email/standup_reminder.php \
        cron/send_standups.php tests/SubmissionReminderTest.php \
        .specifications/asyncstandup/us-29-submission-reminders/
git commit -m "feat(us-29): submission reminders — reminder_sent_at column, pass 3 cron, email template"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (schema) | T-2 |
| AC-2 (`getPendingUnremindedTokens`) | T-3 |
| AC-3 (`markReminderSent`) | T-4 |
| AC-4 (email template) | T-6 |
| AC-5 (`sendSubmissionReminder` + cron pass 3) | T-5, T-7 |
| AC-6 (3 tests) | T-8, T-9 |

**Estimate**: ~6h total
