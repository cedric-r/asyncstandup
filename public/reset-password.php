<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';

startSession();

$pdo   = getDb($config);
$token = trim($_GET['token'] ?? '');

/** Render an error page and exit. */
function showResetError(string $message, string $pageTitle = 'Reset link invalid'): never
{
    global $flash;
    $flash   = null;
    $content = '<div class="card"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
             . '<p class="mt-8"><a href="/forgot-password.php">Request a new reset link</a></p></div>';
    $GLOBALS['pageTitle'] = $pageTitle;
    $GLOBALS['hideNav']   = true;
    include __DIR__ . '/../templates/layout.php';
    exit;
}

if ($token === '') {
    showResetError('Invalid reset link.');
}

$row = findValidResetToken($pdo, $token);

if ($row === null) {
    showResetError('Invalid reset link.');
}

if ($row['used_at'] !== null) {
    showResetError('Reset link already used.');
}

$expiresAt = new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC'));
$now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($now > $expiresAt) {
    showResetError('Reset link has expired — please request a new one.', 'Link Expired');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    // Re-load token (race condition protection).
    $freshRow = findValidResetToken($pdo, $token);

    if ($freshRow === null || $freshRow['used_at'] !== null) {
        showResetError('This reset link is no longer valid.');
    }

    $newPw     = $_POST['new_password'] ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if (strlen($newPw) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($newPw !== $confirmPw) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $applied = applyPasswordReset($pdo, (int) $row['id'], (int) $row['user_id'], $newPw);

        if (!$applied) {
            showResetError('This reset link was already used by a concurrent request.');
        }

        setFlash('success', 'Password updated — please log in.');
        header('Location: /login.php');
        exit;
    }
}

$csrfToken = generateCsrfToken();
$flash     = getFlash();

ob_start();
?>
<h1 class="page-title">Set new password</h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/reset-password.php?token=<?= urlencode($token) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group">
        <label for="new_password">New password <span class="text-muted">(min 8 characters)</span></label>
        <input type="password" id="new_password" name="new_password" required minlength="8" autofocus>
    </div>

    <div class="form-group">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
    </div>

    <button type="submit" class="btn btn-primary">Set password</button>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Reset Password';
$hideNav   = true;
include __DIR__ . '/../templates/layout.php';
