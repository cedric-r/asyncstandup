<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Auth.php sets ini_set('display_errors','0') and installs set_exception_handler()
// at file scope. Save and restore PHPUnit's handler so test failures propagate correctly.
$__prevExceptionHandler = set_exception_handler(null);
require_once __DIR__ . '/../src/Auth.php';
set_exception_handler($__prevExceptionHandler);
unset($__prevExceptionHandler);

/**
 * Repository integration tests using in-memory SQLite.
 *
 * Covers:
 *   - saveSubmission()         (happy path + rollback on FK violation)
 *   - assembleSummaryData()    (submitter + non-submitter)
 *   - acceptInvitationForUser() (valid, already accepted, expired)
 *   - deleteOrg()              (full cascade; no FK violation)
 */
class RepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
    }

    // =========================================================================
    // saveSubmission()
    // =========================================================================

    public function testSaveSubmissionHappyPath(): void
    {
        $userId = seedUser($this->pdo);
        $orgId  = seedOrg($this->pdo, $userId);
        $teamId = seedTeam($this->pdo, $orgId, $userId);
        seedTeamMember($this->pdo, $teamId, $userId);

        // Seed 2 questions.
        $this->pdo->exec("INSERT INTO team_questions (team_id, question, position) VALUES ({$teamId}, 'Q1', 1)");
        $q1 = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO team_questions (team_id, question, position) VALUES ({$teamId}, 'Q2', 2)");
        $q2 = (int) $this->pdo->lastInsertId();

        // Seed unused token.
        $this->pdo->prepare('
            INSERT INTO standup_tokens (team_id, user_id, token, send_date, sent_at, expires_at)
            VALUES (?, ?, ?, ?, datetime("now"), datetime("now", "+48 hours"))
        ')->execute([$teamId, $userId, 'test-token-happy', '2024-01-15']);
        $tokenId = (int) $this->pdo->lastInsertId();

        saveSubmission($this->pdo, $tokenId, $userId, $teamId, [
            $q1 => 'Answer to Q1',
            $q2 => 'Answer to Q2',
        ]);

        // 1 standup_submissions row.
        $subCount = (int) $this->pdo->query('SELECT COUNT(*) FROM standup_submissions')->fetchColumn();
        self::assertSame(1, $subCount);

        // 2 standup_answers rows.
        $ansCount = (int) $this->pdo->query('SELECT COUNT(*) FROM standup_answers')->fetchColumn();
        self::assertSame(2, $ansCount);

        // Token used_at set.
        $row = $this->pdo->query("SELECT used_at FROM standup_tokens WHERE id = {$tokenId}")->fetch();
        self::assertNotNull($row['used_at'], 'used_at must be set after submission');
    }

    public function testSaveSubmissionRollsBackOnFkViolation(): void
    {
        $userId = seedUser($this->pdo);
        $orgId  = seedOrg($this->pdo, $userId);
        $teamId = seedTeam($this->pdo, $orgId, $userId);
        seedTeamMember($this->pdo, $teamId, $userId);

        $this->pdo->prepare('
            INSERT INTO standup_tokens (team_id, user_id, token, send_date, sent_at, expires_at)
            VALUES (?, ?, ?, ?, datetime("now"), datetime("now", "+48 hours"))
        ')->execute([$teamId, $userId, 'test-token-rollback', '2024-01-15']);
        $tokenId = (int) $this->pdo->lastInsertId();

        // Pass a non-existent question_id → FK violation in standup_answers → rollback.
        $threw = false;

        try {
            saveSubmission($this->pdo, $tokenId, $userId, $teamId, [99999 => 'Orphan answer']);
        } catch (PDOException $e) {
            $threw = true;
        }

        self::assertTrue($threw, 'PDOException must be thrown on FK violation');

        // No orphan submission row.
        $subCount = (int) $this->pdo->query('SELECT COUNT(*) FROM standup_submissions')->fetchColumn();
        self::assertSame(0, $subCount, 'Transaction must roll back — no standup_submissions rows');
    }

    // =========================================================================
    // assembleSummaryData()
    // =========================================================================

    public function testAssembleSummaryDataSubmitterAndNonSubmitter(): void
    {
        $userId1 = seedUser($this->pdo, 'dev1@example.com', 'Dev One');
        $userId2 = seedUser($this->pdo, 'dev2@example.com', 'Dev Two');
        $orgId   = seedOrg($this->pdo, $userId1);
        $teamId  = seedTeam($this->pdo, $orgId, $userId1);
        seedTeamMember($this->pdo, $teamId, $userId1, 0, 1);
        seedTeamMember($this->pdo, $teamId, $userId2, 0, 1);

        // 1 question.
        $this->pdo->exec("INSERT INTO team_questions (team_id, question, position) VALUES ({$teamId}, 'What did you do?', 1)");
        $qId = (int) $this->pdo->lastInsertId();

        $sendDate = '2024-01-15';

        // Token + submission for dev1 only.
        $this->pdo->prepare('
            INSERT INTO standup_tokens (team_id, user_id, token, send_date, sent_at, expires_at, used_at)
            VALUES (?, ?, ?, ?, datetime("now"), datetime("now", "+48 hours"), datetime("now"))
        ')->execute([$teamId, $userId1, 'tok1', $sendDate]);
        $tokenId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO standup_submissions (token_id, user_id, team_id) VALUES (?, ?, ?)')
            ->execute([$tokenId, $userId1, $teamId]);
        $subId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (?, ?, ?)')
            ->execute([$subId, $qId, 'Worked on feature X']);

        $data = assembleSummaryData($this->pdo, $teamId, $sendDate);

        // assembleSummaryData returns ['developers', 'questions', 'answerMap'].
        // submitterData / nonSubmitters are assembled in sendSummaryEmail().

        // developers: both dev1 and dev2 must appear.
        $devNames = array_column($data['developers'] ?? [], 'display_name');
        self::assertContains('Dev One', $devNames, 'Dev One must be in developers list');
        self::assertContains('Dev Two', $devNames, 'Dev Two must be in developers list');

        // answerMap: dev1's answer exists; dev2 has no entry (non-submitter).
        $answerMap = $data['answerMap'] ?? [];
        self::assertArrayHasKey($userId1, $answerMap, 'Dev One must have answers in answerMap');
        self::assertSame('Worked on feature X', $answerMap[$userId1][$qId] ?? null);

        // Dev Two has no submission — must not appear in answerMap.
        self::assertArrayNotHasKey($userId2, $answerMap, 'Dev Two must not have answers (non-submitter)');
    }

    // =========================================================================
    // acceptInvitationForUser()
    // =========================================================================

    private function seedInvitation(PDO $pdo, int $teamId, int $invitedBy, string $token, string $createdAt = '', ?string $acceptedAt = null): void
    {
        if ($createdAt === '') {
            $createdAt = date('Y-m-d H:i:s', time());
        }

        $pdo->prepare('
            INSERT INTO invitations (team_id, invited_email, token, invited_by, intended_roles, created_at, accepted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$teamId, 'invitee@test.com', $token, $invitedBy, 'developer', $createdAt, $acceptedAt]);
    }

    public function testAcceptInvitationForUserValidToken(): void
    {
        $ownerId   = seedUser($this->pdo, 'owner@test.com', 'Owner');
        $orgId     = seedOrg($this->pdo, $ownerId);
        $teamId    = seedTeam($this->pdo, $orgId, $ownerId);
        seedTeamMember($this->pdo, $teamId, $ownerId, 1, 1);

        $inviteeId = seedUser($this->pdo, 'invitee@test.com', 'Invitee');
        $this->seedInvitation($this->pdo, $teamId, $ownerId, 'valid-tok-001');

        $result = acceptInvitationForUser($this->pdo, 'valid-tok-001', $inviteeId);

        self::assertTrue($result);

        $row = $this->pdo->query("SELECT * FROM team_members WHERE user_id = {$inviteeId}")->fetch();
        self::assertNotFalse($row, 'team_members row must exist');
        self::assertSame(1, (int) $row['is_developer']);

        $inv = $this->pdo->query("SELECT accepted_at FROM invitations WHERE token = 'valid-tok-001'")->fetch();
        self::assertNotNull($inv['accepted_at']);
    }

    public function testAcceptInvitationForUserAlreadyAccepted(): void
    {
        $ownerId = seedUser($this->pdo, 'owner2@test.com', 'Owner2');
        $orgId   = seedOrg($this->pdo, $ownerId);
        $teamId  = seedTeam($this->pdo, $orgId, $ownerId);
        seedTeamMember($this->pdo, $teamId, $ownerId, 1, 1);

        $inviteeId = seedUser($this->pdo, 'invitee2@test.com', 'Invitee2');
        $this->seedInvitation($this->pdo, $teamId, $ownerId, 'accepted-tok-002', '', '2024-01-14 09:00:00');

        $result = acceptInvitationForUser($this->pdo, 'accepted-tok-002', $inviteeId);

        self::assertFalse($result);

        $row = $this->pdo->query("SELECT 1 FROM team_members WHERE user_id = {$inviteeId}")->fetch();
        self::assertFalse($row, 'No team_members row should be inserted');
    }

    public function testAcceptInvitationForUserExpiredToken(): void
    {
        $ownerId = seedUser($this->pdo, 'owner3@test.com', 'Owner3');
        $orgId   = seedOrg($this->pdo, $ownerId);
        $teamId  = seedTeam($this->pdo, $orgId, $ownerId);
        seedTeamMember($this->pdo, $teamId, $ownerId, 1, 1);

        $inviteeId  = seedUser($this->pdo, 'invitee3@test.com', 'Invitee3');
        $createdAt  = '2024-01-01 00:00:00'; // 8 days ago relative to $now below
        $this->seedInvitation($this->pdo, $teamId, $ownerId, 'expired-tok-003', $createdAt);

        // Inject $now = created_at + 8 days → past the 7-day expiry.
        $injectedNow = new DateTimeImmutable('2024-01-09 00:00:01', new DateTimeZone('UTC'));

        $result = acceptInvitationForUser($this->pdo, 'expired-tok-003', $inviteeId, $injectedNow);

        self::assertFalse($result);

        $row = $this->pdo->query("SELECT 1 FROM team_members WHERE user_id = {$inviteeId}")->fetch();
        self::assertFalse($row, 'No team_members row should be inserted for expired invitation');
    }

    // =========================================================================
    // deleteOrg()
    // =========================================================================

    public function testDeleteOrgFullCascadeNoFkViolation(): void
    {
        // Seed: org → team → member → question → token → submission → answer
        //       → summary_sent → recipient → invitation → org_member.
        $userId = seedUser($this->pdo);
        $orgId  = seedOrg($this->pdo, $userId);
        $teamId = seedTeam($this->pdo, $orgId, $userId);
        seedTeamMember($this->pdo, $teamId, $userId, 1, 1);

        $this->pdo->exec("INSERT INTO team_questions (team_id, question, position) VALUES ({$teamId}, 'Q?', 1)");
        $qId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('
            INSERT INTO standup_tokens (team_id, user_id, token, send_date, sent_at, expires_at, used_at)
            VALUES (?, ?, ?, ?, datetime("now"), datetime("now", "+48 hours"), datetime("now"))
        ')->execute([$teamId, $userId, 'cascade-token', '2024-01-15']);
        $tokenId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO standup_submissions (token_id, user_id, team_id) VALUES (?, ?, ?)')
            ->execute([$tokenId, $userId, $teamId]);
        $subId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (?, ?, ?)')
            ->execute([$subId, $qId, 'answer']);

        $this->pdo->prepare('INSERT INTO summary_sent (team_id, send_date, sent_at) VALUES (?, ?, datetime("now"))')
            ->execute([$teamId, '2024-01-15']);

        $this->pdo->prepare('INSERT INTO team_recipients (team_id, email) VALUES (?, ?)')->execute([$teamId, 'r@r.com']);

        $invUserId = seedUser($this->pdo, 'inv@r.com', 'Invitee');
        $this->pdo->prepare('
            INSERT INTO invitations (team_id, invited_email, token, invited_by, intended_roles, created_at)
            VALUES (?, ?, ?, ?, ?, datetime("now"))
        ')->execute([$teamId, 'inv@r.com', 'cascade-inv-token', $userId, 'developer']);

        // deleteOrg must not throw — no FK violation.
        $threw = false;

        try {
            deleteOrg($this->pdo, $orgId);
        } catch (PDOException $e) {
            $threw = true;
        }

        self::assertFalse($threw, 'deleteOrg must not throw a FK violation: ' . ($threw ? 'threw' : 'ok'));

        // Verify all tables are empty.
        foreach (['standup_answers', 'standup_submissions', 'standup_tokens', 'summary_sent',
                  'team_recipients', 'team_questions', 'invitations', 'team_members', 'teams',
                  'org_members', 'organisations'] as $table) {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            self::assertSame(0, $count, "{$table} must be empty after deleteOrg");
        }
    }

    // =========================================================================
    // changePassword()
    // =========================================================================

    public function testChangePasswordCorrectPasswordReturnsTrue(): void
    {
        $userId = seedUser($this->pdo, 'chpw@test.com', 'ChPw User');

        $result = changePassword($this->pdo, $userId, 'password', 'newpassword123');

        self::assertTrue($result);

        // Verify the stored hash reflects the new password.
        $row = $this->pdo->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetch();
        self::assertTrue(password_verify('newpassword123', $row['password_hash']));
    }

    public function testChangePasswordWrongPasswordReturnsFalse(): void
    {
        $userId = seedUser($this->pdo, 'chpw2@test.com', 'ChPw User2');

        $result = changePassword($this->pdo, $userId, 'wrongpassword', 'newpassword123');

        self::assertFalse($result);

        // Original password must still work.
        $row = $this->pdo->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetch();
        self::assertTrue(password_verify('password', $row['password_hash']), 'Original hash must be unchanged');
    }

    // =========================================================================
    // createPasswordResetToken() / findValidResetToken() / applyPasswordReset()
    // =========================================================================

    private function seedResetToken(PDO $pdo, int $userId, string $token, string $expiresAt, ?string $usedAt = null): void
    {
        $pdo->prepare('
            INSERT INTO password_resets (user_id, token, expires_at, used_at)
            VALUES (?, ?, ?, ?)
        ')->execute([$userId, $token, $expiresAt, $usedAt]);
    }

    public function testFindValidResetTokenWhenExistsReturnsRow(): void
    {
        $userId = seedUser($this->pdo, 'reset1@test.com', 'Reset1');
        $this->seedResetToken($this->pdo, $userId, 'find-tok-001', '2099-12-31 23:59:59');

        $row = findValidResetToken($this->pdo, 'find-tok-001');

        self::assertNotNull($row);
        self::assertSame('find-tok-001', $row['token']);
    }

    public function testFindValidResetTokenWhenNotFoundReturnsNull(): void
    {
        $result = findValidResetToken($this->pdo, 'nonexistent-token');

        self::assertNull($result);
    }

    public function testApplyPasswordResetHappyPath(): void
    {
        $userId = seedUser($this->pdo, 'reset2@test.com', 'Reset2');
        $this->seedResetToken($this->pdo, $userId, 'apply-tok-001', '2099-12-31 23:59:59');
        $tokenRow = findValidResetToken($this->pdo, 'apply-tok-001');

        $applied = applyPasswordReset($this->pdo, (int) $tokenRow['id'], $userId, 'brandnewpass');

        self::assertTrue($applied);

        // Password hash updated.
        $userRow = $this->pdo->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetch();
        self::assertTrue(password_verify('brandnewpass', $userRow['password_hash']));

        // used_at set on password_resets row.
        $resetRow = $this->pdo->query("SELECT used_at FROM password_resets WHERE id = {$tokenRow['id']}")->fetch();
        self::assertNotNull($resetRow['used_at'], 'used_at must be set after applyPasswordReset');
    }

    public function testApplyPasswordResetReturnsFalseOnConcurrentUse(): void
    {
        // Simulate concurrent use: seed an already-used token.
        $userId = seedUser($this->pdo, 'reset3@test.com', 'Reset3');
        $this->seedResetToken($this->pdo, $userId, 'apply-tok-002', '2099-12-31 23:59:59', '2024-01-15 09:00:00');
        $tokenRow = findValidResetToken($this->pdo, 'apply-tok-002');

        // applyPasswordReset should return false because used_at IS NOT NULL.
        $applied = applyPasswordReset($this->pdo, (int) $tokenRow['id'], $userId, 'shouldnotchange');

        self::assertFalse($applied);

        // Original password hash must be unchanged.
        $userRow = $this->pdo->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetch();
        self::assertTrue(password_verify('password', $userRow['password_hash']), 'Password must not change on concurrent use');
    }
}
