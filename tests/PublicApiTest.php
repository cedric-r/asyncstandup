<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ApiAuth.php';

class PublicApiTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();

        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");

        // Clear superglobal between tests.
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

    public function testInvalidKeyReturnsNull(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer notavalidkeyatall';

        $result = authenticateApiKey($this->pdo);

        $this->assertNull($result);
    }

    public function testRateLimitAllows100Requests(): void
    {
        $hash = hash('sha256', 'testkey_rate_allow');
        $this->pdo->exec("INSERT INTO api_keys (user_id, key_hash) VALUES (1, '$hash')");

        // Pre-insert 99 log entries within the last hour.
        $ts = gmdate('Y-m-d H:i:s', time() - 60);
        for ($i = 0; $i < 99; $i++) {
            $this->pdo->exec("INSERT INTO api_request_log (key_hash, requested_at) VALUES ('$hash', '$ts')");
        }

        // 100th request should be allowed (count=99 < 100).
        $this->assertTrue(checkRateLimit($this->pdo, $hash));
    }

    public function testRateLimitBlocks101stRequest(): void
    {
        $hash = hash('sha256', 'testkey_rate_block');
        $ts   = gmdate('Y-m-d H:i:s', time() - 60);

        // Pre-insert 100 log entries — next call should be blocked.
        for ($i = 0; $i < 100; $i++) {
            $this->pdo->exec("INSERT INTO api_request_log (key_hash, requested_at) VALUES ('$hash', '$ts')");
        }

        $this->assertFalse(checkRateLimit($this->pdo, $hash));
    }
}
