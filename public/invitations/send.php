<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/Mailer.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/InvitationRepository.php';
require_once __DIR__ . '/../../src/View.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$teamId = (int) ($_GET['team_id'] ?? 0);
$team   = getTeamById($pdo, $teamId);

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) { forbid(); }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $email    = mb_strtolower(trim($_POST['email'] ?? ''));
    $roles    = array_intersect($_POST['roles'] ?? [], ['owner','developer','recipient']);
    $rolesStr = implode(',', $roles) ?: 'developer';
    $currentUser = getCurrentUser($pdo);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } elseif (isAlreadyTeamMember($pdo, $teamId, $email)) {
        $errors[] = 'That user is already a member of this team.';
    } else {
        $token   = createInvitation($pdo, $teamId, $email, $rolesStr, (int) $_SESSION['user_id']);
        $acceptUrl = rtrim($config['app_url'], '/') . '/invitations/accept.php?token=' . urlencode($token);
        $orgStmt = $pdo->prepare('SELECT name FROM organisations WHERE id = ?');
        $orgStmt->execute([$team['org_id']]);
        $orgName = (string) ($orgStmt->fetchColumn() ?: '');
        $rolesReadable = implode(', ', array_map('ucfirst', $roles)) ?: 'Developer';
        $body = renderEmailTemplate(__DIR__ . '/../../templates/email/invitation.php', [
            'team_name'   => $team['name'],
            'org_name'    => $orgName,
            'inviter_name' => $currentUser['display_name'] ?? $currentUser['email'],
            'accept_url'  => $acceptUrl,
            'expires_days' => 7,
            'roles'       => $rolesReadable,
        ]);
        try {
            sendMail($config, $email, $email, 'You have been invited to ' . $team['name'], $body);
            setFlash('success', 'Invitation sent to ' . $email);
        } catch (RuntimeException $e) {
            setFlash('error', 'Invitation created but email could not be sent: ' . $e->getMessage());
        }
        header('Location: /teams/members.php?team_id=' . $teamId);
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$inp         = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

ob_start();
?>
<p class="text-sm text-gray-500 mb-1"><a href="/teams/members.php?team_id=<?= (int) $teamId ?>" class="text-indigo-600 hover:text-indigo-700">← Members</a></p>
<h1 class="text-2xl font-bold text-gray-900 mb-6">Invite to <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></h1>
<?php foreach ($errors as $e): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<div class="max-w-md bg-white rounded-lg shadow-sm border border-gray-200 p-6">
<form method="POST" action="/invitations/send.php?team_id=<?= (int) $teamId ?>" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Email address</label>
    <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="<?= $inp ?>">
  </div>
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-2">Roles</label>
    <div class="space-y-1 text-sm">
      <label class="flex items-center gap-2"><input type="checkbox" name="roles[]" value="owner" class="accent-indigo-600"> Owner</label>
      <label class="flex items-center gap-2"><input type="checkbox" name="roles[]" value="developer" checked class="accent-indigo-600"> Developer</label>
      <label class="flex items-center gap-2"><input type="checkbox" name="roles[]" value="recipient" class="accent-indigo-600"> Recipient</label>
    </div>
  </div>
  <div class="flex gap-3">
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Send invitation</button>
    <a href="/teams/members.php?team_id=<?= (int) $teamId ?>" class="bg-white hover:bg-gray-50 text-gray-700 font-medium py-2 px-4 rounded-lg text-sm border border-gray-300">Cancel</a>
  </div>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Send Invitation';
include __DIR__ . '/../../templates/layout.php';
