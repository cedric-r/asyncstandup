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
        recordFailedLogin($pdo, $email); // Fix 3: track failed attempts.
        return 'invalid'; // Generic — never reveal whether email exists.
    }

    $status = (string) $row['account_status'];

    if ($status === 'pending') {
        return 'pending';
    }

    if ($status === 'rejected') {
        return 'rejected';
    }

    // Approved — clear any lockout record and start session.
    clearLoginAttempts($pdo, $email); // Fix 3: reset on success.
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
/**
 * Generate a password reset token for a user.
 *
 * Fix 2B: deletes all prior unused tokens before inserting the new one
 *   (prevents stale token accumulation; each request invalidates prior tokens).
 * Fix 2C: rate-limits using a SEPARATE log table `password_reset_requests`.
 *   This allows 2B and 2C to work independently:
 *   - 2B deletes from `password_resets` (so COUNT there would always be 0/1)
 *   - 2C counts from `password_reset_requests` (append-only log; never deleted)
 *   If ≥3 requests in 15 minutes, returns '' — caller shows generic flash; no enumeration.
 *
 * @return string 64-char hex token, or '' if rate limit exceeded.
 */
function createPasswordResetToken(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): string
{
    $now     ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $window    = $now->modify('-15 minutes')->format('Y-m-d H:i:s');
    $nowFmt    = $now->format('Y-m-d H:i:s');
    $expiresAt = $now->modify('+1 hour')->format('Y-m-d H:i:s');

    // Fix 2C: rate-limit check using append-only request log (independent of Fix 2B).
    $rateStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM password_reset_requests WHERE user_id = ? AND requested_at > ?'
    );
    $rateStmt->execute([$userId, $window]);
    if ((int) $rateStmt->fetchColumn() >= 3) {
        return ''; // Rate limit exceeded — no new token; caller shows generic flash.
    }

    // Log this request (used only for rate limiting; never deleted).
    $pdo->prepare('INSERT INTO password_reset_requests (user_id, requested_at) VALUES (?, ?)')
        ->execute([$userId, $nowFmt]);

    $token = bin2hex(random_bytes(32));

    // Fix 2B: invalidate prior unused tokens + insert new one atomically.
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
            ->execute([$userId]);
        $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
            ->execute([$userId, $token, $expiresAt]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

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
 * Fix 4: re-queries the DB on every call to detect de-admin'd sessions.
 * Updates $_SESSION['is_admin'] and calls forbid() if not admin or not approved.
 *
 * @param PDO $pdo Active database connection for live re-verification.
 */
function requireAdmin(PDO $pdo): void
{
    requireLogin();

    $stmt = $pdo->prepare(
        'SELECT is_admin, account_status FROM users WHERE id = ?'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();

    if (!$row || !(bool) $row['is_admin'] || $row['account_status'] !== 'approved') {
        unset($_SESSION['is_admin']); // Revoke stale session flag.
        forbid();
    }

    $_SESSION['is_admin'] = true; // Keep session flag in sync.
}

// ---------------------------------------------------------------------------
// Fix 3 — Login rate limiting helpers
// ---------------------------------------------------------------------------

/**
 * Return true if the given email is currently locked out due to too many failures.
 */
function isLoginLocked(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare('SELECT locked_until FROM login_attempts WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();

    if ($row === false || empty($row['locked_until'])) {
        return false;
    }

    $lockedUntil = new DateTimeImmutable($row['locked_until'], new DateTimeZone('UTC'));
    $now         = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return $lockedUntil > $now;
}

/**
 * Record a failed login attempt. Locks the account after 5 failures in 10 minutes.
 *
 * Uses DELETE+INSERT for the fresh-window case (cross-DB compatible;
 * MySQL's ON DUPLICATE KEY UPDATE would not work in SQLite tests).
 */
function recordFailedLogin(PDO $pdo, string $email): void
{
    $email   = strtolower(trim($email));
    $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $window  = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
    $nowStr  = $now->format('Y-m-d H:i:s');
    $lockStr = $now->modify('+5 minutes')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'SELECT attempt_count, first_attempt_at FROM login_attempts WHERE email = ?'
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if ($row === false || $row['first_attempt_at'] < $window) {
        // Fresh window — reset counter.
        $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
        $pdo->prepare(
            'INSERT INTO login_attempts (email, attempt_count, first_attempt_at, locked_until)
             VALUES (?, 1, ?, NULL)'
        )->execute([$email, $nowStr]);
    } else {
        $newCount = (int) $row['attempt_count'] + 1;
        $lock     = $newCount >= 5 ? $lockStr : null;
        $pdo->prepare(
            'UPDATE login_attempts SET attempt_count = ?, locked_until = ? WHERE email = ?'
        )->execute([$newCount, $lock, $email]);
    }
}

/**
 * Clear login attempt history on successful login.
 */
function clearLoginAttempts(PDO $pdo, string $email): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')
        ->execute([strtolower(trim($email))]);
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
/**
 * Execute the full user deletion cascade inside the caller's transaction.
 *
 * Does NOT begin or commit the transaction — caller is responsible.
 * 10 FK-safe steps (order matters; follow exactly):
 *   1. standup_submissions.user_id → NULL (archival preserved)
 *   2. standup_tokens.user_id → NULL (archival preserved)
 *   3. organisations.created_by → NULL (org survives; creator info cleared)
 *   4. teams.created_by → NULL
 *   5. team_recipients.added_by → NULL (nullable FK; hot-fix 972452f)
 *   6. DELETE team_members
 *   7. DELETE org_members
 *   8. DELETE invitations WHERE invited_by
 *   9. DELETE password_resets
 *  10. DELETE users
 */
function cascadeDeleteUser(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE standup_submissions SET user_id    = NULL WHERE user_id    = ?')->execute([$userId]);
    $pdo->prepare('UPDATE standup_tokens      SET user_id    = NULL WHERE user_id    = ?')->execute([$userId]);
    $pdo->prepare('UPDATE organisations       SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
    $pdo->prepare('UPDATE teams               SET created_by = NULL WHERE created_by = ?')->execute([$userId]);
    $pdo->prepare('UPDATE team_recipients     SET added_by   = NULL WHERE added_by   = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM team_members    WHERE user_id   = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM org_members     WHERE user_id   = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM invitations     WHERE invited_by = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id   = ?')->execute([$userId]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
}

/**
 * Delete a user account after password confirmation.
 *
 * Verifies the password, then calls cascadeDeleteUser() inside a transaction.
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
        cascadeDeleteUser($pdo, $userId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return true;
}

/**
 * Delete a user account as an administrator (no password check required).
 *
 * Wraps cascadeDeleteUser() in its own transaction.
 * Caller must verify the target is not the admin's own account.
 */
function adminDeleteUser(PDO $pdo, int $targetUserId): void
{
    $pdo->beginTransaction();

    try {
        cascadeDeleteUser($pdo, $targetUserId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
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
