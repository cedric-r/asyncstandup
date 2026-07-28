<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/SubmissionRepository.php';
require_once __DIR__ . '/../src/TeamRepository.php';
require_once __DIR__ . '/../src/OrgRepository.php';
require_once __DIR__ . '/../src/View.php';

startSession();

$pdo   = getDb($config);
$token = trim($_GET['token'] ?? '');

function submitError(string $msg, string $title = 'Invalid Link'): never {
    $content = '<div class="max-w-md mx-auto bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">'
             . '<p class="text-gray-700 mb-4">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
             . '<a href="/dashboard.php" class="text-indigo-600 hover:text-indigo-700 text-sm">← Back to dashboard</a>'
             . '</div>';
    $GLOBALS['pageTitle'] = $title;
    $GLOBALS['flash']     = null;
    include __DIR__ . '/../templates/layout.php';
    exit;
}

if ($token === '') { submitError('Invalid link.'); }
$tokenData = getTokenData($pdo, $token);
if ($tokenData === null) { submitError('Invalid link.'); }

$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expiry = new DateTimeImmutable($tokenData['expires_at'], new DateTimeZone('UTC'));
if ($now > $expiry) { submitError('This link has expired. Please contact your team owner.', 'Link Expired'); }

if ($tokenData['used_at'] !== null) {
    $answers = getSubmissionWithAnswers($pdo, (int) $tokenData['id']) ?? [];
    $flash   = null;
    ob_start();
    ?>
    <div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-gray-900 mb-4">Standup already submitted</h1>
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 space-y-4">
      <p class="text-sm text-gray-500">Submitted answers (read-only):</p>
      <?php foreach ($answers as $row): ?>
      <div>
        <p class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($row['question'], ENT_QUOTES, 'UTF-8') ?></p>
        <p class="text-gray-700 mt-1"><?= nl2br(htmlspecialchars($row['answer'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="mt-4 text-sm"><a href="/dashboard.php" class="text-indigo-600 hover:text-indigo-700">← Back to dashboard</a></p>
    </div>
    <?php
    $content   = ob_get_clean();
    $pageTitle = 'Standup Submitted';
    include __DIR__ . '/../templates/layout.php';
    exit;
}

$qStmt = $pdo->prepare('SELECT * FROM team_questions WHERE team_id = ? ORDER BY position ASC');
$qStmt->execute([(int) $tokenData['team_id']]);
$questions = $qStmt->fetchAll();

$team = getTeamById($pdo, (int) $tokenData['team_id']);
$org  = $team !== null ? getOrgById($pdo, (int) $team['org_id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $freshToken = getTokenData($pdo, $token);
    if ($freshToken === null || $freshToken['used_at'] !== null) {
        http_response_code(409);
        echo 'Standup already submitted.';
        exit;
    }
    $answers = [];
    foreach ($questions as $q) {
        $answers[(int) $q['id']] = trim($_POST['answer'][(int) $q['id']] ?? '');
    }
    saveSubmission($pdo, (int) $tokenData['id'], (int) $tokenData['user_id'], (int) $tokenData['team_id'], $answers);
    header('Location: /submit.php?token=' . urlencode($token));
    exit;
}

$csrfToken  = generateCsrfToken();
$flash      = null;
$memberName = htmlspecialchars($tokenData['display_name'] ?? $tokenData['email'], ENT_QUOTES, 'UTF-8');
$inp        = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none resize-y';

ob_start();
?>
<div class="max-w-2xl mx-auto">
<?php if ($org !== null && $team !== null): ?>
<p class="text-xs text-gray-400 mb-1"><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></p>
<h2 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?> — Daily Standup</h2>
<?php endif; ?>
<h1 class="text-base text-gray-600 mb-6">Hi, <?= $memberName ?> — <?= htmlspecialchars($tokenData['send_date'], ENT_QUOTES, 'UTF-8') ?></h1>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
<form method="POST" action="/submit.php?token=<?= urlencode($token) ?>" class="space-y-5">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <?php foreach ($questions as $q): ?>
  <div>
    <label class="block text-sm font-medium text-gray-800 mb-1"><?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?></label>
    <textarea name="answer[<?= (int) $q['id'] ?>]" rows="3" class="<?= $inp ?>"></textarea>
  </div>
  <?php endforeach; ?>
  <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-lg text-sm w-full">Submit standup</button>
</form>
</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Submit Standup';
include __DIR__ . '/../templates/layout.php';
