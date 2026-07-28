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

if ($org === null || !isOrgMember($pdo, $orgId, (int) $_SESSION['user_id'])) { forbid(); }
if (!isOrgCreator($pdo, $orgId, (int) $_SESSION['user_id'])) { forbid(); }

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
<p class="text-sm text-gray-500 mb-1"><a href="/orgs/index.php" class="text-indigo-600 hover:text-indigo-700">← Organisations</a></p>
<h1 class="text-2xl font-bold text-gray-900 mb-6">Delete Organisation</h1>
<div class="max-w-md bg-white rounded-lg shadow-sm border border-red-200 p-6">
  <p class="text-gray-900 mb-2">Are you sure you want to delete <strong><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></strong>?</p>
  <p class="text-sm text-gray-500 mb-6">This will permanently delete all teams, members, questions, submissions, and history.</p>
  <form method="POST" action="/orgs/delete.php?id=<?= (int) $orgId ?>" class="flex gap-3">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Yes, delete</button>
    <a href="/orgs/index.php" class="bg-white hover:bg-gray-50 text-gray-700 font-medium py-2 px-4 rounded-lg text-sm border border-gray-300">Cancel</a>
  </form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Delete Organisation';
include __DIR__ . '/../../templates/layout.php';
