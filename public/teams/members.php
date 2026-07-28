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

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) { forbid(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);
    if ($action === 'update_roles') {
        updateMemberRoles($pdo, $teamId, $userId,
            isset($_POST['is_owner']) ? 1 : 0,
            isset($_POST['is_developer']) ? 1 : 0,
            isset($_POST['is_recipient']) ? 1 : 0);
        setFlash('success', 'Roles updated.');
    } elseif ($action === 'remove') {
        removeMember($pdo, $teamId, $userId);
        setFlash('success', 'Member removed.');
    }
    header('Location: /teams/members.php?team_id=' . $teamId);
    exit;
}

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'members';
$isOwner  = true;

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$members     = getTeamMembers($pdo, $teamId);

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-xl font-bold text-gray-900">Members</h1>
  <a href="/invitations/send.php?team_id=<?= (int) $teamId ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">+ Invite member</a>
</div>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
<table class="w-full text-sm">
<thead class="bg-gray-50 border-b border-gray-200">
<tr><th class="px-4 py-3 text-left font-medium text-gray-700">Member</th><th class="px-4 py-3 text-center font-medium text-gray-700">Owner</th><th class="px-4 py-3 text-center font-medium text-gray-700">Developer</th><th class="px-4 py-3 text-center font-medium text-gray-700">Recipient</th><th class="px-4 py-3 text-left font-medium text-gray-700">Actions</th></tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach ($members as $m): ?>
<tr class="hover:bg-gray-50">
  <td class="px-4 py-3">
    <p class="font-medium text-gray-900"><?= htmlspecialchars($m['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    <p class="text-xs text-gray-400"><?= htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8') ?></p>
  </td>
  <?php foreach (['is_owner','is_developer','is_recipient'] as $role): ?>
  <td class="px-4 py-3 text-center">
    <form method="POST" action="/teams/members.php?team_id=<?= (int) $teamId ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="update_roles">
      <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
      <input type="hidden" name="is_owner" value="<?= (int) $m['is_owner'] ?>">
      <input type="hidden" name="is_developer" value="<?= (int) $m['is_developer'] ?>">
      <input type="hidden" name="is_recipient" value="<?= (int) $m['is_recipient'] ?>">
      <input type="checkbox" name="<?= $role ?>" value="1" <?= $m[$role] ? 'checked' : '' ?> onchange="this.form.submit()" class="w-4 h-4 accent-indigo-600">
    </form>
  </td>
  <?php endforeach; ?>
  <td class="px-4 py-3">
    <div class="flex gap-2">
      <?php if ($isOwner && $m['is_developer']): ?>
      <a href="/teams/responses.php?team_id=<?= (int) $teamId ?>&member_id=<?= (int) $m['id'] ?>" class="text-xs text-indigo-600 hover:text-indigo-700">Responses</a>
      <?php endif; ?>
      <?php if ((int) $m['id'] !== (int) $_SESSION['user_id']): ?>
      <form method="POST" action="/teams/members.php?team_id=<?= (int) $teamId ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="user_id" value="<?= (int) $m['id'] ?>">
        <button type="submit" onclick="return confirm('Remove?')" class="text-xs text-red-600 hover:text-red-700">Remove</button>
      </form>
      <?php endif; ?>
    </div>
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
