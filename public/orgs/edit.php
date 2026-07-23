<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/OrgRepository.php';

startSession();
requireLogin();

$pdo   = getDb($config);
$orgId = (int) ($_GET['id'] ?? 0);
$org   = getOrgById($pdo, $orgId);

if ($org === null || !isOrgMember($pdo, $orgId, (int) $_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $errors[] = 'Organisation name is required.';
    }

    if (empty($errors)) {
        updateOrg($pdo, $orgId, $name);
        setFlash('success', 'Organisation updated.');
        header('Location: /orgs/index.php');
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<h1 class="page-title">Edit Organisation</h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/orgs/edit.php?id=<?= (int) $orgId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div class="form-group">
        <label for="name">Organisation name</label>
        <input type="text" id="name" name="name" required maxlength="255"
               value="<?= htmlspecialchars($_POST['name'] ?? $org['name'], ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
    <a href="/orgs/index.php" class="btn btn-secondary">Cancel</a>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Edit Organisation';
include __DIR__ . '/../../templates/layout.php';
