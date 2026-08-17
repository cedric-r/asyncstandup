<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';

startSession();
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

validateCsrfToken($_POST['csrf_token'] ?? '');

$pdo    = getDb($config);
$teamId = (int) ($_GET['id'] ?? 0);
$team   = getTeamById($pdo, $teamId);

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) {
    forbid();
}

$action = $_POST['action'] ?? '';

if ($action === 'suspend') {
    suspendTeam($pdo, $teamId);
    setFlash('success', 'Team suspended. No emails will be sent until reactivated.');
} elseif ($action === 'reactivate') {
    reactivateTeam($pdo, $teamId);
    setFlash('success', 'Team reactivated. Emails will resume at the next scheduled time.');
} else {
    http_response_code(400);
    exit;
}

header('Location: /teams/index.php?org_id=' . (int) $team['org_id']);
exit;
