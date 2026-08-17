<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamRepository.php';

class MemberAnswerHistoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
    }

    // ── Test 1: isDeveloperMember() ───────────────────────────────────────────

    public function testIsDeveloperMemberReturnsTrueForDeveloper(): void
    {
        $userId = seedUser($this->pdo, 'dev@x.com', 'Dev User');
        $orgId  = seedOrg($this->pdo, $userId);
        $teamId = seedTeam($this->pdo, $orgId, $userId);
        seedTeamMember($this->pdo, $teamId, $userId, 0, 1);

        $this->assertTrue(isDeveloperMember($this->pdo, $teamId, $userId));
    }

    public function testIsDeveloperMemberReturnsFalseForRecipientOnly(): void
    {
        $ownerId = seedUser($this->pdo, 'owner@x.com', 'Owner');
        $orgId   = seedOrg($this->pdo, $ownerId);
        $teamId  = seedTeam($this->pdo, $orgId, $ownerId);

        $recipId = seedUser($this->pdo, 'rec@x.com', 'Recipient');
        $this->pdo->prepare(
            'INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (?, ?, 0, 0, 1)'
        )->execute([$teamId, $recipId]);

        $this->assertFalse(isDeveloperMember($this->pdo, $teamId, $recipId));
    }

    // ── Test 3: canAccessResponses() — recipient-only is denied ──────────────

    public function testRecipientOnlyCannotAccessResponses(): void
    {
        $ownerId = seedUser($this->pdo, 'owner@x.com', 'Owner');
        $orgId   = seedOrg($this->pdo, $ownerId);
        $teamId  = seedTeam($this->pdo, $orgId, $ownerId);

        // Seed a recipient-only member (is_developer = 0, is_owner = 0)
        $recipId = seedUser($this->pdo, 'rec@x.com', 'Recipient');
        $this->pdo->prepare(
            'INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (?, ?, 0, 0, 1)'
        )->execute([$teamId, $recipId]);

        $this->assertFalse(
            canAccessResponses($this->pdo, $teamId, $recipId),
            'Recipient-only member must be denied access to the responses page'
        );
    }

    // ── Test 4: canSeeAllMemberResponses() — summary_to_all = 0 ─────────────

    public function testDeveloperWithoutSummaryFlagCannotSeeAll(): void
    {
        $userId = seedUser($this->pdo, 'dev@x.com', 'Dev');
        $orgId  = seedOrg($this->pdo, $userId);
        // seedTeam sets summary_to_all_developers = 0 (default)
        $teamId = seedTeam($this->pdo, $orgId, $userId);
        seedTeamMember($this->pdo, $teamId, $userId, 0, 1);

        $team = ['id' => $teamId, 'summary_to_all_developers' => 0];

        // Developer is not an owner
        $canSeeAll = canSeeAllMemberResponses(false, $team);

        $this->assertFalse($canSeeAll, 'Developer without summary_to_all_developers should not see all members');
    }

    // ── Test 5: canSeeAllMemberResponses() — summary_to_all = 1 ─────────────

    public function testDeveloperWithSummaryFlagCanSeeAll(): void
    {
        $ownerId = seedUser($this->pdo, 'owner@x.com', 'Owner');
        $orgId   = seedOrg($this->pdo, $ownerId);

        // Insert team with summary_to_all_developers = 1
        $this->pdo->prepare(
            'INSERT INTO teams (org_id, name, timezone, standup_time, summary_to_all_developers, created_by) VALUES (?, ?, ?, ?, 1, ?)'
        )->execute([$orgId, 'Open Team', 'UTC', '09:00:00', $ownerId]);
        $teamId = (int) $this->pdo->lastInsertId();

        $devId = seedUser($this->pdo, 'dev@x.com', 'Dev');
        seedTeamMember($this->pdo, $teamId, $devId, 0, 1);

        // Fetch the actual team row (as responses.php does via getTeamById)
        $stmt = $this->pdo->prepare('SELECT * FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $team = $stmt->fetch();

        // Developer is not an owner
        $canSeeAll = canSeeAllMemberResponses(false, $team);

        $this->assertTrue($canSeeAll, 'Developer with summary_to_all_developers = 1 should see all members');
    }
}
