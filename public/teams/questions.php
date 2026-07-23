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

    $action     = $_POST['action'] ?? '';
    $questionId = (int) ($_POST['question_id'] ?? 0);

    if ($action === 'add') {
        $q = trim($_POST['question'] ?? '');
        if ($q !== '') {
            addQuestion($pdo, $teamId, $q);
            setFlash('success', 'Question added.');
        }
    } elseif ($action === 'edit') {
        $q = trim($_POST['question'] ?? '');
        if ($q !== '' && $questionId > 0) {
            updateQuestion($pdo, $questionId, $teamId, $q);
            setFlash('success', 'Question updated.');
        }
    } elseif ($action === 'delete' && $questionId > 0) {
        deleteQuestion($pdo, $questionId, $teamId);
        setFlash('success', 'Question deleted.');
    } elseif (in_array($action, ['up', 'down'], true) && $questionId > 0) {
        swapQuestionPositions($pdo, $questionId, $action, $teamId);
    }

    header('Location: /teams/questions.php?team_id=' . $teamId);
    exit;
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$questions   = getQuestions($pdo, $teamId);

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'questions';

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<h1 class="page-title">Questions — <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></h1>

<div class="card">
<h3>Add question</h3>
<form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="add">
    <div class="form-group">
        <input type="text" name="question" required maxlength="500" placeholder="Question text">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Add</button>
</form>
</div>

<div class="mt-16">
<?php foreach ($questions as $q): ?>
<div class="card" style="display:flex;align-items:center;gap:12px;padding:12px">
    <span style="flex:1"><?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?></span>

    <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="up">
        <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">↑</button>
    </form>

    <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="down">
        <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">↓</button>
    </form>

    <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>" style="display:inline" id="edit-form-<?= (int) $q['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
        <input type="text" name="question" value="<?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?>"
               maxlength="500" style="width:300px">
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </form>

    <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Delete this question?')">Delete</button>
    </form>
</div>
<?php endforeach; ?>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Team Questions';
include __DIR__ . '/../../templates/layout.php';
