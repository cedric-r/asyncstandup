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
