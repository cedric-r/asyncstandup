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
$teamId = (int) ($_GET['team_id'] ?? 0);
$team   = getTeamById($pdo, $teamId);

if ($team === null || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $email       = trim($_POST['email'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        } else {
            try {
                addRecipient($pdo, $teamId, $email, $displayName, (int) $_SESSION['user_id']);
                setFlash('success', 'Recipient added.');
                header('Location: /teams/recipients.php?team_id=' . $teamId);
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'That email is already a recipient for this team.';
                } else {
                    $errors[] = 'Could not add recipient.';
                }
            }
        }
    } elseif ($action === 'remove') {
        $recipientId = (int) ($_POST['recipient_id'] ?? 0);
        removeRecipient($pdo, $recipientId, $teamId);
        setFlash('success', 'Recipient removed.');
        header('Location: /teams/recipients.php?team_id=' . $teamId);
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$recipients  = getRecipients($pdo, $teamId);

ob_start();
?>
<h1 class="page-title">Summary Recipients — <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<h3>Add external recipient</h3>
<form method="POST" action="/teams/recipients.php?team_id=<?= (int) $teamId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="action" value="add">
    <div class="form-row">
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
            <label>Display name <span class="text-muted">(optional)</span></label>
            <input type="text" name="display_name" maxlength="100" value="<?= htmlspecialchars($_POST['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Add</button>
</form>
</div>

<div class="mt-16">
<?php if (empty($recipients)): ?>
<p class="text-muted">No external recipients yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Email</th><th>Display name</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($recipients as $r): ?>
<tr>
    <td><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
    <td><?= htmlspecialchars($r['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
    <td>
        <form method="POST" action="/teams/recipients.php?team_id=<?= (int) $teamId ?>" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="recipient_id" value="<?= (int) $r['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Recipients';
include __DIR__ . '/../../templates/layout.php';
