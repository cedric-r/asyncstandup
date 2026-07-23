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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    deleteOrg($pdo, $orgId);
    setFlash('success', 'Organisation deleted.');
    header('Location: /orgs/index.php');
    exit;
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<h1 class="page-title">Delete Organisation</h1>

<div class="card">
<p>Are you sure you want to delete <strong><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></strong>?</p>
<p class="text-muted mt-8">This will permanently delete all teams, members, questions, submissions, and history.</p>

<form method="POST" action="/orgs/delete.php?id=<?= (int) $orgId ?>" class="mt-16">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="btn btn-danger">Yes, delete</button>
    <a href="/orgs/index.php" class="btn btn-secondary">Cancel</a>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Delete Organisation';
include __DIR__ . '/../../templates/layout.php';
