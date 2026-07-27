<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/Captcha.php';
require_once __DIR__ . '/../src/Mailer.php';
require_once __DIR__ . '/../src/View.php'; // renderEmailTemplate()

$config = require __DIR__ . '/../config/config.php';

startSession();

if (isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

$pdo    = getDb($config);
$errors = [];

// Pre-fill email from invitation redirect if present.
$prefillEmail = htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8');
$inviteToken  = htmlspecialchars($_GET['invite'] ?? '', ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation order: CSRF → CAPTCHA → form logic (no DB access on captcha fail).
    validateCsrfToken($_POST['csrf_token'] ?? '');

    if (!captchaValidate($_POST['captcha_answer'] ?? '')) {
        $errors[] = 'Incorrect answer to the security question.';
    } else {
        $email       = trim($_POST['email'] ?? '');
        $password    = $_POST['password'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');
        $invite      = trim($_POST['invite_token'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        }

        if (empty($errors)) {
            try {
                registerUser($pdo, $email, $password, $displayName);

                // US-17: notify all admins of the new pending registration.
                $adminStmt = $pdo->query("SELECT email, display_name FROM users WHERE is_admin = 1 AND account_status = 'approved'");
                $admins    = $adminStmt->fetchAll();

                if (!empty($admins)) {
                    $adminUrl  = rtrim($config['app_url'], '/') . '/admin/users.php';
                    $appName   = $config['app_name'] ?? 'AsyncStandUp';

                    $body = renderEmailTemplate(
                        __DIR__ . '/../templates/email/admin_new_registration.php',
                        [
                            'new_user_name'  => $displayName ?: $email,
                            'new_user_email' => $email,
                            'admin_url'      => $adminUrl,
                            'app_name'       => $appName,
                        ]
                    );

                    $subject = "[{$appName}] New registration awaiting approval";

                    foreach ($admins as $admin) {
                        try {
                            sendMail($config, $admin['email'], $admin['display_name'] ?? $admin['email'], $subject, $body);
                        } catch (RuntimeException $e) {
                            error_log('[AsyncStandUp] admin notification email failed: ' . $e->getMessage());
                        }
                    }
                }

                // No auto-login. New accounts require admin approval.
                header('Location: /login.php?registered=1');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'An account with that email already exists.';
                } else {
                    $errors[] = 'Registration failed. Please try again.';
                }
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$flash     = getFlash();

// Always show a fresh CAPTCHA question (GET) or a new one after failure (POST).
if (!isset($captcha)) {
    $captcha = captchaGetRandomQuestion();
}

ob_start();
?>
<h1 class="page-title">Create an account</h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/register.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="invite_token" value="<?= htmlspecialchars($inviteToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required
               value="<?= htmlspecialchars($_POST['email'] ?? $prefillEmail, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label for="display_name">Display name</label>
        <input type="text" id="display_name" name="display_name" required maxlength="100"
               value="<?= htmlspecialchars($_POST['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label for="password">Password <span class="text-muted">(min 8 characters)</span></label>
        <input type="password" id="password" name="password" required minlength="8">
    </div>

    <div class="form-group">
        <label for="captcha_answer">Security question: <?= htmlspecialchars($captcha['question'], ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="captcha_answer" name="captcha_answer" autocomplete="off" required>
    </div>

    <button type="submit" class="btn btn-primary">Create account</button>
</form>
</div>

<p class="mt-8">Already have an account? <a href="/login.php">Log in</a></p>
<?php
$content   = ob_get_clean();
$pageTitle = 'Register';
$hideNav   = true;
include __DIR__ . '/../templates/layout.php';
