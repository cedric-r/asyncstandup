<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/TeamRepository.php';

startSession();
$pdo = getDb($config);
requireAdmin($pdo);

$teams = getTeamsAdminOverview($pdo);

$badges = [
    'email'         => '<span class="bg-gray-100 text-gray-600 text-xs px-2 py-0.5 rounded-full">email</span>',
    'teams-summary' => '<span class="bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full">Teams Summary</span>',
    'teams'         => '<span class="bg-green-100 text-green-700 text-xs px-2 py-0.5 rounded-full">Teams DM</span>',
];

ob_start();
?>
<div class="max-w-5xl mx-auto px-4 py-8">
  <div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Teams Integration — All Teams</h1>
    <a href="/admin/users.php" class="text-sm text-gray-500 hover:underline">← Admin Users</a>
  </div>

  <?php if (empty($teams)): ?>
    <p class="text-gray-500">No teams found.</p>
  <?php else: ?>
  <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
    <table class="min-w-full divide-y divide-gray-200 text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Team</th>
          <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Org</th>
          <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Mode</th>
          <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Webhook URL</th>
          <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Last Error</th>
          <th class="px-4 py-3"></th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php foreach ($teams as $team): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 font-medium text-gray-900">
            <?= htmlspecialchars((string) $team['team_name'], ENT_QUOTES) ?>
          </td>
          <td class="px-4 py-3 text-gray-600">
            <?= htmlspecialchars((string) $team['org_name'], ENT_QUOTES) ?>
          </td>
          <td class="px-4 py-3">
            <?= $badges[$team['notification_channel'] ?? 'email'] ?? $badges['email'] ?>
          </td>
          <td class="px-4 py-3 text-gray-500 font-mono text-xs">
            <?php if (!empty($team['teams_webhook_url'])): ?>
              <?= htmlspecialchars(substr((string) $team['teams_webhook_url'], 0, 60), ENT_QUOTES) ?>
              <?php if (strlen((string) $team['teams_webhook_url']) > 60): ?>…<?php endif; ?>
            <?php else: ?>
              <span class="text-gray-300">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3">
            <?php if (!empty($team['teams_last_error'])): ?>
              <span class="text-red-600 text-xs">
                <?= htmlspecialchars(substr((string) $team['teams_last_error'], 0, 80), ENT_QUOTES) ?>
                <span class="text-gray-400 ml-1">(<?= htmlspecialchars(substr((string) ($team['teams_last_error_at'] ?? ''), 0, 10), ENT_QUOTES) ?>)</span>
              </span>
            <?php else: ?>
              <span class="text-gray-300 text-xs">—</span>
            <?php endif; ?>
          </td>
          <td class="px-4 py-3 text-right">
            <a href="/teams/edit.php?id=<?= (int) $team['id'] ?>"
               class="text-indigo-600 hover:underline text-xs">Edit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Admin — Teams Integration';
include __DIR__ . '/../../templates/layout.php';
