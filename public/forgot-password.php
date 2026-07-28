<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/Mailer.php';
require_once __DIR__ . '/../src/View.php';

startSession();

$pdo = getDb($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $email = mb_strtolower(trim($_POST['email'] ?? ''));

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id, display_name FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user !== false) {
            $token    = createPasswordResetToken($pdo, (int) $user['id']);
            $resetUrl = rtrim($config['app_url'], '/') . '/reset-password.php?token=' . urlencode($token);
            $userName = $user['display_name'] ?: $email;
            $body     = renderEmailTemplate(
                __DIR__ . '/../templates/email/password_reset.php',
                ['user_name' => $userName, 'reset_url' => $resetUrl, 'expires_minutes' => 60]
            );
            try {
                sendMail($config, $email, $userName, 'Reset your AsyncStandUp password', $body);
            } catch (RuntimeException $e) {
                error_log('[AsyncStandUp] forgot-password sendMail failed: ' . $e->getMessage());
            }
        }
    }

    setFlash('success', 'If your email is registered, you will receive a reset link.');
    header('Location: /forgot-password.php');
    exit;
}

$csrfToken = generateCsrfToken();
$flash     = getFlash();

ob_start();
?>
<div class="min-h-screen flex items-center justify-center py-12">
<div class="w-full max-w-md">
  <h1 class="text-2xl font-bold text-gray-900 mb-2 text-center">Forgot password</h1>
  <p class="text-sm text-gray-500 text-center mb-8">Enter your email and we'll send you a reset link.</p>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
    <form method="POST" action="/forgot-password.php" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
        <input type="email" id="email" name="email" required autofocus
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm transition-colors">
        Send reset link
      </button>
    </form>
  </div>
  <p class="text-center text-sm text-gray-500 mt-6"><a href="/login.php" class="text-indigo-600 hover:text-indigo-700">← Back to login</a></p>
</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Forgot password';
include __DIR__ . '/../templates/layout.php';
