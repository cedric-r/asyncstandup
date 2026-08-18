<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class BotWebhookTest extends TestCase
{
    private PDO $pdo;
    private BotActivityHandler $handler;

    protected function setUp(): void
    {
        $this->pdo     = createTestPdo();
        $this->handler = new BotActivityHandler($this->pdo, ['app_id' => 'testapp']);

        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash, teams_aad_id) VALUES (1, 'u@x.com', 'h', 'aad-123')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by) VALUES (1, 1, 'T', 'UTC', '09:00', 1)");
        $this->pdo->exec("INSERT INTO standup_tokens (id, team_id, user_id, token, send_date, sent_at, expires_at)
            VALUES (1, 1, 1, 'validtoken', date('now'), datetime('now'), datetime('now', '+2 hours'))");
        $this->pdo->exec("INSERT INTO team_questions (id, team_id, question, position) VALUES (1, 1, 'Q1?', 1)");
    }

    public function testHandleUnknownActivityReturns200Ignored(): void
    {
        [$code, $body] = $this->handler->handle(['type' => 'message', 'text' => 'hello']);

        $this->assertEquals(200, $code);
        $this->assertEquals('ignored', $body['status']);
    }

    public function testHandleInvokeRejectsAlreadyUsedToken(): void
    {
        $this->pdo->exec("UPDATE standup_tokens SET used_at = datetime('now') WHERE id = 1");

        $activity = [
            'type'         => 'invoke',
            'value'        => ['token' => 'validtoken'],
            'serviceUrl'   => '',
            'conversation' => ['id' => ''],
            'from'         => ['aadObjectId' => 'aad-123'],
        ];
        [$code, $body] = $this->handler->handle($activity);

        $this->assertEquals(409, $code);
        $this->assertArrayHasKey('error', $body);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM standup_submissions')->fetchColumn();
        $this->assertEquals(0, $count, 'No submission row should be created for an already-used token');
    }

    public function testHandleInvokeSavesAnswers(): void
    {
        $activity = [
            'type'         => 'invoke',
            'value'        => ['token' => 'validtoken', 'q_1' => 'My answer'],
            'serviceUrl'   => '',
            'conversation' => ['id' => ''],
            'from'         => ['aadObjectId' => 'aad-123'],
        ];
        [$code, $body] = $this->handler->handle($activity);

        $this->assertEquals(200, $code);
        $this->assertEquals('submitted', $body['status']);
        $this->assertGreaterThan(0, $body['submission_id']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM standup_submissions')->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testHandleConversationUpdateRejectsInvalidServiceUrl(): void
    {
        $activity = [
            'type'         => 'conversationUpdate',
            'membersAdded' => [['aadObjectId' => 'aad-123']],
            'serviceUrl'   => 'http://evil.com/',
            'conversation' => ['id' => 'conv-abc'],
            'recipient'    => ['id' => 'bot-1'],
            'channelId'    => 'msteams',
        ];
        $this->handler->handle($activity);

        $row = $this->pdo->query('SELECT teams_conversation_ref FROM users WHERE id = 1')->fetch();
        $ref = json_decode((string) ($row['teams_conversation_ref'] ?? 'null'), true);
        $this->assertIsArray($ref, 'Conv ref should be stored even with invalid serviceUrl');
        $this->assertNull($ref['serviceUrl'], 'Unvalidated serviceUrl must be stored as null');
    }

    public function testHandleConversationUpdateSavesConvRef(): void
    {
        $activity = [
            'type'         => 'conversationUpdate',
            'membersAdded' => [['aadObjectId' => 'aad-123']],
            'serviceUrl'   => 'https://smba.trafficmanager.net/emea/',
            'conversation' => ['id' => 'conv-abc'],
            'recipient'    => ['id' => 'bot-1'],
            'channelId'    => 'msteams',
        ];
        $this->handler->handle($activity);

        $row = $this->pdo->query('SELECT teams_conversation_ref FROM users WHERE id = 1')->fetch();
        $this->assertNotNull($row['teams_conversation_ref']);

        $ref = json_decode((string) $row['teams_conversation_ref'], true);
        $this->assertEquals('https://smba.trafficmanager.net/emea/', $ref['serviceUrl']);
        $this->assertEquals('msteams', $ref['channelId']);
    }
}
