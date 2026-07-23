<?php

declare(strict_types=1);

// Suppress stack-trace / DSN leakage on uncaught errors (M-1).
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);
    echo '<p>An unexpected error occurred. Please try again later.</p>';
    error_log((string) $e);
    exit;
});

/**
 * Start a PHP session with secure cookie settings.
 *
 * Must be called before any output and before reading/writing $_SESSION.
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', '1');
    ini_set('session.use_strict_mode', '1');

    session_start();
}

/**
 * Return true if a user is currently logged in.
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Redirect to login if no session exists. Must be called before any output.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Load the current user row from the database.
 *
 * @return array|null User row, or null if not found.
 */
function getCurrentUser(PDO $pdo): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Attempt to log in a user by email and password.
 *
 * On success, sets $_SESSION['user_id'] and regenerates the session ID.
 *
 * @return bool True on successful authentication.
 */
function loginUser(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([mb_strtolower(trim($email))]);
    $row = $stmt->fetch();

    if ($row === false) {
        return false;
    }

    if (!password_verify($password, $row['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = (int) $row['id'];
    session_regenerate_id(true);

    return true;
}

/**
 * Register a new user account.
 *
 * Throws PDOException on duplicate email (UNIQUE violation).
 *
 * @return int The new user's ID.
 */
function registerUser(PDO $pdo, string $email, string $password, string $displayName): int
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, password_hash, display_name) VALUES (?, ?, ?)'
    );
    $stmt->execute([mb_strtolower(trim($email)), $hash, trim($displayName)]);

    return (int) $pdo->lastInsertId();
}

/**
 * Destroy the current session and redirect to login.
 */
function logoutUser(): void
{
    $_SESSION = [];
    session_destroy();
    header('Location: /login.php');
    exit;
}

/**
 * Retrieve a flash message from the session and remove it.
 *
 * @return array{type: string, text: string}|null
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return $flash;
    }

    return null;
}

/**
 * Store a flash message to display on the next request.
 *
 * @param string $type 'success' or 'error'.
 */
function setFlash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

/**
 * Send HTTP 403 Forbidden and exit.
 *
 * Used as a one-liner access guard across all page controllers.
 */
function forbid(): never
{
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

/**
 * Verify the current password and update to a new one if it matches.
 *
 * @param string $current Plain-text current password for verification.
 * @param string $new     Plain-text new password (caller must validate min length).
 * @return bool True if password updated; false if current password was wrong.
 */
function changePassword(PDO $pdo, int $userId, string $current, string $new): bool
{
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if ($row === false || !password_verify($current, $row['password_hash'])) {
        return false;
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([$hash, $userId]);

    return true;
}

/**
 * Generate a password reset token and insert it into password_resets.
 *
 * @return string 64-char hex token.
 */
function createPasswordResetToken(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): string
{
    $token     = bin2hex(random_bytes(32));
    $now     ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $now->modify('+1 hour')->format('Y-m-d H:i:s');

    $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$userId, $token, $expiresAt]);

    return $token;
}

/**
 * Find a password reset token row by token string.
 *
 * Returns the row or null if not found. Caller validates expiry and used_at.
 */
function findValidResetToken(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Apply a password reset: update the user's password and mark the token used.
 *
 * Wrapped in a transaction — both updates succeed or neither does.
 * The token is claimed atomically via `used_at IS NULL` — concurrent requests
 * will see rowCount() = 0 and the function returns false without updating
 * the password.
 *
 * @return bool True if password was reset; false if token was already used
 *              by a concurrent request.
 */
function applyPasswordReset(PDO $pdo, int $tokenId, int $userId, string $newPassword): bool
{
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $ts   = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();

    try {
        // Atomically claim the token — fails if already used (concurrent request).
        $stmt = $pdo->prepare(
            'UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL'
        );
        $stmt->execute([$ts, $tokenId]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();

            return false; // Token already consumed by a concurrent request.
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return true;
}
