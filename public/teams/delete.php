<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$teamId = (int) ($_GET['id'] ?? 0);
$team   = getTeamById($pdo, $teamId);

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $orgId = (int) $team['org_id'];
    deleteTeam($pdo, $teamId);
    setFlash('success', 'Team deleted.');
    header('Location: /teams/index.php?org_id=' . $orgId);
    exit;
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<h1 class="page-title">Delete Team</h1>

<div class="card">
<p>Are you sure you want to delete <strong><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></strong>?</p>
<p class="text-muted mt-8">This permanently deletes all members, questions, tokens, submissions, and history.</p>

<form method="POST" action="/teams/delete.php?id=<?= (int) $teamId ?>" class="mt-16">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" class="btn btn-danger">Yes, delete</button>
    <a href="/teams/index.php?org_id=<?= (int) $team['org_id'] ?>" class="btn btn-secondary">Cancel</a>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Delete Team';
include __DIR__ . '/../../templates/layout.php';
