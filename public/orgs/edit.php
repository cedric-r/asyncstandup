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

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { $errors[] = 'Organisation name is required.'; }
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
$inp         = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

ob_start();
?>
<p class="text-sm text-gray-500 mb-1"><a href="/orgs/index.php" class="text-indigo-600 hover:text-indigo-700">← Organisations</a></p>
<h1 class="text-2xl font-bold text-gray-900 mb-6">Edit Organisation</h1>
<?php foreach ($errors as $e): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<div class="max-w-md bg-white rounded-lg shadow-sm border border-gray-200 p-6">
<form method="POST" action="/orgs/edit.php?id=<?= (int) $orgId ?>" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Organisation name</label>
    <input type="text" name="name" required maxlength="255" class="<?= $inp ?>"
           value="<?= htmlspecialchars($_POST['name'] ?? $org['name'], ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="flex gap-3">
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Save</button>
    <a href="/orgs/index.php" class="bg-white hover:bg-gray-50 text-gray-700 font-medium py-2 px-4 rounded-lg text-sm border border-gray-300">Cancel</a>
  </div>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Edit Organisation';
include __DIR__ . '/../../templates/layout.php';
