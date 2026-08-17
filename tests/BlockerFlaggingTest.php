<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamRepository.php';
require_once __DIR__ . '/../src/SummaryEmailer.php';

class BlockerFlaggingTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();

        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'T', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'a@x.com', 'h')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 1, 0, 0)");
        $this->pdo->exec("INSERT INTO team_questions (id, team_id, question, position, is_blocker, is_mood) VALUES (1, 1, 'Q1', 1, 1, 0)");
        $this->pdo->exec("INSERT INTO team_questions (id, team_id, question, position, is_blocker, is_mood) VALUES (2, 1, 'Q2', 2, 0, 0)");
    }

    public function testSetBlockerClearsPreviousBlocker(): void
    {
        setBlockerQuestion($this->pdo, 1, 2);

        $q1 = $this->pdo->query("SELECT is_blocker FROM team_questions WHERE id = 1")->fetchColumn();
        $q2 = $this->pdo->query("SELECT is_blocker FROM team_questions WHERE id = 2")->fetchColumn();

        $this->assertSame(0, (int) $q1);
        $this->assertSame(1, (int) $q2);
    }

    public function testBlockerFlagSurfacesInAssembledData(): void
    {
        // Need a submission for assembleSummaryData to return questions.
        $sendDate = '2026-08-17';
        $this->pdo->exec("INSERT INTO standup_tokens (id, team_id, user_id, token, send_date, sent_at, expires_at) VALUES (1, 1, 1, 'tok', '$sendDate', '2026-08-17 09:00:00', '2026-08-17 10:00:00')");
        $this->pdo->exec("INSERT INTO standup_submissions (id, token_id, user_id, team_id) VALUES (1, 1, 1, 1)");
        $this->pdo->exec("INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (1, 1, 'blocked by review')");
        $this->pdo->exec("INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (1, 2, 'done')");
        $this->pdo->exec("UPDATE standup_tokens SET used_at = '2026-08-17 09:30:00' WHERE id = 1");

        $data = assembleSummaryData($this->pdo, 1, $sendDate);

        $questions = array_column($data['questions'], null, 'id');
        $this->assertSame(1, (int) ($questions[1]['is_blocker'] ?? 0));
        $this->assertSame(0, (int) ($questions[2]['is_blocker'] ?? 0));
    }

    public function testClearBlockerRemovesAllFlags(): void
    {
        // Ensure q1 starts with is_blocker=1
        clearBlockerQuestion($this->pdo, 1);

        $q1 = $this->pdo->query("SELECT is_blocker FROM team_questions WHERE id = 1")->fetchColumn();
        $q2 = $this->pdo->query("SELECT is_blocker FROM team_questions WHERE id = 2")->fetchColumn();

        $this->assertSame(0, (int) $q1);
        $this->assertSame(0, (int) $q2);
    }
}
