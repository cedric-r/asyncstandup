<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/DashboardRepository.php';
require_once __DIR__ . '/../../src/OrgRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$teamId = (int) ($_GET['team_id'] ?? 0);
$team   = getTeamById($pdo, $teamId);
$userId = (int) $_SESSION['user_id'];

if ($team === null || !isTeamMember($pdo, $teamId, $userId)) { forbid(); }

$isOwner = isTeamOwner($pdo, $teamId, $userId);
if (!$isOwner) { forbid(); }

$teamTz = new DateTimeZone($team['timezone']);
$today  = new DateTimeImmutable('today', $teamTz);
$days   = [];
for ($i = 6; $i >= 0; $i--) {
    $days[] = $today->modify("-{$i} days")->format('Y-m-d');
}
$date30Ago = $today->modify('-29 days')->format('Y-m-d');
$todayStr  = $today->format('Y-m-d');

$gridData = getTeamGrid($pdo, $teamId, $days);
$grid     = $gridData['grid'] ?? [];
$names    = $gridData['names'] ?? [];
$stats    = getParticipationStats($pdo, $teamId, $date30Ago, $todayStr);

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'dashboard';

$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

function pctStr(array $stats, int $userId): string {
    if (!isset($stats[$userId]) || $stats[$userId]['sent'] === 0) return '—';
    return round($stats[$userId]['submitted'] / $stats[$userId]['sent'] * 100) . '%';
}

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<div class="flex items-center justify-between mb-4">
  <div>
    <h1 class="text-xl font-bold text-gray-900"><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?> — Dashboard</h1>
    <p class="text-xs text-gray-400">Last 7 days · <?= htmlspecialchars($team['timezone'], ENT_QUOTES, 'UTF-8') ?></p>
  </div>
</div>

<?php if (empty($grid)): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center"><p class="text-gray-500">No data yet.</p></div>
<?php else: ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-x-auto">
<table class="w-full text-sm">
<thead class="bg-gray-50 border-b border-gray-200">
<tr>
  <th class="px-4 py-3 text-left font-medium text-gray-700">Member</th>
  <?php foreach ($days as $day): ?><th class="px-2 py-3 text-center font-medium text-gray-700 text-xs"><?= htmlspecialchars(substr($day, 5), ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?>
  <th class="px-3 py-3 text-center font-medium text-gray-700 text-xs">7d</th>
  <th class="px-3 py-3 text-center font-medium text-gray-700 text-xs">30d</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach ($grid as $memberId => $dates): ?>
<?php
  $s7 = ['sent' => 0, 'submitted' => 0];
  foreach ($dates as $d => $state) {
      if ($state !== 'not_sent') { $s7['sent']++; if ($state === 'submitted') $s7['submitted']++; }
  }
  $pct7  = $s7['sent'] > 0 ? round($s7['submitted'] / $s7['sent'] * 100) . '%' : '—';
  $pct30 = pctStr($stats, $memberId);
?>
<tr class="hover:bg-gray-50">
  <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($names[$memberId] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
  <?php foreach ($dates as $state): ?>
  <td class="px-2 py-3 text-center text-sm">
    <?php if ($state === 'submitted'): ?>
      <span class="text-green-600 font-bold">✓</span>
    <?php elseif ($state === 'sent_not_submitted'): ?>
      <span class="text-red-500 font-bold">✗</span>
    <?php else: ?>
      <span class="text-gray-300 text-xs">N/A</span>
    <?php endif; ?>
  </td>
  <?php endforeach; ?>
  <td class="px-3 py-3 text-center text-xs text-gray-600"><?= htmlspecialchars($pct7, ENT_QUOTES, 'UTF-8') ?></td>
  <td class="px-3 py-3 text-center text-xs text-gray-600"><?= htmlspecialchars($pct30, ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') . ' Dashboard';
include __DIR__ . '/../../templates/layout.php';
