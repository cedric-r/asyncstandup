<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';

startSession();

$pdo   = getDb($config);
$token = trim($_GET['token'] ?? '');
$flash = null;

function showResetError(string $message, string $pageTitle = 'Reset link invalid'): never
{
    $content = '<div class="max-w-md mx-auto bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">'
             . '<p class="text-gray-700 mb-4">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
             . '<a href="/forgot-password.php" class="text-indigo-600 hover:text-indigo-700 text-sm">Request a new reset link</a>'
             . '</div>';
    $GLOBALS['pageTitle'] = $pageTitle;
    include __DIR__ . '/../templates/layout.php';
    exit;
}

if ($token === '') { showResetError('Invalid reset link.'); }
$row = findValidResetToken($pdo, $token);
if ($row === null) { showResetError('Invalid reset link.'); }
if ($row['used_at'] !== null) { showResetError('Reset link already used.'); }

$expiresAt = new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC'));
$now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($now > $expiresAt) { showResetError('Reset link has expired — please request a new one.', 'Link Expired'); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $freshRow = findValidResetToken($pdo, $token);
    if ($freshRow === null || $freshRow['used_at'] !== null) { showResetError('This reset link is no longer valid.'); }

    $newPw     = $_POST['new_password'] ?? '';
    $confirmPw = $_POST['confirm_password'] ?? '';

    if (strlen($newPw) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($newPw !== $confirmPw) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $applied = applyPasswordReset($pdo, (int) $row['id'], (int) $row['user_id'], $newPw);
        if (!$applied) { showResetError('This reset link was already used by a concurrent request.'); }
        setFlash('success', 'Password updated — please log in.');
        header('Location: /login.php');
        exit;
    }
}

$csrfToken = generateCsrfToken();

ob_start();
?>
<div class="min-h-screen flex items-center justify-center py-12">
<div class="w-full max-w-md">
  <h1 class="text-2xl font-bold text-gray-900 mb-2 text-center">Set new password</h1>
  <p class="text-sm text-gray-500 text-center mb-8">Choose a new password for your account.</p>
  <?php foreach ($errors as $err): ?>
  <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endforeach; ?>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
    <form method="POST" action="/reset-password.php?token=<?= urlencode($token) ?>" class="space-y-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">New password <span class="font-normal text-gray-400">(min 8 characters)</span></label>
        <input type="password" name="new_password" required minlength="8" autofocus
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Confirm new password</label>
        <input type="password" name="confirm_password" required minlength="8"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none">
      </div>
      <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm transition-colors">
        Set password
      </button>
    </form>
  </div>
</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Reset Password';
include __DIR__ . '/../templates/layout.php';
