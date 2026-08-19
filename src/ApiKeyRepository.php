<?php

declare(strict_types=1);

/**
 * Generate a new API key, store its SHA-256 hash, and return the plain-text key (shown once).
 *
 * @param string|null $expiresAt Optional UTC expiry datetime in 'Y-m-d H:i:s' format.
 *                               Pass null (default) for a non-expiring key.
 *
 * @throws \Random\RandomException if random_bytes() fails (PHP 8.2+ — practically never)
 */
function createApiKey(PDO $pdo, int $userId, string $name, ?string $expiresAt = null): string
{
    $rawKey  = bin2hex(random_bytes(32)); // 64-char hex
    $keyHash = hash('sha256', $rawKey);

    $pdo->prepare(
        'INSERT INTO api_keys (user_id, key_hash, name, created_at, expires_at) VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $keyHash, trim($name), gmdate('Y-m-d H:i:s'), $expiresAt]);

    return $rawKey; // caller is responsible for showing this once
}

/**
 * Return all non-revoked API keys for a user, with a masked key preview and expiry status.
 *
 * Uses PHP post-processing for the masked_key and is_expired fields to maintain
 * SQLite compatibility (avoids CONCAT / RIGHT / UTC_TIMESTAMP() which are not available in SQLite).
 *
 * @return array<int, array{id: int, name: string, created_at: string, last_used_at: string|null,
 *                          expires_at: string|null, is_expired: bool, masked_key: string}>
 */
function listApiKeysForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, key_hash, created_at, last_used_at, expires_at
         FROM api_keys
         WHERE user_id = ? AND revoked_at IS NULL
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $now = gmdate('Y-m-d H:i:s');

    return array_map(static function (array $row) use ($now): array {
        $row['masked_key'] = 'sk-...' . substr((string) $row['key_hash'], -6);
        $row['is_expired'] = $row['expires_at'] !== null && $row['expires_at'] < $now;
        unset($row['key_hash']); // never expose hash to callers
        return $row;
    }, $rows);
}

/**
 * Fetch a single api_key row by id and user_id (ownership check).
 *
 * Returns both revoked and non-revoked keys (for display purposes).
 * Returns null if the key does not exist or belongs to a different user.
 *
 * @return array{id: int, user_id: int, key_hash: string, name: string,
 *               created_at: string, last_used_at: string|null,
 *               revoked_at: string|null, expires_at: string|null}|null
 */
function getApiKey(PDO $pdo, int $keyId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, user_id, key_hash, name, created_at, last_used_at, revoked_at, expires_at
         FROM api_keys
         WHERE id = ? AND user_id = ?'
    );
    $stmt->execute([$keyId, $userId]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
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
