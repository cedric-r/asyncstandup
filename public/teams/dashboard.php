<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/DashboardRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$teamId = (int) ($_GET['team_id'] ?? 0);
$team   = getTeamById($pdo, $teamId);
$userId = (int) $_SESSION['user_id'];

if ($team === null || !isTeamMember($pdo, $teamId, $userId)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$isOwner = isTeamOwner($pdo, $teamId, $userId);

// 7-day date range in team timezone.
$teamTz = new DateTimeZone($team['timezone']);
$today  = new DateTimeImmutable('today', $teamTz);
$days   = [];

for ($i = 6; $i >= 0; $i--) {
    $days[] = $today->modify("-{$i} days")->format('Y-m-d');
}

// 30-day range for participation stats.
$date30Ago = $today->modify('-29 days')->format('Y-m-d');
$todayStr  = $today->format('Y-m-d');

$gridData = getTeamGrid($pdo, $teamId, $days);
$grid     = $gridData['grid'] ?? [];
$names    = $gridData['names'] ?? [];
$stats    = getParticipationStats($pdo, $teamId, $date30Ago, $todayStr);

// Non-owners only see their own row.
if (!$isOwner) {
    $grid  = isset($grid[$userId]) ? [$userId => $grid[$userId]] : [];
    $names = isset($names[$userId]) ? [$userId => $names[$userId]] : [];
}

$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

/**
 * Render a participation % string.
 */
function pctStr(array $stats, int $userId): string
{
    if (!isset($stats[$userId]) || $stats[$userId]['sent'] === 0) {
        return '—';
    }

    $pct = round($stats[$userId]['submitted'] / $stats[$userId]['sent'] * 100);

    return $pct . '%';
}

ob_start();
?>
<h1 class="page-title"><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?> Dashboard</h1>
<p class="text-muted">Last 7 days · <?= htmlspecialchars($team['timezone'], ENT_QUOTES, 'UTF-8') ?></p>

<?php if (empty($grid)): ?>
<div class="card mt-16"><p>No data yet.</p></div>
<?php else: ?>

<div class="card mt-16" style="overflow-x:auto">
<table>
<thead>
<tr>
    <th>Member</th>
    <?php foreach ($days as $day): ?>
    <th style="font-size:11px"><?= htmlspecialchars(substr($day, 5), ENT_QUOTES, 'UTF-8') ?></th>
    <?php endforeach; ?>
    <th>7-day</th>
    <th>30-day</th>
</tr>
</thead>
<tbody>
<?php foreach ($grid as $memberId => $dates): ?>
<?php
    $s7  = ['sent' => 0, 'submitted' => 0];
    foreach ($dates as $d => $state) {
        if ($state !== 'not_sent') {
            $s7['sent']++;
            if ($state === 'submitted') {
                $s7['submitted']++;
            }
        }
    }
    $pct7  = $s7['sent'] > 0 ? round($s7['submitted'] / $s7['sent'] * 100) . '%' : '—';
    $pct30 = pctStr($stats, $memberId);
?>
<tr>
    <td><?= htmlspecialchars($names[$memberId] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
    <?php foreach ($dates as $state): ?>
    <td class="<?= match($state) {
        'submitted'         => 'cell-submitted',
        'sent_not_submitted' => 'cell-missed',
        default             => 'cell-na',
    } ?>">
        <?= match($state) {
            'submitted'         => '✓',
            'sent_not_submitted' => '✗',
            default             => 'N/A',
        } ?>
    </td>
    <?php endforeach; ?>
    <td><?= htmlspecialchars($pct7, ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($pct30, ENT_QUOTES, 'UTF-8') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php endif; ?>

<div class="mt-16">
    <a href="/dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    <?php if ($isOwner): ?>
    <a href="/teams/members.php?team_id=<?= (int) $teamId ?>" class="btn btn-secondary">Manage Members</a>
    <?php endif; ?>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') . ' Dashboard';
include __DIR__ . '/../../templates/layout.php';
