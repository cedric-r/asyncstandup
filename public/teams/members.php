<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/OrgRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$teamId = (int) ($_GET['team_id'] ?? 0);
$team   = getTeamById($pdo, $teamId);

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'update_roles') {
        updateMemberRoles(
            $pdo,
            $teamId,
            $userId,
            isset($_POST['is_owner']) ? 1 : 0,
            isset($_POST['is_developer']) ? 1 : 0,
            isset($_POST['is_recipient']) ? 1 : 0,
        );
        setFlash('success', 'Roles updated.');
    } elseif ($action === 'remove') {
        removeMember($pdo, $teamId, $userId);
        setFlash('success', 'Member removed.');
    }

    header('Location: /teams/members.php?team_id=' . $teamId);
    exit;
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$members     = getTeamMembers($pdo, $teamId);

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'members';

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<h1 class="page-title">Members — <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></h1>
<a href="/invitations/send.php?team_id=<?= (int) $teamId ?>" class="btn btn-primary">+ Invite member</a>

<div class="mt-16">
<table>
<thead>
<tr><th>Member</th><th>Owner</th><th>Developer</th><th>Recipient</th><th>Actions</th></tr>
</thead>
<tbody>
<?php foreach ($members as $m): ?>
<tr>
    <td>
        <?= htmlspecialchars($m['display_name'] ?? $m['email'], ENT_QUOTES, 'UTF-8') ?>
        <span class="text-muted"><?= htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8') ?></span>
    </td>
    <td>
        <form method="POST" action="/teams/members.php?team_id=<?= (int) $teamId ?>" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_roles">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <input type="checkbox" name="is_owner" value="1" <?= $m['is_owner'] ? 'checked' : '' ?> onchange="this.form.submit()">
            <input type="hidden" name="is_developer" value="<?= (int) $m['is_developer'] ?>">
            <input type="hidden" name="is_recipient" value="<?= (int) $m['is_recipient'] ?>">
        </form>
    </td>
    <td>
        <form method="POST" action="/teams/members.php?team_id=<?= (int) $teamId ?>" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_roles">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <input type="hidden" name="is_owner" value="<?= (int) $m['is_owner'] ?>">
            <input type="checkbox" name="is_developer" value="1" <?= $m['is_developer'] ? 'checked' : '' ?> onchange="this.form.submit()">
            <input type="hidden" name="is_recipient" value="<?= (int) $m['is_recipient'] ?>">
        </form>
    </td>
    <td>
        <form method="POST" action="/teams/members.php?team_id=<?= (int) $teamId ?>" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update_roles">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <input type="hidden" name="is_owner" value="<?= (int) $m['is_owner'] ?>">
            <input type="hidden" name="is_developer" value="<?= (int) $m['is_developer'] ?>">
            <input type="checkbox" name="is_recipient" value="1" <?= $m['is_recipient'] ? 'checked' : '' ?> onchange="this.form.submit()">
        </form>
    </td>
    <td>
        <?php if ($isOwner && $m['is_developer']): ?>
        <a href="/teams/responses.php?team_id=<?= (int) $teamId ?>&amp;member_id=<?= (int) $m['id'] ?>" class="btn btn-secondary btn-sm">View responses</a>
        <?php endif; ?>
        <?php if ((int) $m['id'] !== (int) $_SESSION['user_id']): ?>
        <form method="POST" action="/teams/members.php?team_id=<?= (int) $teamId ?>" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Remove this member?')">Remove</button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Team Members';
include __DIR__ . '/../../templates/layout.php';
