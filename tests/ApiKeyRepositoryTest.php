<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ApiKeyRepository.php';

class ApiKeyRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (2, 'u2@x.com', 'h2')");
    }

    public function testCreateApiKeyReturnsPlainTextKey(): void
    {
        $rawKey = createApiKey($this->pdo, 1, 'Test key');

        $this->assertSame(64, strlen($rawKey));

        $hash = hash('sha256', $rawKey);
        $row  = $this->pdo->query("SELECT key_hash FROM api_keys WHERE user_id = 1")->fetch();
        $this->assertSame($hash, $row['key_hash']);
    }

    public function testListApiKeysExcludesRevoked(): void
    {
        createApiKey($this->pdo, 1, 'Active key');
        $keyId = (int) $this->pdo->lastInsertId();
        createApiKey($this->pdo, 1, 'Another key');

        revokeApiKey($this->pdo, $keyId, 1);

        $keys = listApiKeysForUser($this->pdo, 1);
        $this->assertCount(1, $keys);
        $this->assertSame('Another key', $keys[0]['name']);
    }

    public function testRevokeApiKeySetsRevokedAt(): void
    {
        createApiKey($this->pdo, 1, 'My key');
        $keyId = (int) $this->pdo->lastInsertId();

        $result = revokeApiKey($this->pdo, $keyId, 1);
        $this->assertTrue($result);

        $row = $this->pdo->query("SELECT revoked_at FROM api_keys WHERE id = $keyId")->fetch();
        $this->assertNotNull($row['revoked_at']);
    }

    public function testRevokeApiKeyFailsForWrongUser(): void
    {
        createApiKey($this->pdo, 1, 'User 1 key');
        $keyId = (int) $this->pdo->lastInsertId();

        $result = revokeApiKey($this->pdo, $keyId, 2); // wrong user
        $this->assertFalse($result);

        $row = $this->pdo->query("SELECT revoked_at FROM api_keys WHERE id = $keyId")->fetch();
        $this->assertNull($row['revoked_at']);
    }
}
