<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/DashboardRepository.php';

/**
 * Integration tests for getResponseData() in src/DashboardRepository.php.
 *
 * Uses in-memory SQLite via createTestPdo(). Seed helpers from bootstrap.php.
 */
class DashboardRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $userId1;
    private int $userId2;
    private int $teamId;
    private int $qId1;
    private int $qId2;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();

        $this->userId1 = seedUser($this->pdo, 'dev1@resp.test', 'Dev One');
        $this->userId2 = seedUser($this->pdo, 'dev2@resp.test', 'Dev Two');
        $orgId         = seedOrg($this->pdo, $this->userId1);
        $this->teamId  = seedTeam($this->pdo, $orgId, $this->userId1, 'UTC', '09:00:00');
        seedTeamMember($this->pdo, $this->teamId, $this->userId1, 1, 1);
        seedTeamMember($this->pdo, $this->teamId, $this->userId2, 0, 1);

        // Two questions.
        $this->pdo->exec("INSERT INTO team_questions (team_id, question, position) VALUES ({$this->teamId}, 'Q1', 1)");
        $this->qId1 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO team_questions (team_id, question, position) VALUES ({$this->teamId}, 'Q2', 2)");
        $this->qId2 = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        // In-memory DB destroyed with PDO object.
    }

    // -------------------------------------------------------------------------
    // Helper: seed a standup_tokens row and return its id.
    // -------------------------------------------------------------------------

    private function seedToken(int $userId, string $sendDate, bool $withSubmission = false): int
    {
        $this->pdo->prepare('
            INSERT INTO standup_tokens (team_id, user_id, token, send_date, sent_at, expires_at)
            VALUES (?, ?, ?, ?, datetime("now"), datetime("now", "+48 hours"))
        ')->execute([$this->teamId, $userId, 'tok-' . $userId . '-' . $sendDate, $sendDate]);
        $tokenId = (int) $this->pdo->lastInsertId();

        if ($withSubmission) {
            $this->pdo->prepare('
                INSERT INTO standup_submissions (token_id, user_id, team_id) VALUES (?, ?, ?)
            ')->execute([$tokenId, $userId, $this->teamId]);
            $subId = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare('INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (?, ?, ?)')
                ->execute([$subId, $this->qId1, 'Answer A']);
            $this->pdo->prepare('INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (?, ?, ?)')
                ->execute([$subId, $this->qId2, 'Answer B']);
        }

        return $tokenId;
    }

    // =========================================================================
    // getResponseData() — 6 cases
    // =========================================================================

    public function testGetResponseDataDefaultModeReturnsTokensInRange(): void
    {
        // Tokens on 2024-01-15 (in range) and 2023-12-01 (out of range).
        $this->seedToken($this->userId1, '2024-01-15');
        $this->seedToken($this->userId1, '2023-12-01');

        $rows = getResponseData($this->pdo, $this->teamId, null, null, '2024-01-01', '2024-01-31');

        $dates = array_unique(array_column($rows, 'send_date'));
        self::assertContains('2024-01-15', $dates, '2024-01-15 must be in results');
        self::assertNotContains('2023-12-01', $dates, '2023-12-01 is outside range and must be excluded');
    }

    public function testGetResponseDataByDateReturnsSingleDate(): void
    {
        $this->seedToken($this->userId1, '2024-01-15');
        $this->seedToken($this->userId1, '2024-01-16');

        $rows = getResponseData($this->pdo, $this->teamId, '2024-01-15', null, '2024-01-01', '2024-01-31');

        $dates = array_unique(array_column($rows, 'send_date'));
        self::assertCount(1, $dates, 'Only one date should be returned when date filter is set');
        self::assertSame('2024-01-15', $dates[0]);
    }

    public function testGetResponseDataByMemberReturnsSingleMember(): void
    {
        $this->seedToken($this->userId1, '2024-01-15');
        $this->seedToken($this->userId2, '2024-01-15');

        $rows = getResponseData($this->pdo, $this->teamId, null, $this->userId1, '2024-01-01', '2024-01-31');

        $userIds = array_unique(array_map('intval', array_column($rows, 'user_id')));
        self::assertContains($this->userId1, $userIds, 'Dev One must appear in results');
        self::assertNotContains($this->userId2, $userIds, 'Dev Two must be excluded when member filter set');
    }

    public function testGetResponseDataSingleModeBothFilters(): void
    {
        $this->seedToken($this->userId1, '2024-01-15');
        $this->seedToken($this->userId2, '2024-01-15');
        $this->seedToken($this->userId1, '2024-01-16');

        $rows = getResponseData($this->pdo, $this->teamId, '2024-01-15', $this->userId1, '2024-01-01', '2024-01-31');

        $userIds = array_unique(array_map('intval', array_column($rows, 'user_id')));
        $dates   = array_unique(array_column($rows, 'send_date'));

        self::assertSame([$this->userId1], $userIds, 'Only Dev One must appear');
        self::assertSame(['2024-01-15'], $dates, 'Only 2024-01-15 must appear');
    }

    public function testGetResponseDataSentNotSubmittedSubmissionIdIsNull(): void
    {
        // Token sent, no submission → submission_id LEFT JOIN should be NULL.
        $this->seedToken($this->userId1, '2024-01-15', false);

        $rows = getResponseData($this->pdo, $this->teamId, '2024-01-15', $this->userId1, '2024-01-01', '2024-01-31');

        self::assertNotEmpty($rows, 'Token row must appear even without submission');
        self::assertNull($rows[0]['submission_id'], 'submission_id must be NULL when not submitted');
    }

    public function testGetResponseDataWithSubmissionIncludesAnswerRows(): void
    {
        // Token with submission + 2 answers → 2 rows returned (one per question).
        $this->seedToken($this->userId1, '2024-01-15', true);

        $rows = getResponseData($this->pdo, $this->teamId, '2024-01-15', $this->userId1, '2024-01-01', '2024-01-31');

        // Cast to int — SQLite may return integers or strings depending on PDO settings.
        $questionIds = array_map('intval', array_column($rows, 'question_id'));
        self::assertContains($this->qId1, $questionIds, 'Q1 must appear in answer rows');
        self::assertContains($this->qId2, $questionIds, 'Q2 must appear in answer rows');

        // Verify answer content.
        $answers = array_column($rows, 'answer');
        self::assertContains('Answer A', $answers);
        self::assertContains('Answer B', $answers);
    }
}
