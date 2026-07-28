<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/View.php';

startSession();
requireLogin();

$pdo  = getDb($config);
$user = getCurrentUser($pdo);

$errors = [];
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    if ($action === 'unsubscribe_team') {
        $teamId = (int) ($_POST['team_id'] ?? 0);
        // IDOR guard: verify user actually has is_recipient=1 for this team.
        $check = $pdo->prepare(
            'SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND is_recipient = 1'
        );
        $check->execute([$teamId, (int) $_SESSION['user_id']]);
        if (!$check->fetch()) {
            $errors[] = 'Not subscribed to that team.';
        } else {
            $pdo->prepare('UPDATE team_members SET is_recipient = 0 WHERE team_id = ? AND user_id = ?')
                ->execute([$teamId, (int) $_SESSION['user_id']]);
            setFlash('success', 'Removed from summary list.');
            header('Location: /profile.php');
            exit;
        }
    } elseif ($action === 'delete_account') {
        $confirmPw = $_POST['confirm_password'] ?? '';
        if (deleteUserAccount($pdo, (int) $_SESSION['user_id'], $confirmPw)) {
            session_destroy();
            header('Location: /login.php?deleted=1');
            exit;
        }
        $errors[] = 'Incorrect password.';
    } elseif ($action === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw     = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['confirm_password'] ?? '';
        if (strlen($newPw) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPw !== $confirmPw) {
            $errors[] = 'New passwords do not match.';
        } elseif (!changePassword($pdo, (int) $_SESSION['user_id'], $currentPw, $newPw)) {
            $errors[] = 'Current password is incorrect.';
        } else {
            setFlash('success', 'Password changed successfully.');
            header('Location: /profile.php');
            exit;
        }
    } else {
        $displayName = trim($_POST['display_name'] ?? '');
        $timezone    = trim($_POST['timezone'] ?? 'UTC');
        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'Invalid timezone.';
        }
        if (empty($errors)) {
            $pdo->prepare('UPDATE users SET display_name = ?, timezone = ? WHERE id = ?')
                ->execute([$displayName, $timezone, (int) $_SESSION['user_id']]);
            setFlash('success', 'Profile updated.');
            header('Location: /profile.php');
            exit;
        }
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$allTzs      = DateTimeZone::listIdentifiers();
$currentUser = $user;
$inp         = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

// Load summary subscriptions (teams where user has is_recipient=1).
$subStmt = $pdo->prepare("
    SELECT t.id AS team_id, t.name AS team_name, o.name AS org_name
    FROM team_members tm
    JOIN teams t         ON t.id = tm.team_id
    JOIN organisations o ON o.id = t.org_id
    WHERE tm.user_id = ? AND tm.is_recipient = 1
    ORDER BY o.name, t.name
");
$subStmt->execute([(int) $_SESSION['user_id']]);
$subscriptions = $subStmt->fetchAll();

ob_start();
?>
<div class="max-w-2xl mx-auto">
<h1 class="text-2xl font-bold text-gray-900 mb-6">My Profile</h1>

<?php foreach ($errors as $err): ?>
<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-4">
<h2 class="font-semibold text-gray-900 mb-4">Account details</h2>
<form method="POST" action="/profile.php" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
  <input type="hidden" name="action" value="">
  <div><label class="block text-sm font-medium text-gray-700 mb-1">Email</label><p class="text-sm text-gray-600"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p></div>
  <div>
    <label for="display_name" class="block text-sm font-medium text-gray-700 mb-1">Display name</label>
    <input type="text" id="display_name" name="display_name" required maxlength="100" class="<?= $inp ?>"
           value="<?= htmlspecialchars($_POST['display_name'] ?? $user['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div>
    <label for="timezone" class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
    <select id="timezone" name="timezone" class="<?= $inp ?>">
    <?php foreach ($allTzs as $tz): ?>
      <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= ($tz === ($_POST['timezone'] ?? $user['timezone'] ?? 'UTC')) ? 'selected' : '' ?>><?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Save changes</button>
</form>
</div>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-4">
<h2 class="font-semibold text-gray-900 mb-4">Change password</h2>
<form method="POST" action="/profile.php" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
  <input type="hidden" name="action" value="change_password">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Current password</label>
    <input type="password" name="current_password" required class="<?= $inp ?>">
  </div>
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">New password <span class="font-normal text-gray-400">(min 8 chars)</span></label>
    <input type="password" name="new_password" required minlength="8" class="<?= $inp ?>">
  </div>
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm new password</label>
    <input type="password" name="confirm_password" required minlength="8" class="<?= $inp ?>">
  </div>
  <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Change password</button>
</form>
</div>

<?php if (!empty($subscriptions)): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-4">
<h2 class="font-semibold text-gray-900 mb-2">My summary subscriptions</h2>
<p class="text-sm text-gray-500 mb-4">Teams whose standup summaries you receive. Owners control whether you are added.</p>
<ul class="divide-y divide-gray-100">
<?php foreach ($subscriptions as $sub): ?>
<li class="flex items-center justify-between py-3">
  <span class="text-sm text-gray-900"><?= h($sub['org_name']) ?> / <?= h($sub['team_name']) ?></span>
  <form method="POST" action="/profile.php">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
    <input type="hidden" name="action" value="unsubscribe_team">
    <input type="hidden" name="team_id" value="<?= (int) $sub['team_id'] ?>">
    <button type="submit" class="text-sm text-red-600 hover:text-red-800 underline">Remove me</button>
  </form>
</li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<div class="bg-white rounded-lg shadow-sm border border-red-200 p-6">
<h2 class="font-semibold text-red-700 mb-2">Danger zone</h2>
<p class="text-sm text-gray-500 mb-4">Permanently delete your account. Your standup submissions are retained for team records.</p>
<form method="POST" action="/profile.php" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
  <input type="hidden" name="action" value="delete_account">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm your password</label>
    <input type="password" name="confirm_password" required autocomplete="current-password" class="<?= $inp ?>">
  </div>
  <button type="submit" onclick="return confirm('This is permanent. Are you sure?')"
          class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
    Delete my account
  </button>
</form>
</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Profile';
include __DIR__ . '/../templates/layout.php';
