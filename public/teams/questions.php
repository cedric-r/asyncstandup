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
    $action     = $_POST['action'] ?? '';
    $questionId = (int) ($_POST['question_id'] ?? 0);
    if ($action === 'add') { $q = trim($_POST['question'] ?? ''); if ($q !== '') addQuestion($pdo, $teamId, $q); setFlash('success', 'Question added.'); }
    elseif ($action === 'edit') { $q = trim($_POST['question'] ?? ''); if ($q !== '' && $questionId > 0) updateQuestion($pdo, $questionId, $teamId, $q); setFlash('success', 'Updated.'); }
    elseif ($action === 'delete' && $questionId > 0) { deleteQuestion($pdo, $questionId, $teamId); setFlash('success', 'Deleted.'); }
    elseif (in_array($action, ['up','down'], true) && $questionId > 0) { swapQuestionPositions($pdo, $questionId, $action, $teamId); }
    header('Location: /teams/questions.php?team_id=' . $teamId);
    exit;
}

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'questions';
$isOwner  = true;

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$questions   = getQuestions($pdo, $teamId);
$inp         = 'flex-1 border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<h1 class="text-xl font-bold text-gray-900 mb-4">Questions</h1>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4">
<h3 class="text-sm font-medium text-gray-700 mb-3">Add question</h3>
<form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>" class="flex gap-2">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="action" value="add">
  <input type="text" name="question" required maxlength="500" placeholder="Question text" class="<?= $inp ?>">
  <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-1.5 px-3 rounded-lg">Add</button>
</form>
</div>

<div class="space-y-2">
<?php foreach ($questions as $q): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-3 flex items-center gap-2">
  <div class="flex flex-col gap-1">
    <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="up"><input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>"><button type="submit" class="text-gray-400 hover:text-gray-600 text-xs leading-none">↑</button></form>
    <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="down"><input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>"><button type="submit" class="text-gray-400 hover:text-gray-600 text-xs leading-none">↓</button></form>
  </div>
  <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>" class="flex flex-1 items-center gap-2">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
    <input type="text" name="question" value="<?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?>" maxlength="500" class="<?= $inp ?>">
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium py-1.5 px-3 rounded-lg">Save</button>
  </form>
  <form method="POST" action="/teams/questions.php?team_id=<?= (int) $teamId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
    <button type="submit" onclick="return confirm('Delete?')" class="text-red-500 hover:text-red-700 text-xs">Delete</button>
  </form>
</div>
<?php endforeach; ?>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Team Questions';
include __DIR__ . '/../../templates/layout.php';
