<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/StandupEmailer.php';

class SubmissionReminderTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();

        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'T', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash, display_name) VALUES (1, 'dev@x.com', 'h', 'Dev')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 0, 1, 0)");
    }

    private function insertToken(string $expiresAt, ?string $usedAt, ?string $reminderSentAt): int
    {
        $token          = 'tok' . uniqid();
        $usedAtSql      = $usedAt      !== null ? "'$usedAt'"      : 'NULL';
        $reminderSql    = $reminderSentAt !== null ? "'$reminderSentAt'" : 'NULL';

        $this->pdo->exec("
            INSERT INTO standup_tokens
                (team_id, user_id, token, send_date, sent_at, expires_at, used_at, reminder_sent_at)
            VALUES
                (1, 1, '$token', '2026-08-17', '2026-08-17 09:00:00',
                 '$expiresAt', $usedAtSql, $reminderSql)
        ");

        return (int) $this->pdo->lastInsertId();
    }

    public function testTokenInWindowIsReturnedForReminder(): void
    {
        $now = new DateTimeImmutable('2026-08-17 10:00:00', new DateTimeZone('UTC'));
        // expires_at = now+1h — within the 2h window
        $this->insertToken('2026-08-17 11:00:00', null, null);

        $results = getPendingUnremindedTokens($this->pdo, $now);

        $this->assertCount(1, $results);
    }

    public function testAlreadyRemindedTokenIsSkipped(): void
    {
        $now = new DateTimeImmutable('2026-08-17 10:00:00', new DateTimeZone('UTC'));
        // reminder_sent_at is set — should be excluded
        $this->insertToken('2026-08-17 11:00:00', null, '2026-08-17 09:30:00');

        $results = getPendingUnremindedTokens($this->pdo, $now);

        $this->assertCount(0, $results);
    }

    public function testAlreadyUsedTokenIsSkipped(): void
    {
        $now = new DateTimeImmutable('2026-08-17 10:00:00', new DateTimeZone('UTC'));
        // used_at is set — submission already done, should be excluded
        $this->insertToken('2026-08-17 11:00:00', '2026-08-17 09:45:00', null);

        $results = getPendingUnremindedTokens($this->pdo, $now);

        $this->assertCount(0, $results);
    }
}
