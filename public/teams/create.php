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
$orgId = (int) ($_GET['org_id'] ?? $_POST['org_id'] ?? 0);
$org   = getOrgById($pdo, $orgId);

if ($org === null || !isOrgMember($pdo, $orgId, (int) $_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$errors  = [];
$allTzs  = DateTimeZone::listIdentifiers();

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
        $teamId = createTeam($pdo, $orgId, $name, $timezone, $standupTime . ':00', (int) $_SESSION['user_id']);
        setFlash('success', 'Team created.');
        header('Location: /teams/index.php?org_id=' . $orgId);
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<h1 class="page-title">New Team — <?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/teams/create.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="org_id" value="<?= (int) $orgId ?>">

    <div class="form-group">
        <label for="name">Team name</label>
        <input type="text" id="name" name="name" required maxlength="255"
               value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="timezone">Timezone</label>
            <select id="timezone" name="timezone">
            <?php foreach ($allTzs as $tz): ?>
                <option value="<?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($tz === ($_POST['timezone'] ?? 'UTC')) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($tz, ENT_QUOTES, 'UTF-8') ?>
                </option>
            <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="standup_time">Standup time (HH:MM)</label>
            <input type="time" id="standup_time" name="standup_time" required
                   value="<?= htmlspecialchars($_POST['standup_time'] ?? '09:00', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>

    <button type="submit" class="btn btn-primary">Create team</button>
    <a href="/teams/index.php?org_id=<?= (int) $orgId ?>" class="btn btn-secondary">Cancel</a>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'New Team';
include __DIR__ . '/../../templates/layout.php';
