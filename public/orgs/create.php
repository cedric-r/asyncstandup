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
    if ($name === '') { $errors[] = 'Organisation name is required.'; }
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
$inp         = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

ob_start();
?>
<p class="text-sm text-gray-500 mb-1"><a href="/orgs/index.php" class="text-indigo-600 hover:text-indigo-700">← Organisations</a></p>
<h1 class="text-2xl font-bold text-gray-900 mb-6">New Organisation</h1>
<?php foreach ($errors as $e): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<div class="max-w-md bg-white rounded-lg shadow-sm border border-gray-200 p-6">
<form method="POST" action="/orgs/create.php" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Organisation name</label>
    <input type="text" name="name" required maxlength="255" autofocus class="<?= $inp ?>"
           value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="flex gap-3">
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Create</button>
    <a href="/orgs/index.php" class="bg-white hover:bg-gray-50 text-gray-700 font-medium py-2 px-4 rounded-lg text-sm border border-gray-300">Cancel</a>
  </div>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'New Organisation';
include __DIR__ . '/../../templates/layout.php';
