<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/Mailer.php';
require_once __DIR__ . '/../../src/View.php'; // renderEmailTemplate()

startSession();
$pdo = getDb($config);
requireAdmin($pdo);
$errors  = [];
$flash   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $action   = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $adminId  = (int) $_SESSION['user_id'];

    if ($action === 'approve' && $targetId > 0) {
        $pdo->prepare("UPDATE users SET account_status = 'approved' WHERE id = ?")->execute([$targetId]);
        $userRow = $pdo->prepare('SELECT email, display_name FROM users WHERE id = ?');
        $userRow->execute([$targetId]);
        $approvedUser = $userRow->fetch();
        if ($approvedUser !== false) {
            $loginUrl = rtrim($config['app_url'], '/') . '/login.php';
            $appName  = $config['app_name'] ?? 'AsyncStandUp';
            $userName = $approvedUser['display_name'] ?: $approvedUser['email'];
            $body = renderEmailTemplate(__DIR__ . '/../../templates/email/account_approved.php',
                ['userName' => $userName, 'loginUrl' => $loginUrl, 'appName' => $appName]);
            try { sendMail($config, $approvedUser['email'], $userName, "Your {$appName} account has been approved", $body); }
            catch (RuntimeException $e) { error_log('[AsyncStandUp] approval email failed: ' . $e->getMessage()); }
        }
        setFlash('success', 'User approved.');
        header('Location: /admin/users.php');
        exit;

    } elseif ($action === 'reject' && $targetId > 0) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE standup_submissions SET user_id    = NULL WHERE user_id    = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE standup_tokens      SET user_id    = NULL WHERE user_id    = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE organisations       SET created_by = NULL WHERE created_by = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE teams               SET created_by = NULL WHERE created_by = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE team_recipients     SET added_by   = NULL WHERE added_by   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM team_members    WHERE user_id   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM org_members     WHERE user_id   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM invitations     WHERE invited_by = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[AsyncStandUp] admin reject user ' . $targetId . ' failed: ' . $e->getMessage());
            throw $e;
        }
        setFlash('success', 'User rejected and removed.');
        header('Location: /admin/users.php');
        exit;

    } elseif ($action === 'toggle_admin' && $targetId > 0) {
        if ($targetId === $adminId) {
            $errors[] = 'You cannot change your own admin status.';
        } else {
            $pdo->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$targetId]);
            setFlash('success', 'Admin flag updated.');
            header('Location: /admin/users.php');
            exit;
        }

    } elseif ($action === 'delete_user' && $targetId > 0) {
        if ($targetId === $adminId) {
            $errors[] = 'You cannot delete your own account from the admin panel.';
        } else {
            adminDeleteUser($pdo, $targetId);
            setFlash('success', 'User deleted.');
            header('Location: /admin/users.php');
            exit;
        }
    }
}

$flash = $flash ?? getFlash();

$users = $pdo->query("
    SELECT id, email, display_name, account_status, is_admin, created_at
    FROM users
    ORDER BY CASE account_status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, created_at DESC
")->fetchAll();

$currentUser = getCurrentUser($pdo);
$csrfToken   = generateCsrfToken();

$badgeCls = [
    'pending'  => 'bg-amber-100 text-amber-800',
    'approved' => 'bg-green-100 text-green-800',
    'rejected' => 'bg-red-100 text-red-800',
];

ob_start();
?>
<h1 class="text-2xl font-bold text-gray-900 mb-6">User Management</h1>
<?php foreach ($errors as $err): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
<table class="w-full text-sm">
<thead class="bg-gray-50 border-b border-gray-200">
<tr>
  <th class="px-4 py-3 text-left font-medium text-gray-700">Email</th>
  <th class="px-4 py-3 text-left font-medium text-gray-700">Name</th>
  <th class="px-4 py-3 text-left font-medium text-gray-700">Status</th>
  <th class="px-4 py-3 text-left font-medium text-gray-700">Admin</th>
  <th class="px-4 py-3 text-left font-medium text-gray-700">Registered</th>
  <th class="px-4 py-3 text-left font-medium text-gray-700">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach ($users as $u): ?>
<tr class="hover:bg-gray-50">
  <td class="px-4 py-3 text-gray-900"><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
  <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($u['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
  <td class="px-4 py-3">
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $badgeCls[$u['account_status']] ?? 'bg-gray-100 text-gray-800' ?>">
      <?= htmlspecialchars($u['account_status'], ENT_QUOTES, 'UTF-8') ?>
    </span>
  </td>
  <td class="px-4 py-3"><?= $u['is_admin'] ? '<span class="text-indigo-600 font-bold text-xs">✓ Admin</span>' : '' ?></td>
  <td class="px-4 py-3 text-gray-400 text-xs"><?= htmlspecialchars(substr($u['created_at'] ?? '', 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
  <td class="px-4 py-3">
    <div class="flex flex-wrap gap-1.5">
      <?php if ($u['account_status'] === 'pending'): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <button type="submit" class="text-xs bg-green-600 hover:bg-green-700 text-white font-medium py-1 px-2.5 rounded">Approve</button>
      </form>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <button type="submit" onclick="return confirm('Reject and delete?')" class="text-xs bg-red-600 hover:bg-red-700 text-white font-medium py-1 px-2.5 rounded">Reject</button>
      </form>
      <?php endif; ?>
      <?php if ((int) $u['id'] !== (int) $_SESSION['user_id']): ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="toggle_admin">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <button type="submit" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded border border-gray-200">
          <?= $u['is_admin'] ? 'Remove admin' : 'Make admin' ?>
        </button>
      </form>
      <form method="POST" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <button type="submit" onclick="return confirm('Delete this user and all their data? This cannot be undone.')"
                class="text-xs bg-red-600 hover:bg-red-700 text-white font-medium py-1 px-2.5 rounded">
          Delete
        </button>
      </form>
      <?php endif; ?>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Admin — Users';
include __DIR__ . '/../../templates/layout.php';
