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

    // =========================================================================
    // Path B — Characterisation: ApiAuth::authenticateApiKey()
    // File src/ApiAuth.php has no existing tests — using characterisation path.
    // These tests pin current behaviour against the UNMODIFIED ApiAuth.php.
    // =========================================================================

    public function testAuthenticateApiKeyReturnsNullForMissingHeader(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $result = authenticateApiKey($this->pdo);

        $this->assertNull($result);
    }

    public function testAuthenticateApiKeyReturnsNullForInvalidKey(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalidkeyvalue';

        $result = authenticateApiKey($this->pdo);

        $this->assertNull($result);
    }

    public function testAuthenticateApiKeyReturnsRowForValidKey(): void
    {
        $rawKey  = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);
        $now     = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO api_keys (user_id, key_hash, name, created_at) VALUES (?, ?, ?, ?)'
        )->execute([1, $keyHash, 'chartest', $now]);

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $rawKey;

        $result = authenticateApiKey($this->pdo);

        $this->assertIsArray($result);
        $this->assertSame($keyHash, $result['key_hash']);
        $this->assertSame(1, (int) $result['user_id']);
    }

    // =========================================================================
    // Existing ApiKeyRepository tests
    // =========================================================================

    // -------------------------------------------------------------------------
    // Path B — Characterisation: ApiKeyRepository existing signatures
    // File src/ApiKeyRepository.php has partial coverage — using characterisation path.
    // These tests pin current behaviour against UNMODIFIED ApiKeyRepository.php.
    // -------------------------------------------------------------------------

    public function testCreateApiKeyExistingSignatureAcceptsNameOnly(): void
    {
        // Current signature: createApiKey(PDO, int, string) — 3 args, no expires_at param yet.
        createApiKey($this->pdo, 1, 'chartest-repo');

        $row = $this->pdo->query("SELECT expires_at FROM api_keys WHERE user_id = 1")->fetch();
        $this->assertNull($row['expires_at']);
    }

    public function testListApiKeysForUserExistingShapeHasNoExpiresAt(): void
    {
        // Pins that the current listApiKeysForUser() return shape does NOT include expires_at.
        createApiKey($this->pdo, 1, 'chartest-list');

        $keys = listApiKeysForUser($this->pdo, 1);

        $this->assertCount(1, $keys);
        $this->assertArrayNotHasKey('expires_at', $keys[0]);
    }

    // -------------------------------------------------------------------------

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
