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
$teamId = (int) ($_GET['id'] ?? 0);
$team   = getTeamById($pdo, $teamId);

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) { forbid(); }

$errors = [];
$allTzs = DateTimeZone::listIdentifiers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $timezone    = trim($_POST['timezone'] ?? '');
    $standupTime = trim($_POST['standup_time'] ?? '');
    if ($name === '') { $errors[] = 'Team name is required.'; }
    if (!in_array($timezone, $allTzs, true)) { $errors[] = 'Invalid timezone.'; }
    if (!preg_match('/^\d{2}:\d{2}$/', $standupTime)) { $errors[] = 'Standup time must be HH:MM.'; }
    if (empty($errors)) {
        updateTeam($pdo, $teamId, $name, $timezone, $standupTime . ':00');
        setFlash('success', 'Team settings updated.');
        header('Location: /teams/edit.php?id=' . $teamId);
        exit;
    }
}

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'edit';
$isOwner  = true;

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$inp         = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<h1 class="text-xl font-bold text-gray-900 mb-6">Team Settings</h1>
<?php foreach ($errors as $e): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<div class="max-w-lg bg-white rounded-lg shadow-sm border border-gray-200 p-6">
<form method="POST" action="/teams/edit.php?id=<?= (int) $teamId ?>" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Team name</label>
    <input type="text" name="name" required maxlength="255" class="<?= $inp ?>"
           value="<?= htmlspecialchars($_POST['name'] ?? $team['name'], ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="grid grid-cols-2 gap-4">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
      <select name="timezone" class="<?= $inp ?>">
      <?php foreach ($allTzs as $tz): ?><option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= ($tz === ($_POST['timezone'] ?? $team['timezone'])) ? 'selected' : '' ?>><?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Standup time</label>
      <input type="time" name="standup_time" required class="<?= $inp ?>"
             value="<?= htmlspecialchars($_POST['standup_time'] ?? substr($team['standup_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>">
    </div>
  </div>
  <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Save</button>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Team Settings';
include __DIR__ . '/../../templates/layout.php';
