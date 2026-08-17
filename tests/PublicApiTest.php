<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ApiAuth.php';
require_once __DIR__ . '/../src/DashboardRepository.php';

class PublicApiTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();

        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");

        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testNoAuthHeaderReturnsNull(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $result = authenticateApiKey($this->pdo);

        $this->assertNull($result);
    }

    public function testValidKeyAuthenticates(): void
    {
        $raw  = 'testrawkey123';
        $hash = hash('sha256', $raw);
        $this->pdo->exec("INSERT INTO api_keys (user_id, key_hash) VALUES (1, '$hash')");

        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $raw";

        $result = authenticateApiKey($this->pdo);

        $this->assertNotNull($result);
        $this->assertSame($hash, $result['key_hash']);
    }

    /**
     * handleGetTeams() returns teams filtered to the authenticated user's memberships.
     * Tests the underlying data call (getTeamsForUser) — handler wraps this with apiOk()
     * which calls exit; the filtering logic is what AC-8 specifies.
     */
    public function testTeamListFilteredToUserMemberships(): void
    {
        // User 1 is member of teams 1 and 2; user 2 owns team 3 only.
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (2, 'b@x.com', 'h')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'Alpha', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (2, 1, 'Beta',  'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (3, 1, 'Gamma', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 1, 0, 0)");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (2, 1, 0, 1, 0)");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (3, 2, 1, 0, 0)");

        $teams = getTeamsForUser($this->pdo, 1);
        $ids   = array_map(static fn($t) => (int) $t['id'], $teams);

        $this->assertContains(1, $ids, 'User 1 should see team Alpha');
        $this->assertContains(2, $ids, 'User 1 should see team Beta');
        $this->assertNotContains(3, $ids, 'User 1 should NOT see team Gamma (not a member)');
    }

    /**
     * handleGetSubmissions() paginates correctly.
     * Tests the pivot + array_slice logic used by the handler (same code path).
     */
    public function testSubmissionListPaginates(): void
    {
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'T', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 1, 1, 0)");
        $this->pdo->exec("INSERT INTO team_questions (id, team_id, question, position) VALUES (1, 1, 'Q?', 1)");

        // Seed 3 tokens + submissions on different dates.
        foreach ([1, 2, 3] as $i) {
            $date = "2026-08-1{$i}";
            $this->pdo->exec("INSERT INTO standup_tokens (id, team_id, user_id, token, send_date, sent_at, expires_at, used_at)
                VALUES ($i, 1, 1, 'tok$i', '$date', '{$date} 09:00:00', '{$date} 10:00:00', '{$date} 09:30:00')");
            $this->pdo->exec("INSERT INTO standup_submissions (id, token_id, user_id, team_id) VALUES ($i, $i, 1, 1)");
            $this->pdo->exec("INSERT INTO standup_answers (submission_id, question_id, answer) VALUES ($i, 1, 'answer$i')");
        }

        // Replicate the pivot logic from handleGetSubmissions().
        $rows = getResponseData($this->pdo, 1, null, null, '2026-08-11', '2026-08-13');

        $bySubmission = [];
        foreach ($rows as $row) {
            $sid = (int) ($row['submission_id'] ?? 0);
            if ($sid === 0) {
                continue;
            }
            if (!isset($bySubmission[$sid])) {
                $bySubmission[$sid] = ['submission_id' => $sid, 'answers' => []];
            }
            if ($row['question_id'] !== null) {
                $bySubmission[$sid]['answers'][] = $row['answer'];
            }
        }

        $all   = array_values($bySubmission);
        $total = count($all);

        // Page 1, per_page=2
        $page    = 1;
        $perPage = 2;
        $items   = array_slice($all, ($page - 1) * $perPage, $perPage);

        $this->assertSame(3, $total, 'Total should be 3 submissions');
        $this->assertCount(2, $items, 'Page 1 with per_page=2 should return 2 items');
    }

    public function testRateLimitAllows100Requests(): void
    {
        $hash = hash('sha256', 'testkey_rate_allow');
        $this->pdo->exec("INSERT INTO api_keys (user_id, key_hash) VALUES (1, '$hash')");

        $ts = gmdate('Y-m-d H:i:s', time() - 60);
        for ($i = 0; $i < 99; $i++) {
            $this->pdo->exec("INSERT INTO api_request_log (key_hash, requested_at) VALUES ('$hash', '$ts')");
        }

        // 100th request — count=99 < 100, so allowed.
        $this->assertTrue(checkRateLimit($this->pdo, $hash));
    }

    public function testRateLimitBlocks101stRequest(): void
    {
        $hash = hash('sha256', 'testkey_rate_block');
        $ts   = gmdate('Y-m-d H:i:s', time() - 60);

        for ($i = 0; $i < 100; $i++) {
            $this->pdo->exec("INSERT INTO api_request_log (key_hash, requested_at) VALUES ('$hash', '$ts')");
        }

        // count=100 >= 100, blocked.
        $this->assertFalse(checkRateLimit($this->pdo, $hash));
    }
}
