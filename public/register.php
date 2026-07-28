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

$prefillEmail = htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8');
$inviteToken  = htmlspecialchars($_GET['invite'] ?? '', ENT_QUOTES, 'UTF-8');

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

                // Notify all approved admins of the new registration.
                $adminStmt = $pdo->query("SELECT email, display_name FROM users WHERE is_admin = 1 AND account_status = 'approved'");
                $admins    = $adminStmt->fetchAll();

                if (!empty($admins)) {
                    $adminUrl = rtrim($config['app_url'], '/') . '/admin/users.php';
                    $appName  = $config['app_name'] ?? 'AsyncStandUp';
                    $body     = renderEmailTemplate(
                        __DIR__ . '/../templates/email/admin_new_registration.php',
                        ['new_user_name' => $displayName ?: $email, 'new_user_email' => $email, 'admin_url' => $adminUrl, 'app_name' => $appName]
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

if (!isset($captcha)) {
    $captcha = captchaGetRandomQuestion();
}

ob_start();
?>
<div class="min-h-screen flex items-center justify-center py-12">
<div class="w-full max-w-md">
  <h1 class="text-2xl font-bold text-gray-900 mb-2 text-center">Create an account</h1>
  <p class="text-sm text-gray-500 text-center mb-8">Join AsyncStandUp — registration requires admin approval</p>

  <?php foreach ($errors as $err): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endforeach; ?>

  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
    <form method="POST" action="/register.php" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="invite_token" value="<?= htmlspecialchars($inviteToken, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
        <input type="email" id="email" name="email" required autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? $prefillEmail, ENT_QUOTES, 'UTF-8') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div>
        <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1">Display name</label>
        <input type="text" id="display_name" name="display_name" required maxlength="100"
               value="<?= htmlspecialchars($_POST['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div>
        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="font-normal text-gray-400">(min 8 characters)</span></label>
        <input type="password" id="password" name="password" required minlength="8"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div>
        <label for="captcha_answer" class="block text-sm font-medium text-gray-700 mb-1"><?= htmlspecialchars($captcha['question'], ENT_QUOTES, 'UTF-8') ?></label>
        <input type="text" id="captcha_answer" name="captcha_answer" autocomplete="off" required
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <button type="submit"
              class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm transition-colors">
        Create account
      </button>
    </form>
  </div>
  <p class="text-center text-sm text-gray-500 mt-6">Already have an account? <a href="/login.php" class="text-indigo-600 hover:text-indigo-700 font-medium">Log in</a></p>
</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Register';
include __DIR__ . '/../templates/layout.php';
