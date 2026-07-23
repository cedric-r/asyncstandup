<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/Mailer.php';

startSession();

$pdo = getDb($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $email = mb_strtolower(trim($_POST['email'] ?? ''));

    // Attempt to find the user — run same code path regardless of existence
    // to prevent email enumeration (AC-2).
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id, display_name FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user !== false) {
            $token    = createPasswordResetToken($pdo, (int) $user['id']);
            $resetUrl = rtrim($config['app_url'], '/') . '/reset-password.php?token=' . urlencode($token);
            $userName = $user['display_name'] ?: $email;

            ob_start();
            extract(['user_name' => $userName, 'reset_url' => $resetUrl, 'expires_minutes' => 60], EXTR_SKIP);
            include __DIR__ . '/../templates/email/password_reset.php';
            $body = (string) ob_get_clean();

            try {
                sendMail($config, $email, $userName, 'Reset your AsyncStandUp password', $body);
            } catch (RuntimeException $e) {
                error_log('[AsyncStandUp] forgot-password sendMail failed: ' . $e->getMessage());
            }
        }
    }

    // Same flash for known + unknown email — no enumeration (AC-2).
    setFlash('success', 'If your email is registered, you will receive a reset link.');
    header('Location: /forgot-password.php');
    exit;
}

$csrfToken = generateCsrfToken();
$flash     = getFlash();

ob_start();
?>
<h1 class="page-title">Forgot password</h1>

<div class="card">
<form method="POST" action="/forgot-password.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autofocus>
    </div>

    <button type="submit" class="btn btn-primary">Send reset link</button>
</form>
</div>

<p class="mt-8"><a href="/login.php">← Back to login</a></p>
<?php
$content   = ob_get_clean();
$pageTitle = 'Forgot password';
$hideNav   = true;
include __DIR__ . '/../templates/layout.php';
