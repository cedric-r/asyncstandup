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
 * Returns a status string:
 *   'ok'       — login successful; session set
 *   'invalid'  — wrong email or wrong password (generic; no enumeration)
 *   'pending'  — account awaiting admin approval
 *   'rejected' — account not approved (edge case; rejected users are deleted)
 */
function loginUser(PDO $pdo, string $email, string $password): string
{
    $stmt = $pdo->prepare('SELECT id, password_hash, is_admin, account_status FROM users WHERE email = ?');
    $stmt->execute([mb_strtolower(trim($email))]);
    $row = $stmt->fetch();

    if ($row === false || !password_verify($password, $row['password_hash'])) {
        return 'invalid'; // Generic — never reveal whether email exists.
    }

    $status = (string) $row['account_status'];

    if ($status === 'pending') {
        return 'pending';
    }

    if ($status === 'rejected') {
        return 'rejected';
    }

    // Approved — start session.
    $_SESSION['user_id']  = (int) $row['id'];
    $_SESSION['is_admin'] = (bool) $row['is_admin'];
    // Guard for CLI/test context where no session is active.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    return 'ok';
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

/**
 * Require the current user to be an administrator.
 *
 * Calls requireLogin() first (redirects unauthenticated users to login).
 * Then checks $_SESSION['is_admin'] — calls forbid() (HTTP 403) if not admin.
 */
function requireAdmin(): void
{
    requireLogin();

    if (empty($_SESSION['is_admin'])) {
        forbid();
    }
}

/**
 * Delete a user account after password confirmation.
 *
 * All cascade steps run in a single transaction. Submissions and tokens are
 * preserved with user_id = NULL for team archival. Orgs/teams created by this
 * user have their created_by set to NULL and are NOT deleted.
 *
 * Returns false if the password is wrong (no DB changes made).
 */
function deleteUserAccount(PDO $pdo, int $userId, string $passwordInput): bool
{
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if ($user === false || !password_verify($passwordInput, $user['password_hash'])) {
        return false;
    }

    $pdo->beginTransaction();

    try {
        // Nullify archival references (preserve team history).
        $pdo->prepare('UPDATE standup_submissions SET user_id    = NULL WHERE user_id    = ?')->execute([$userId]);
        $pdo->prepare('UPDATE standup_tokens      SET user_id    = NULL WHERE user_id    = ?')->execute([$userId]);

        // Nullify created_by on orgs/teams (orgs and teams survive; creator info cleared).
        $pdo->prepare('UPDATE organisations       SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
        $pdo->prepare('UPDATE teams               SET created_by = NULL WHERE created_by = ?')->execute([$userId]);

        // team_recipients.added_by is a nullable FK — must NULL before user DELETE.
        $pdo->prepare('UPDATE team_recipients     SET added_by   = NULL WHERE added_by   = ?')->execute([$userId]);

        // Remove membership records.
        $pdo->prepare('DELETE FROM team_members    WHERE user_id   = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM org_members     WHERE user_id   = ?')->execute([$userId]);

        // Remove invitations sent by this user.
        $pdo->prepare('DELETE FROM invitations     WHERE invited_by = ?')->execute([$userId]);

        // Remove password reset tokens.
        $pdo->prepare('DELETE FROM password_resets WHERE user_id   = ?')->execute([$userId]);

        // Finally delete the user row.
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return true;
}

/**
 * Return true if the user has any ownership or admin privilege anywhere in the system.
 *
 * True if ANY: is_admin=1, OR created ≥1 organisation, OR is_owner=1 on ≥1 team.
 * Used to determine whether the user is allowed to create organisations and teams.
 */
function isOrgOrTeamOwnerAnywhere(PDO $pdo, int $userId): bool
{
    // 1. Admin flag.
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    if ((bool) $stmt->fetchColumn()) {
        return true;
    }

    // 2. Org creator.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM organisations WHERE created_by = ?');
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    // 3. Team owner on any team.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM team_members WHERE user_id = ? AND is_owner = 1'
    );
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    return false;
}

/**
 * Return true if the user is a "pure developer" — has team memberships
 * but holds no ownership or admin privilege anywhere.
 *
 * Zero-membership users return false: a freshly registered user must be
 * able to create their first organisation (bootstrapping).
 *
 * Pure developers can participate in standups but cannot create or manage
 * team structures — that is owner/admin territory.
 */
function isPureDeveloper(PDO $pdo, int $userId): bool
{
    // Must have no ownership or admin privilege.
    if (isOrgOrTeamOwnerAnywhere($pdo, $userId)) {
        return false;
    }

    // Must have at least one team membership (any role) to be considered
    // a "pure developer". Zero-membership new users are not restricted.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE user_id = ?');
    $stmt->execute([$userId]);

    return (int) $stmt->fetchColumn() > 0;
}
