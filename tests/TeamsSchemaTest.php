<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamRepository.php';

class TeamsSchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'o@x.com', 'h')");
        $this->pdo->exec(
            "INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by)
             VALUES (1, 1, 'T', 'UTC', '09:00', 1)"
        );
    }

    public function testDefaultNotificationChannelIsEmail(): void
    {
        $row = $this->pdo->query("SELECT notification_channel FROM teams WHERE id = 1")->fetch();
        $this->assertSame('email', $row['notification_channel']);
    }

    public function testTeamStoresWebhookUrl(): void
    {
        $result = updateTeam(
            $this->pdo, 1, 'T', 'UTC', '09:00', 0, 'daily', null,
            'teams-summary', 'https://outlook.webhook.office.com/xxx', '#standup'
        );

        $this->assertNotFalse($result);

        $row = $this->pdo->query(
            "SELECT notification_channel, teams_webhook_url FROM teams WHERE id = 1"
        )->fetch();
        $this->assertSame('teams-summary', $row['notification_channel']);
        $this->assertSame('https://outlook.webhook.office.com/xxx', $row['teams_webhook_url']);
    }

    public function testUpdateTeamRejectsInvalidChannel(): void
    {
        $result = updateTeam($this->pdo, 1, 'T', 'UTC', '09:00', 0, 'daily', null, 'slack');

        $this->assertFalse($result);

        $row = $this->pdo->query("SELECT notification_channel FROM teams WHERE id = 1")->fetch();
        $this->assertSame('email', $row['notification_channel']); // unchanged
    }
}
