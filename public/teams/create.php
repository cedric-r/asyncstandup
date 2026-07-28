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

$pdo             = getDb($config);
$isPureDeveloper = isPureDeveloper($pdo, (int) $_SESSION['user_id']);
if ($isPureDeveloper) { forbid(); }

$orgId = (int) ($_GET['org_id'] ?? $_POST['org_id'] ?? 0);
$org   = getOrgById($pdo, $orgId);

if ($org === null || !isOrgMember($pdo, $orgId, (int) $_SESSION['user_id'])) { forbid(); }

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
        createTeam($pdo, $orgId, $name, $timezone, $standupTime . ':00', (int) $_SESSION['user_id']);
        setFlash('success', 'Team created.');
        header('Location: /teams/index.php?org_id=' . $orgId);
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$inp         = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

ob_start();
?>
<p class="text-sm text-gray-500 mb-1"><a href="/teams/index.php?org_id=<?= (int) $orgId ?>" class="text-indigo-600 hover:text-indigo-700">← Teams</a></p>
<h1 class="text-2xl font-bold text-gray-900 mb-6">New Team — <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></h1>
<?php foreach ($errors as $e): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
<div class="max-w-lg bg-white rounded-lg shadow-sm border border-gray-200 p-6">
<form method="POST" action="/teams/create.php" class="space-y-4">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Team name</label>
    <input type="text" name="name" required maxlength="255" autofocus class="<?= $inp ?>"
           value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div class="grid grid-cols-2 gap-4">
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
      <select name="timezone" class="<?= $inp ?>">
      <?php foreach ($allTzs as $tz): ?><option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>" <?= ($tz === ($_POST['timezone'] ?? 'UTC')) ? 'selected' : '' ?>><?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Standup time (HH:MM)</label>
      <input type="time" name="standup_time" required class="<?= $inp ?>"
             value="<?= htmlspecialchars($_POST['standup_time'] ?? '09:00', ENT_QUOTES, 'UTF-8') ?>">
    </div>
  </div>
  <div class="flex gap-3">
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Create team</button>
    <a href="/teams/index.php?org_id=<?= (int) $orgId ?>" class="bg-white hover:bg-gray-50 text-gray-700 font-medium py-2 px-4 rounded-lg text-sm border border-gray-300">Cancel</a>
  </div>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'New Team';
include __DIR__ . '/../../templates/layout.php';
