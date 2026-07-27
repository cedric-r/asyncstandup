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

// No requireLogin() — token is the authenticator for this page.

$pdo   = getDb($config);
$token = trim($_GET['token'] ?? '');

if ($token === '') {
    http_response_code(404);
    $pageTitle = 'Invalid Link';
    $hideNav   = true;
    $flash     = null;
    $content   = '<div class="card"><p>Invalid link.</p></div>';
    include __DIR__ . '/../templates/layout.php';
    exit;
}

$tokenData = getTokenData($pdo, $token);

if ($tokenData === null) {
    http_response_code(404);
    $pageTitle = 'Invalid Link';
    $hideNav   = true;
    $flash     = null;
    $content   = '<div class="card"><p>Invalid link.</p></div>';
    include __DIR__ . '/../templates/layout.php';
    exit;
}

$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expiry = new DateTimeImmutable($tokenData['expires_at'], new DateTimeZone('UTC'));

if ($now > $expiry) {
    $pageTitle = 'Link Expired';
    $hideNav   = true;
    $flash     = null;
    $content   = '<div class="card"><p>This link has expired. Please contact your team owner.</p></div>';
    include __DIR__ . '/../templates/layout.php';
    exit;
}

if ($tokenData['used_at'] !== null) {
    // Show read-only already-submitted view.
    $answers   = getSubmissionWithAnswers($pdo, (int) $tokenData['id']) ?? [];
    $flash     = null;
    $pageTitle = 'Standup Submitted';
    $hideNav   = true;

    ob_start();
    ?>
    <h1 class="page-title">Standup already submitted</h1>
    <div class="card">
        <p class="text-muted">Submitted answers (read-only):</p>
        <br>
        <?php foreach ($answers as $row): ?>
        <p><strong><?= htmlspecialchars($row['question'], ENT_QUOTES, 'UTF-8') ?></strong></p>
        <p><?= nl2br(htmlspecialchars($row['answer'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
        <br>
        <?php endforeach; ?>
    </div>
    <?php
    $content = ob_get_clean();
    include __DIR__ . '/../templates/layout.php';
    exit;
}

// Load team questions.
$qStmt = $pdo->prepare('SELECT * FROM team_questions WHERE team_id = ? ORDER BY position ASC');
$qStmt->execute([(int) $tokenData['team_id']]);
$questions = $qStmt->fetchAll();

// Load team and org for display context (Feature 2).
$team = getTeamById($pdo, (int) $tokenData['team_id']);
$org  = $team !== null ? getOrgById($pdo, (int) $team['org_id']) : null;

// Handle POST submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    // Re-validate token (race condition protection).
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

    saveSubmission(
        $pdo,
        (int) $tokenData['id'],
        (int) $tokenData['user_id'],
        (int) $tokenData['team_id'],
        $answers
    );

    // PRG — redirect back to same URL; the used_at check will show read-only view.
    header('Location: /submit.php?token=' . urlencode($token));
    exit;
}

$csrfToken  = generateCsrfToken();
$flash      = null;
$pageTitle  = 'Submit Standup';
$hideNav    = true;
$memberName = htmlspecialchars($tokenData['display_name'] ?? $tokenData['email'], ENT_QUOTES, 'UTF-8');

ob_start();
?>
<?php if ($org !== null && $team !== null): ?>
<div style="margin-bottom:12px">
    <p class="text-muted" style="font-size:0.9em"><?= h($org['name']) ?></p>
    <h2 style="font-size:1.2em;font-weight:700"><?= h($team['name']) ?> — Daily Standup</h2>
</div>
<?php endif; ?>
<h1 class="page-title">Standup — <?= $memberName ?></h1>
<p class="text-muted">Date: <?= htmlspecialchars($tokenData['send_date'], ENT_QUOTES, 'UTF-8') ?></p>

<div class="card mt-16">
<form method="POST" action="/submit.php?token=<?= urlencode($token) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <?php foreach ($questions as $q): ?>
    <div class="form-group">
        <label><?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?></label>
        <textarea name="answer[<?= (int) $q['id'] ?>]" rows="3"></textarea>
    </div>
    <?php endforeach; ?>

    <button type="submit" class="btn btn-primary">Submit standup</button>
</form>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../templates/layout.php';
