<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamRepository.php';

class TeamDeletionHardeningTest extends TestCase
{
    private PDO $pdo;
    private int $userId;
    private int $orgId;

    protected function setUp(): void
    {
        $this->pdo    = createTestPdo();
        $this->userId = seedUser($this->pdo);
        $this->orgId  = seedOrg($this->pdo, $this->userId);
    }

    /**
     * Seed a token + submission + answer chain for a team, then return the answer ID.
     */
    private function seedSubmissionChain(int $teamId, int $userId): int
    {
        $questionStmt = $this->pdo->prepare(
            'INSERT INTO team_questions (team_id, question, position) VALUES (?, ?, 1)'
        );
        $questionStmt->execute([$teamId, 'Test question?']);
        $questionId = (int) $this->pdo->lastInsertId();

        $tokenStmt = $this->pdo->prepare(
            'INSERT INTO standup_tokens (team_id, user_id, token, send_date, sent_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $tokenStmt->execute([$teamId, $userId, bin2hex(random_bytes(16)), '2024-01-01', '2024-01-01 09:00:00', '2024-01-03 09:00:00']);
        $tokenId = (int) $this->pdo->lastInsertId();

        $subStmt = $this->pdo->prepare(
            'INSERT INTO standup_submissions (token_id, user_id, team_id, submitted_at) VALUES (?, ?, ?, ?)'
        );
        $subStmt->execute([$tokenId, $userId, $teamId, '2024-01-01 09:05:00']);
        $submissionId = (int) $this->pdo->lastInsertId();

        $ansStmt = $this->pdo->prepare(
            'INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (?, ?, ?)'
        );
        $ansStmt->execute([$submissionId, $questionId, 'Done some work.']);

        return (int) $this->pdo->lastInsertId();
    }

    public function testDeleteTeamRemovesAllChildRecords(): void
    {
        $teamId = seedTeam($this->pdo, $this->orgId, $this->userId);
        seedTeamMember($this->pdo, $teamId, $this->userId);
        $this->seedSubmissionChain($teamId, $this->userId);

        deleteTeam($this->pdo, $teamId);

        // teams row gone
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
        $this->assertSame(0, $count);

        // All child tables empty
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM team_members')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM team_questions')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM standup_tokens')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM standup_submissions')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM standup_answers')->fetchColumn());
    }

    public function testDeleteTeamDoesNotAffectOtherTeamRecipients(): void
    {
        $teamId1 = seedTeam($this->pdo, $this->orgId, $this->userId);
        $teamId2 = seedTeam($this->pdo, $this->orgId, $this->userId, 'UTC', '10:00:00');

        $insertRecipient = $this->pdo->prepare(
            'INSERT INTO team_recipients (team_id, email, display_name) VALUES (?, ?, ?)'
        );
        $insertRecipient->execute([$teamId1, 'shared@example.com', 'Shared']);
        $insertRecipient->execute([$teamId2, 'shared@example.com', 'Shared']);

        deleteTeam($this->pdo, $teamId1);

        // Team 2's recipient must survive
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM team_recipients WHERE team_id = ?');
        $stmt->execute([$teamId2]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testDeleteTeamSucceedsAtomically(): void
    {
        $teamId = seedTeam($this->pdo, $this->orgId, $this->userId);
        seedTeamMember($this->pdo, $teamId, $this->userId);

        // Verify the team exists before deletion
        $stmt = $this->pdo->prepare('SELECT status FROM teams WHERE id = ?');
        $stmt->execute([$teamId]);
        $before = $stmt->fetchColumn();
        $this->assertSame('active', $before);

        // Normal deletion must succeed atomically
        deleteTeam($this->pdo, $teamId);

        // Team row must be gone (commit happened)
        $stmt2 = $this->pdo->prepare('SELECT COUNT(*) FROM teams WHERE id = ?');
        $stmt2->execute([$teamId]);
        $this->assertSame(0, (int) $stmt2->fetchColumn());

        // No orphan team_members should remain
        $stmt3 = $this->pdo->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = ?');
        $stmt3->execute([$teamId]);
        $this->assertSame(0, (int) $stmt3->fetchColumn());
    }

    public function testSuspendTeamBlocksItFromCronAllTeams(): void
    {
        $teamId = seedTeam($this->pdo, $this->orgId, $this->userId);

        // Confirm it appears before suspension
        require_once __DIR__ . '/../src/StandupEmailer.php';
        $before = getAllTeams($this->pdo);
        $beforeIds = array_map('intval', array_column($before, 'id'));
        $this->assertContains($teamId, $beforeIds);

        // Suspend then confirm it no longer appears
        suspendTeam($this->pdo, $teamId);
        $after = getAllTeams($this->pdo);
        $afterIds = array_map('intval', array_column($after, 'id'));
        $this->assertNotContains($teamId, $afterIds);
    }
}
