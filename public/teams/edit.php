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

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) {
    forbid();
}

$errors = [];
$allTzs = DateTimeZone::listIdentifiers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $name        = trim($_POST['name'] ?? '');
    $timezone    = trim($_POST['timezone'] ?? '');
    $standupTime = trim($_POST['standup_time'] ?? '');

    if ($name === '') {
        $errors[] = 'Team name is required.';
    }

    if (!in_array($timezone, $allTzs, true)) {
        $errors[] = 'Invalid timezone.';
    }

    if (!preg_match('/^\d{2}:\d{2}$/', $standupTime)) {
        $errors[] = 'Standup time must be in HH:MM format.';
    }

    if (empty($errors)) {
        updateTeam($pdo, $teamId, $name, $timezone, $standupTime . ':00');
        setFlash('success', 'Team settings updated.');
        header('Location: /teams/edit.php?id=' . $teamId);
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'edit';

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<h1 class="page-title">Team Settings — <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/teams/edit.php?id=<?= (int) $teamId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group">
        <label for="name">Team name</label>
        <input type="text" id="name" name="name" required maxlength="255"
               value="<?= htmlspecialchars($_POST['name'] ?? $team['name'], ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="timezone">Timezone</label>
            <select id="timezone" name="timezone">
            <?php foreach ($allTzs as $tz): ?>
                <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($tz === ($_POST['timezone'] ?? $team['timezone'])) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="standup_time">Standup time (HH:MM)</label>
            <input type="time" id="standup_time" name="standup_time" required
                   value="<?= htmlspecialchars($_POST['standup_time'] ?? substr($team['standup_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
    <a href="/teams/index.php?org_id=<?= (int) $team['org_id'] ?>" class="btn btn-secondary">Back</a>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Team Settings';
include __DIR__ . '/../../templates/layout.php';
