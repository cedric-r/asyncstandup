<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/OrgRepository.php';

startSession();
requireLogin();

$pdo         = getDb($config);
$currentUser = getCurrentUser($pdo);
$flash       = getFlash();
$orgs        = getOrgsForUser($pdo, (int) $_SESSION['user_id']);

ob_start();
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Organisations</h1>
  <a href="/orgs/create.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">+ New Organisation</a>
</div>

<?php if (empty($orgs)): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
  <p class="text-gray-500 mb-4">You are not a member of any organisation yet.</p>
  <a href="/orgs/create.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Create one</a>
</div>
<?php else: ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
<table class="w-full text-sm">
<thead class="bg-gray-50 border-b border-gray-200">
<tr><th class="px-4 py-3 text-left font-medium text-gray-700">Name</th><th class="px-4 py-3 text-left font-medium text-gray-700">Actions</th></tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach ($orgs as $org): ?>
<tr class="hover:bg-gray-50">
  <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></td>
  <td class="px-4 py-3">
    <div class="flex gap-2">
      <a href="/teams/index.php?org_id=<?= (int) $org['id'] ?>" class="text-xs bg-white hover:bg-gray-50 text-gray-700 font-medium py-1.5 px-3 rounded-md border border-gray-300">Teams</a>
      <a href="/orgs/edit.php?id=<?= (int) $org['id'] ?>" class="text-xs bg-white hover:bg-gray-50 text-gray-700 font-medium py-1.5 px-3 rounded-md border border-gray-300">Edit</a>
      <a href="/orgs/delete.php?id=<?= (int) $org['id'] ?>" class="text-xs bg-red-50 hover:bg-red-100 text-red-700 font-medium py-1.5 px-3 rounded-md border border-red-200">Delete</a>
    </div>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = 'Organisations';
include __DIR__ . '/../../templates/layout.php';
