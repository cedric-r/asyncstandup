<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TeamsFallbackTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'OrgA'), (2, 'OrgB')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by) VALUES (1, 1, 'T1', 'UTC', '09:00', 1)");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by) VALUES (2, 2, 'T2', 'UTC', '09:00', 1)");
    }

    public function testRecordTeamsErrorPersistsMessage(): void
    {
        recordTeamsError($this->pdo, 1, 'webhook failed');

        $row = $this->pdo->query('SELECT teams_last_error, teams_last_error_at FROM teams WHERE id = 1')->fetch();
        $this->assertEquals('webhook failed', $row['teams_last_error']);
        $this->assertNotNull($row['teams_last_error_at']);
    }

    public function testClearTeamsErrorRemovesError(): void
    {
        recordTeamsError($this->pdo, 1, 'err');
        clearTeamsError($this->pdo, 1);

        $row = $this->pdo->query('SELECT teams_last_error, teams_last_error_at FROM teams WHERE id = 1')->fetch();
        $this->assertNull($row['teams_last_error']);
        $this->assertNull($row['teams_last_error_at']);
    }

    public function testGetTeamsAdminOverviewReturnsAllTeams(): void
    {
        $rows = getTeamsAdminOverview($this->pdo);

        $this->assertCount(2, $rows);
        $orgNames = array_column($rows, 'org_name');
        $this->assertContains('OrgA', $orgNames);
        $this->assertContains('OrgB', $orgNames);
    }
}
