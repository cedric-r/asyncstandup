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
$teams       = getTeamsForUser($pdo, (int) $_SESSION['user_id']);

// Group by org.
$byOrg = [];

foreach ($teams as $team) {
    $orgName = $team['org_name'];

    if (!isset($byOrg[$orgName])) {
        $byOrg[$orgName] = [];
    }

    $byOrg[$orgName][] = $team;
}

ob_start();
?>
<h1 class="page-title">Dashboard</h1>

<?php if (empty($teams)): ?>
<div class="card">
<p>You are not a member of any team yet.</p>
<a href="/orgs/index.php" class="btn btn-primary mt-16">Manage Organisations</a>
</div>
<?php else: ?>

<?php foreach ($byOrg as $orgName => $orgTeams): ?>
<h2 style="margin-bottom:8px"><?= htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') ?></h2>

<div class="card" style="margin-bottom:24px">
<table>
<thead><tr><th>Team</th><th>Standup time</th><th>Your roles</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($orgTeams as $team): ?>
<tr>
    <td><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars(substr($team['standup_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($team['timezone'], ENT_QUOTES, 'UTF-8') ?></td>
    <td>
        <?php if ($team['is_owner']): ?><span class="badge badge-owner">Owner</span><?php endif; ?>
        <?php if ($team['is_developer']): ?><span class="badge badge-dev">Developer</span><?php endif; ?>
        <?php if ($team['is_recipient']): ?><span class="badge badge-recip">Recipient</span><?php endif; ?>
    </td>
    <td class="actions">
        <a href="/teams/dashboard.php?team_id=<?= (int) $team['id'] ?>" class="btn btn-primary btn-sm">View Dashboard</a>
        <?php if ($team['is_owner']): ?>
        <a href="/teams/edit.php?id=<?= (int) $team['id'] ?>" class="btn btn-secondary btn-sm">Settings</a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endforeach; ?>

<?php endif; ?>

<p><a href="/orgs/index.php">Manage Organisations →</a></p>
<?php
$content   = ob_get_clean();
$pageTitle = 'Dashboard';
include __DIR__ . '/../templates/layout.php';
