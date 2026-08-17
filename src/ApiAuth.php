<?php

declare(strict_types=1);

/**
 * Authenticate from Authorization: Bearer <key> header.
 *
 * @return array{id: int, user_id: int, key_hash: string, name: string,
 *               created_at: string, last_used_at: string|null, revoked_at: string|null}|null
 *         The api_keys row, or null if the key is missing, invalid, or revoked.
 */
function authenticateApiKey(PDO $pdo): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        return null;
    }

    $rawKey  = trim(substr($header, 7));
    $keyHash = hash('sha256', $rawKey);

    $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL');
    $stmt->execute([$keyHash]);
    $row = $stmt->fetch();

    if ($row === false) {
        return null;
    }

    // Update last_used_at (best-effort — failure is non-fatal).
    $pdo->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s'), $row['id']]);

    return $row;
}

/**
 * Check rate limit: 100 requests per hour per key_hash.
 *
 * Logs the current request if within the limit.
 *
 * @return bool True if the request is within the limit; false if exceeded.
 */
function checkRateLimit(PDO $pdo, string $keyHash): bool
{
    $oneHourAgo = gmdate('Y-m-d H:i:s', time() - 3600);

    $count = $pdo->prepare('SELECT COUNT(*) FROM api_request_log WHERE key_hash = ? AND requested_at > ?');
    $count->execute([$keyHash, $oneHourAgo]);

    if ((int) $count->fetchColumn() >= 100) {
        return false;
    }

    $pdo->prepare('INSERT INTO api_request_log (key_hash, requested_at) VALUES (?, ?)')
        ->execute([$keyHash, gmdate('Y-m-d H:i:s')]);

    return true;
}
