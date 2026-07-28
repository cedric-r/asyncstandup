<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/OrgRepository.php';
require_once __DIR__ . '/../../src/TeamRepository.php';

startSession();
requireLogin();

$pdo             = getDb($config);
$isPureDeveloper = isPureDeveloper($pdo, (int) $_SESSION['user_id']);
$orgId           = (int) ($_GET['org_id'] ?? 0);
$org             = getOrgById($pdo, $orgId);

if ($org === null || !isOrgMember($pdo, $orgId, (int) $_SESSION['user_id'])) { forbid(); }

$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$teams       = getTeamsForOrg($pdo, $orgId, (int) $_SESSION['user_id']);

ob_start();
?>
<p class="text-sm text-gray-500 mb-1"><a href="/orgs/index.php" class="text-indigo-600 hover:text-indigo-700">← Organisations</a></p>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Teams — <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></h1>
  <?php if (!$isPureDeveloper): ?>
  <a href="/teams/create.php?org_id=<?= (int) $orgId ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">+ New Team</a>
  <?php endif; ?>
</div>

<?php if (empty($teams)): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center"><p class="text-gray-500">No teams yet.</p></div>
<?php else: ?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
<?php foreach ($teams as $team): ?>
  <?php $isTOwner = isTeamOwner($pdo, (int) $team['id'], (int) $_SESSION['user_id']); ?>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
    <p class="font-semibold text-gray-900 mb-1"><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></p>
    <p class="text-xs text-gray-400 mb-4"><?= htmlspecialchars(substr($team['standup_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($team['timezone'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($isTOwner): ?>
    <div class="flex flex-wrap gap-1.5">
      <a href="/teams/dashboard.php?team_id=<?= (int) $team['id'] ?>" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-1 px-2.5 rounded">Dashboard</a>
      <a href="/teams/members.php?team_id=<?= (int) $team['id'] ?>" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded">Members</a>
      <a href="/teams/questions.php?team_id=<?= (int) $team['id'] ?>" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded">Questions</a>
      <a href="/teams/recipients.php?team_id=<?= (int) $team['id'] ?>" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded">Recipients</a>
      <a href="/teams/edit.php?id=<?= (int) $team['id'] ?>" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded">Settings</a>
      <a href="/teams/responses.php?team_id=<?= (int) $team['id'] ?>" class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded">Responses</a>
      <a href="/teams/delete.php?id=<?= (int) $team['id'] ?>" class="text-xs bg-red-50 hover:bg-red-100 text-red-700 font-medium py-1 px-2.5 rounded border border-red-200">Delete</a>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = 'Teams';
include __DIR__ . '/../../templates/layout.php';
