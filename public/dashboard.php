<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/DashboardRepository.php';

startSession();
requireLogin();

$pdo         = getDb($config);
$currentUser = getCurrentUser($pdo);
$flash       = getFlash();
$pendingTokens = $currentUser !== null
    ? getPendingTokensForUser($pdo, (int) $currentUser['id'])
    : [];
$teams = getTeamsForUser($pdo, (int) $_SESSION['user_id']);

$byOrg = [];
foreach ($teams as $team) {
    $byOrg[$team['org_name']][] = $team;
}

ob_start();
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
  <a href="/orgs/index.php" class="text-sm text-indigo-600 hover:text-indigo-700">Manage Organisations →</a>
</div>

<?php if (!empty($pendingTokens)): ?>
<div class="pending-standups bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
  <h2 class="text-sm font-semibold text-amber-800 mb-3">⏳ Pending standups</h2>
  <ul class="space-y-2">
  <?php foreach ($pendingTokens as $pt): ?>
    <li>
      <a href="/submit.php?token=<?= htmlspecialchars($pt['token'], ENT_QUOTES, 'UTF-8') ?>"
         class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">
        Submit standup for <?= htmlspecialchars($pt['team_name'], ENT_QUOTES, 'UTF-8') ?>
      </a>
      <span class="text-xs text-gray-400 ml-2">(<?= htmlspecialchars($pt['send_date'], ENT_QUOTES, 'UTF-8') ?>)</span>
    </li>
  <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (empty($teams)): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center">
  <p class="text-gray-500 mb-4">You are not a member of any team yet.</p>
  <a href="/orgs/index.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
    Create an organisation
  </a>
</div>
<?php else: ?>

<?php foreach ($byOrg as $orgName => $orgTeams): ?>
<div class="mb-8">
  <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3"><?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?></h2>
  <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
  <?php foreach ($orgTeams as $team): ?>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
      <p class="font-semibold text-gray-900 mb-1"><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?>
        <?php if (($team['status'] ?? 'active') === 'suspended'): ?>
          <span class="inline-block text-xs font-medium bg-amber-100 text-amber-700 px-2 py-0.5 rounded ml-1">[Suspended]</span>
        <?php endif; ?>
      </p>
      <p class="text-xs text-gray-400 mb-4"><?= htmlspecialchars(substr($team['standup_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($team['timezone'], ENT_QUOTES, 'UTF-8') ?></p>
      <div class="flex flex-wrap gap-1 mb-4">
        <?php if ($team['is_owner']): ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">Owner</span><?php endif; ?>
        <?php if ($team['is_developer']): ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Developer</span><?php endif; ?>
        <?php if ($team['is_recipient']): ?><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">Recipient</span><?php endif; ?>
      </div>
      <div class="flex gap-2">
        <?php if ($team['is_owner']): ?>
        <a href="/teams/dashboard.php?team_id=<?= (int) $team['id'] ?>" class="text-xs bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-1.5 px-3 rounded-md">Dashboard</a>
        <a href="/teams/edit.php?id=<?= (int) $team['id'] ?>" class="text-xs bg-white hover:bg-gray-50 text-gray-700 font-medium py-1.5 px-3 rounded-md border border-gray-300">Settings</a>
        <?php endif; ?>
        <?php if ($team['is_developer']): ?>
        <a href="/teams/responses.php?team_id=<?= (int) $team['id'] ?>" class="text-xs bg-white hover:bg-gray-50 text-gray-700 font-medium py-1.5 px-3 rounded-md border border-gray-300">History</a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = 'Dashboard';
include __DIR__ . '/../templates/layout.php';
