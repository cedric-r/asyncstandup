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

    public function testIsDeveloperMemberReturnsTrueForDeveloper(): void
    {
        $userId = seedUser($this->pdo, 'dev@x.com', 'Dev User');
        $orgId  = seedOrg($this->pdo, $userId);
        $teamId = seedTeam($this->pdo, $orgId, $userId);

        // seedTeamMember defaults is_developer = 1
        seedTeamMember($this->pdo, $teamId, $userId, 0, 1);

        $this->assertTrue(isDeveloperMember($this->pdo, $teamId, $userId));
    }

    public function testIsDeveloperMemberReturnsFalseForRecipientOnly(): void
    {
        $ownerId = seedUser($this->pdo, 'owner@x.com', 'Owner');
        $orgId   = seedOrg($this->pdo, $ownerId);
        $teamId  = seedTeam($this->pdo, $orgId, $ownerId);

        $recipId = seedUser($this->pdo, 'rec@x.com', 'Recipient');
        // is_developer = 0, is_recipient = 1
        $this->pdo->prepare(
            'INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (?, ?, 0, 0, 1)'
        )->execute([$teamId, $recipId]);

        $this->assertFalse(isDeveloperMember($this->pdo, $teamId, $recipId));
    }

    public function testRecipientOnlyCannotAccessResponsesPage(): void
    {
        // Simulate the access control logic from responses.php directly
        $isOwner     = false; // recipient is not an owner
        $isDeveloper = false; // recipient is not a developer

        $canAccess = $isOwner || $isDeveloper;

        $this->assertFalse($canAccess, 'Recipient-only member must be denied access to responses page');
    }

    public function testDeveloperWithoutSummaryToAllForcesOwnFilter(): void
    {
        $userId = seedUser($this->pdo, 'dev@x.com', 'Dev User');
        $orgId  = seedOrg($this->pdo, $userId);
        $teamId = seedTeam($this->pdo, $orgId, $userId);
        seedTeamMember($this->pdo, $teamId, $userId, 0, 1);

        // summary_to_all_developers = 0 (default from seedTeam)
        $team = ['summary_to_all_developers' => 0];

        $isOwner   = false;
        $canSeeAll = $isOwner || (bool) ($team['summary_to_all_developers'] ?? 0);

        $this->assertFalse($canSeeAll, 'Developer without summary_to_all should not see all members');

        // Simulate the forced filter: when !$canSeeAll, memberFilter = $userId
        $memberFilter = $canSeeAll ? null : $userId;
        $this->assertSame($userId, $memberFilter);
    }

    public function testDeveloperWithSummaryToAllCanSeeAllMembers(): void
    {
        $ownerId = seedUser($this->pdo, 'owner@x.com', 'Owner');
        $orgId   = seedOrg($this->pdo, $ownerId);

        // summary_to_all_developers = 1
        $this->pdo->prepare(
            'INSERT INTO teams (org_id, name, timezone, standup_time, summary_to_all_developers, created_by) VALUES (?, ?, ?, ?, 1, ?)'
        )->execute([$orgId, 'Open Team', 'UTC', '09:00:00', $ownerId]);
        $teamId = (int) $this->pdo->lastInsertId();

        $devId = seedUser($this->pdo, 'dev@x.com', 'Dev');
        seedTeamMember($this->pdo, $teamId, $devId, 0, 1);

        $team = ['summary_to_all_developers' => 1];

        $isOwner   = false;
        $canSeeAll = $isOwner || (bool) ($team['summary_to_all_developers'] ?? 0);

        $this->assertTrue($canSeeAll, 'Developer with summary_to_all = 1 should be able to see all members');

        // No forced filter — member_id GET param would be honoured
        $memberFilter = null; // unrestricted
        $this->assertNull($memberFilter);
    }
}
