<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamRepository.php';
require_once __DIR__ . '/../src/StandupEmailer.php';

class TeamSuspensionTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private int $orgId;
    private int $teamId;

    protected function setUp(): void
    {
        $this->pdo    = createTestPdo();
        $this->userId = seedUser($this->pdo);
        $this->orgId  = seedOrg($this->pdo, $this->userId);
        $this->teamId = seedTeam($this->pdo, $this->orgId, $this->userId);
    }

    public function testSuspendTeamSetsStatusSuspended(): void
    {
        suspendTeam($this->pdo, $this->teamId);

        $row = $this->pdo->prepare('SELECT status FROM teams WHERE id = ?');
        $row->execute([$this->teamId]);
        $status = $row->fetchColumn();

        $this->assertSame('suspended', $status);
    }

    public function testReactivateTeamSetsStatusActive(): void
    {
        suspendTeam($this->pdo, $this->teamId);
        reactivateTeam($this->pdo, $this->teamId);

        $row = $this->pdo->prepare('SELECT status FROM teams WHERE id = ?');
        $row->execute([$this->teamId]);
        $status = $row->fetchColumn();

        $this->assertSame('active', $status);
    }

    public function testGetAllTeamsExcludesSuspendedTeams(): void
    {
        suspendTeam($this->pdo, $this->teamId);

        $teams = getAllTeams($this->pdo);

        $ids = array_column($teams, 'id');
        $this->assertNotContains((string) $this->teamId, $ids);
        $this->assertNotContains($this->teamId, $ids);
    }

    public function testGetAllTeamsIncludesActiveTeams(): void
    {
        // Default status is 'active'; no suspension applied.
        $teams = getAllTeams($this->pdo);

        $ids = array_map('intval', array_column($teams, 'id'));
        $this->assertContains($this->teamId, $ids);
    }
}
