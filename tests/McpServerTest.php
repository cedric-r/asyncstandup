<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/McpServer.php';
require_once __DIR__ . '/../src/McpTools.php';
require_once __DIR__ . '/../src/DashboardRepository.php';

class McpServerTest extends TestCase
{
    private PDO       $pdo;
    /** @var array<string, mixed> */
    private array     $keyRow;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();

        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");

        $rawKey        = 'testmcpkey';
        $keyHash       = hash('sha256', $rawKey);
        $this->pdo->exec("INSERT INTO api_keys (user_id, key_hash) VALUES (1, '$keyHash')");

        // Fetch the stored row so tests can inject it via constructor.
        $stmt = $this->pdo->prepare('SELECT * FROM api_keys WHERE key_hash = ?');
        $stmt->execute([$keyHash]);
        $this->keyRow = $stmt->fetch();
    }

    public function testInitializeResponseHasCorrectServerInfo(): void
    {
        $server = new McpServer($this->pdo, $this->keyRow);

        $resp = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);

        $this->assertSame('AsyncStandUp', $resp['result']['serverInfo']['name']);
        $this->assertSame('2024-11-05', $resp['result']['protocolVersion']);
    }

    public function testToolsListReturnsAllSixTools(): void
    {
        $server = new McpServer($this->pdo, $this->keyRow);

        $resp  = $server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
        $names = array_column($resp['result']['tools'], 'name');

        $this->assertCount(6, $names);
        $this->assertContains('list_teams', $names);
        $this->assertContains('list_questions', $names);
        $this->assertContains('get_submissions', $names);
        $this->assertContains('get_submission', $names);
        $this->assertContains('submit_standup', $names);
        $this->assertContains('get_team_stats', $names);
    }

    public function testToolsCallWithoutApiKeyReturnsError(): void
    {
        // Inject null apiKey — simulates missing or invalid ASYNCSTANDUP_API_KEY.
        $server = new McpServer($this->pdo, null);

        $resp = $server->handle([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'method'  => 'tools/call',
            'params'  => ['name' => 'list_teams', 'arguments' => []],
        ]);

        $this->assertArrayHasKey('error', $resp);
        $this->assertSame(-32001, $resp['error']['code']);
    }

    public function testListTeamsReturnsUserTeams(): void
    {
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'My Team', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 1, 1, 0)");

        $server = new McpServer($this->pdo, $this->keyRow);

        $resp = $server->handle([
            'jsonrpc' => '2.0',
            'id'      => 4,
            'method'  => 'tools/call',
            'params'  => ['name' => 'list_teams', 'arguments' => []],
        ]);

        $this->assertArrayNotHasKey('error', $resp, 'Expected no error: ' . json_encode($resp));
        $data = json_decode($resp['result']['content'][0]['text'], true);
        $this->assertCount(1, $data);
        $this->assertSame('My Team', $data[0]['name']);
    }
}
