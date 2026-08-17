<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamRepository.php';
require_once __DIR__ . '/../src/StandupEmailer.php';

class MoodTrackingTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();

        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'T', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'a@x.com', 'h')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 1, 0, 0)");
        $this->pdo->exec("INSERT INTO team_questions (id, team_id, question, position, is_blocker, is_mood) VALUES (1, 1, 'Q1', 1, 0, 1)");
        $this->pdo->exec("INSERT INTO team_questions (id, team_id, question, position, is_blocker, is_mood) VALUES (2, 1, 'Q2', 2, 0, 0)");
    }

    public function testScoreMoodAnswerKnownPatterns(): void
    {
        $this->assertSame(5, scoreMoodAnswer('great'));
        $this->assertSame(1, scoreMoodAnswer('bad'));
        $this->assertSame(3, scoreMoodAnswer('ok'));
    }

    public function testScoreMoodAnswerReturnsNullForUnrecognised(): void
    {
        $this->assertNull(scoreMoodAnswer('the PR was reviewed and merged'));
    }

    public function testIsMoodFlagOnlyOnePerTeam(): void
    {
        setMoodQuestion($this->pdo, 1, 2);

        $q1 = $this->pdo->query("SELECT is_mood FROM team_questions WHERE id = 1")->fetchColumn();
        $q2 = $this->pdo->query("SELECT is_mood FROM team_questions WHERE id = 2")->fetchColumn();

        $this->assertSame(0, (int) $q1);
        $this->assertSame(1, (int) $q2);
    }
}
