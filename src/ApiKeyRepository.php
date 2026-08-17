<?php

declare(strict_types=1);

/**
 * Generate a new API key, store its SHA-256 hash, and return the plain-text key (shown once).
 *
 * @throws \Random\RandomException if random_bytes() fails (PHP 8.2+ — practically never)
 */
function createApiKey(PDO $pdo, int $userId, string $name): string
{
    $rawKey  = bin2hex(random_bytes(32)); // 64-char hex
    $keyHash = hash('sha256', $rawKey);

    $pdo->prepare(
        'INSERT INTO api_keys (user_id, key_hash, name, created_at) VALUES (?, ?, ?, ?)'
    )->execute([$userId, $keyHash, trim($name), gmdate('Y-m-d H:i:s')]);

    return $rawKey; // caller is responsible for showing this once
}

/**
 * Return all non-revoked API keys for a user, with a masked key preview.
 *
 * Uses PHP post-processing for the masked_key to maintain SQLite compatibility
 * (avoids CONCAT / RIGHT which are not available in SQLite).
 *
 * @return array<int, array{id: int, name: string, created_at: string, last_used_at: string|null, masked_key: string}>
 */
function listApiKeysForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, key_hash, created_at, last_used_at
         FROM api_keys
         WHERE user_id = ? AND revoked_at IS NULL
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row): array {
        $row['masked_key'] = 'sk-...' . substr((string) $row['key_hash'], -6);
        unset($row['key_hash']); // never expose hash to callers
        return $row;
    }, $rows);
}

/**
 * Soft-delete an API key by setting revoked_at to the current UTC time.
 *
 * Scoped to user_id to prevent cross-user revocation.
 * Returns false if the key does not exist, is already revoked, or belongs to another user.
 */
function revokeApiKey(PDO $pdo, int $keyId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE api_keys SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
    );
    $stmt->execute([gmdate('Y-m-d H:i:s'), $keyId, $userId]);

    return $stmt->rowCount() > 0;
}
