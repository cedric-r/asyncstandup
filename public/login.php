<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/Captcha.php';

startSession();

if (isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

$pdo    = getDb($config);
$error  = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    if (!captchaValidate($_POST['captcha_answer'] ?? '')) {
        $errors[] = 'Incorrect answer to the security question.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $result   = loginUser($pdo, $email, $password);

        if ($result === 'ok') {
            if (!empty($_SESSION['pending_invite_token'])) {
                require_once __DIR__ . '/../src/InvitationRepository.php';
                $pendingToken = (string) $_SESSION['pending_invite_token'];
                unset($_SESSION['pending_invite_token']);
                acceptInvitationForUser($pdo, $pendingToken, (int) $_SESSION['user_id']);
                setFlash('success', 'Welcome back! You have joined the team.');
            } else {
                setFlash('success', 'Welcome back!');
            }
            header('Location: /dashboard.php');
            exit;
        } elseif ($result === 'pending') {
            $error = 'Your account is awaiting administrator approval.';
        } elseif ($result === 'rejected') {
            $error = 'Your account was not approved. Please contact the administrator.';
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

$csrfToken = generateCsrfToken();
$flash     = getFlash();
$captcha   = captchaGetRandomQuestion();

if (isset($_GET['registered'])) {
    $flash = ['type' => 'success', 'text' => 'Account created. Your registration is pending administrator approval. You will receive an email when approved.'];
}
if (isset($_GET['deleted'])) {
    $flash = ['type' => 'success', 'text' => 'Your account has been deleted.'];
}

ob_start();
?>
<div class="min-h-screen flex items-center justify-center py-12">
<div class="w-full max-w-md">
  <h1 class="text-2xl font-bold text-gray-900 mb-2 text-center">Welcome back</h1>
  <p class="text-sm text-gray-500 text-center mb-8">Log in to your AsyncStandUp account</p>

  <?php foreach ($errors as $err): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endforeach; ?>
  <?php if ($error !== null): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
    <form method="POST" action="/login.php" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
        <input type="email" id="email" name="email" required autofocus
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div>
        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
        <input type="password" id="password" name="password" required
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div>
        <label for="captcha_answer" class="block text-sm font-medium text-gray-700 mb-1">
          <?= htmlspecialchars($captcha['question'], ENT_QUOTES, 'UTF-8') ?>
        </label>
        <input type="text" id="captcha_answer" name="captcha_answer" autocomplete="off" required
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <button type="submit"
              class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm transition-colors">
        Log in
      </button>
    </form>
  </div>
  <p class="text-center text-sm text-gray-500 mt-6">
    Don't have an account? <a href="/register.php" class="text-indigo-600 hover:text-indigo-700 font-medium">Register</a>
    &nbsp;·&nbsp;
    <a href="/forgot-password.php" class="text-indigo-600 hover:text-indigo-700">Forgot password?</a>
  </p>
</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Log in';
include __DIR__ . '/../templates/layout.php';
