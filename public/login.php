<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';

startSession();

if (isLoggedIn()) {
    header('Location: /dashboard.php');
    exit;
}

$pdo   = getDb($config);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (loginUser($pdo, $email, $password)) {
        // AC-3: if an invitation was pending, auto-accept it on login.
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
    }

    $error = 'Invalid email or password.';
}

$csrfToken = generateCsrfToken();
$flash     = getFlash();

ob_start();
?>
<h1 class="page-title">Log in</h1>

<?php if ($error !== null): ?>
<div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card">
<form method="POST" action="/login.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
    </div>

    <button type="submit" class="btn btn-primary">Log in</button>
</form>
</div>

<p class="mt-8">Don't have an account? <a href="/register.php">Register</a></p>
<?php
$content   = ob_get_clean();
$pageTitle = 'Log in';
$hideNav   = true;
include __DIR__ . '/../templates/layout.php';
