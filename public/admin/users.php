<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/Mailer.php';
require_once __DIR__ . '/../../src/View.php';

startSession();
requireAdmin();

$pdo     = getDb($config);
$errors  = [];
$flash   = null;

// ---------------------------------------------------------------------------
// POST: handle approve / reject / toggle_admin
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $action    = $_POST['action'] ?? '';
    $targetId  = (int) ($_POST['user_id'] ?? 0);
    $adminId   = (int) $_SESSION['user_id'];

    if ($action === 'approve' && $targetId > 0) {
        $pdo->prepare("UPDATE users SET account_status = 'approved' WHERE id = ?")
            ->execute([$targetId]);

        // Send approval email.
        $userRow = $pdo->prepare('SELECT email, display_name FROM users WHERE id = ?');
        $userRow->execute([$targetId]);
        $approvedUser = $userRow->fetch();

        if ($approvedUser !== false) {
            $loginUrl  = rtrim($config['app_url'], '/') . '/login.php';
            $appName   = $config['app_name'] ?? 'AsyncStandUp';
            $userName  = $approvedUser['display_name'] ?: $approvedUser['email'];

            $body = renderEmailTemplate(
                __DIR__ . '/../../templates/email/account_approved.php',
                ['userName' => $userName, 'loginUrl' => $loginUrl, 'appName' => $appName]
            );

            try {
                sendMail($config, $approvedUser['email'], $userName, "Your {$appName} account has been approved", $body);
            } catch (RuntimeException $e) {
                error_log('[AsyncStandUp] approval email failed: ' . $e->getMessage());
            }
        }

        setFlash('success', 'User approved.');
        header('Location: /admin/users.php');
        exit;

    } elseif ($action === 'reject' && $targetId > 0) {
        try {
            // Full FK-safe cascade (matches deleteUserAccount() order).
            $pdo->prepare('UPDATE standup_submissions SET user_id    = NULL WHERE user_id    = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE standup_tokens      SET user_id    = NULL WHERE user_id    = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE organisations       SET created_by = NULL WHERE created_by = ?')->execute([$targetId]);
            $pdo->prepare('UPDATE teams               SET created_by = NULL WHERE created_by = ?')->execute([$targetId]);
            // team_recipients.added_by is a nullable FK to users — must NULL before DELETE.
            $pdo->prepare('UPDATE team_recipients     SET added_by   = NULL WHERE added_by   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM team_members    WHERE user_id   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM org_members     WHERE user_id   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM invitations     WHERE invited_by = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id   = ?')->execute([$targetId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
        } catch (\Throwable $e) {
            error_log('[REJECT DEBUG] ' . $e->getMessage() . ' | Code: ' . $e->getCode());
            throw $e;
        }
        setFlash('success', 'User rejected and removed.');
        header('Location: /admin/users.php');
        exit;

    } elseif ($action === 'toggle_admin' && $targetId > 0) {
        if ($targetId === $adminId) {
            $errors[] = 'You cannot change your own admin status.';
        } else {
            $pdo->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?')
                ->execute([$targetId]);
            setFlash('success', 'Admin flag updated.');
            header('Location: /admin/users.php');
            exit;
        }
    }
}

$flash = $flash ?? getFlash();

// ---------------------------------------------------------------------------
// Load users — pending first, then approved, then rejected
// ---------------------------------------------------------------------------
$users = $pdo->query("
    SELECT id, email, display_name, account_status, is_admin, created_at
    FROM users
    ORDER BY
        CASE account_status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        created_at DESC
")->fetchAll();

$currentUser = getCurrentUser($pdo);
$csrfToken   = generateCsrfToken();

ob_start();
?>
<h1 class="page-title">User Management</h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= h($err) ?></div>
<?php endforeach; ?>

<div class="card" style="overflow-x:auto">
<table>
<thead>
<tr>
    <th>Email</th>
    <th>Display name</th>
    <th>Status</th>
    <th>Admin</th>
    <th>Registered</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
    <td><?= h($u['email']) ?></td>
    <td><?= h($u['display_name'] ?? '') ?></td>
    <td>
        <?php
        $statusColor = match($u['account_status']) {
            'pending'  => '#e65100',
            'approved' => '#2e7d32',
            default    => '#c62828',
        };
        ?>
        <span style="color:<?= $statusColor ?>;font-weight:600"><?= h($u['account_status']) ?></span>
    </td>
    <td><?= $u['is_admin'] ? '✓' : '' ?></td>
    <td><?= h(substr($u['created_at'] ?? '', 0, 10)) ?></td>
    <td class="actions">
        <?php if ($u['account_status'] === 'pending'): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="btn btn-primary btn-sm">Approve</button>
        </form>
        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Reject and delete this user?')">Reject</button>
        </form>
        <?php endif; ?>
        <?php if ((int) $u['id'] !== (int) $_SESSION['user_id']): ?>
        <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="action" value="toggle_admin">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="btn btn-secondary btn-sm">
                <?= $u['is_admin'] ? 'Remove admin' : 'Make admin' ?>
            </button>
        </form>
        <?php endif; ?>
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
