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
// Mood trend section
require_once __DIR__ . '/../../src/StandupEmailer.php'; // scoreMoodAnswer (already loads TeamRepository transitively via bootstrap; include is safe)
$questions        = getQuestions($pdo, $teamId);
$hasMoodQuestion  = !empty(array_filter($questions, fn($q) => (int) ($q['is_mood'] ?? 0) === 1));
if ($hasMoodQuestion):
    $dateFrom30 = date('Y-m-d', strtotime('-30 days'));
    $dateTo     = date('Y-m-d');
    $moodTrend  = getMoodTrend($pdo, $teamId, $dateFrom30, $dateTo);
?>
<div class="mt-8 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
  <h2 class="text-lg font-semibold text-gray-800 mb-4">Mood Trend — last 30 days</h2>
  <?php if (empty($moodTrend)): ?>
    <p class="text-sm text-gray-500">No mood data yet. Set a mood question to start tracking.</p>
  <?php else: ?>
  <table class="w-full text-sm text-left">
    <thead><tr class="border-b">
      <th class="py-2 pr-4 font-medium text-gray-600">Date</th>
      <th class="py-2 pr-4 font-medium text-gray-600">Avg</th>
      <th class="py-2 font-medium text-gray-600">Responses</th>
    </tr></thead>
    <tbody>
    <?php foreach ($moodTrend as $row): ?>
      <tr class="border-b border-gray-50">
        <td class="py-1 pr-4 text-gray-700"><?= htmlspecialchars($row['send_date'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="py-1 pr-4 text-gray-800 font-medium"><?= number_format((float) $row['avg_score'], 1) ?></td>
        <td class="py-1 text-gray-600"><?= (int) $row['responses'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="mt-3 text-xs text-gray-400">Scale: 😞 1 &nbsp;|&nbsp; 😐 2 &nbsp;|&nbsp; 😐 3 &nbsp;|&nbsp; 👍 4 &nbsp;|&nbsp; 😀 5</p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') . ' Dashboard';
include __DIR__ . '/../../templates/layout.php';
