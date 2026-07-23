<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/OrgRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $errors[] = 'Organisation name is required.';
    }

    if (empty($errors)) {
        createOrg($pdo, $name, (int) $_SESSION['user_id']);
        setFlash('success', 'Organisation created.');
        header('Location: /orgs/index.php');
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<h1 class="page-title">New Organisation</h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/orgs/create.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="form-group">
        <label for="name">Organisation name</label>
        <input type="text" id="name" name="name" required maxlength="255"
               value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <button type="submit" class="btn btn-primary">Create</button>
    <a href="/orgs/index.php" class="btn btn-secondary">Cancel</a>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'New Organisation';
include __DIR__ . '/../../templates/layout.php';
