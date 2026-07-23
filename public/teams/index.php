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

$pdo   = getDb($config);
$orgId = (int) ($_GET['org_id'] ?? 0);
$org   = getOrgById($pdo, $orgId);

if ($org === null || !isOrgMember($pdo, $orgId, (int) $_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$teams       = getTeamsForOrg($pdo, $orgId, (int) $_SESSION['user_id']);

ob_start();
?>
<h1 class="page-title">Teams — <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></h1>
<a href="/teams/create.php?org_id=<?= (int) $orgId ?>" class="btn btn-primary">+ New Team</a>

<div class="mt-16">
<?php if (empty($teams)): ?>
<p class="text-muted">No teams yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Name</th><th>Standup time</th><th>Timezone</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($teams as $team): ?>
<tr>
    <td><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars(substr($team['standup_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($team['timezone'], ENT_QUOTES, 'UTF-8') ?></td>
    <td class="actions">
        <?php $isTOwner = isTeamOwner($pdo, (int) $team['id'], (int) $_SESSION['user_id']); ?>
        <?php if ($isTOwner): ?>
        <a href="/teams/dashboard.php?team_id=<?= (int) $team['id'] ?>" class="btn btn-secondary btn-sm">Dashboard</a>
        <a href="/teams/members.php?team_id=<?= (int) $team['id'] ?>" class="btn btn-secondary btn-sm">Members</a>
        <a href="/teams/questions.php?team_id=<?= (int) $team['id'] ?>" class="btn btn-secondary btn-sm">Questions</a>
        <a href="/teams/recipients.php?team_id=<?= (int) $team['id'] ?>" class="btn btn-secondary btn-sm">Recipients</a>
        <a href="/teams/edit.php?id=<?= (int) $team['id'] ?>" class="btn btn-secondary btn-sm">Settings</a>
        <a href="/teams/responses.php?team_id=<?= (int) $team['id'] ?>" class="btn btn-secondary btn-sm">Responses</a>
        <a href="/teams/delete.php?id=<?= (int) $team['id'] ?>" class="btn btn-danger btn-sm">Delete</a>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Teams';
include __DIR__ . '/../../templates/layout.php';
