<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';

startSession();
requireLogin();

$pdo  = getDb($config);
$user = getCurrentUser($pdo);

$errors = [];

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    if ($action === 'change_password') {
        // Change-password sub-form.
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';

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
        // Profile sub-form.
        $displayName = trim($_POST['display_name'] ?? '');
        $timezone    = trim($_POST['timezone'] ?? 'UTC');
        $validTzs    = DateTimeZone::listIdentifiers();

        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        }

        if (!in_array($timezone, $validTzs, true)) {
            $errors[] = 'Invalid timezone.';
        }

        if (empty($errors)) {
            $stmt = $pdo->prepare('UPDATE users SET display_name = ?, timezone = ? WHERE id = ?');
            $stmt->execute([$displayName, $timezone, (int) $_SESSION['user_id']]);

            setFlash('success', 'Profile updated.');
            header('Location: /profile.php');
            exit;
        }
    }
}

$csrfToken  = generateCsrfToken();
$flash      = getFlash();
$allTzs     = DateTimeZone::listIdentifiers();
$currentUser = $user;

ob_start();
?>
<h1 class="page-title">My Profile</h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/profile.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group">
        <label>Email</label>
        <p><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="form-group">
        <label for="display_name">Display name</label>
        <input type="text" id="display_name" name="display_name" required maxlength="100"
               value="<?= htmlspecialchars($_POST['display_name'] ?? $user['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label for="timezone">Timezone</label>
        <select id="timezone" name="timezone">
        <?php foreach ($allTzs as $tz): ?>
            <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>"
                <?= ($tz === ($_POST['timezone'] ?? $user['timezone'] ?? 'UTC')) ? 'selected' : '' ?>>
                <?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>
            </option>
        <?php endforeach; ?>
        </select>
    </div>

    <input type="hidden" name="action" value="">
    <button type="submit" class="btn btn-primary">Save changes</button>
</form>
</div>

<div class="card mt-16">
<h3>Change password</h3>
<form method="POST" action="/profile.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="change_password">

    <div class="form-group">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required>
    </div>

    <div class="form-group">
        <label for="new_password">New password <span class="text-muted">(min 8 characters)</span></label>
        <input type="password" id="new_password" name="new_password" required minlength="8">
    </div>

    <div class="form-group">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
    </div>

    <button type="submit" class="btn btn-primary">Change password</button>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Profile';
include __DIR__ . '/../templates/layout.php';
