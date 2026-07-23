<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/Mailer.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/InvitationRepository.php';

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

    $email       = mb_strtolower(trim($_POST['email'] ?? ''));
    $roles       = array_intersect($_POST['roles'] ?? [], ['owner', 'developer', 'recipient']);
    $rolesStr    = implode(',', $roles) ?: 'developer';
    $currentUser = getCurrentUser($pdo);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    } elseif (isAlreadyTeamMember($pdo, $teamId, $email)) {
        $errors[] = 'That user is already a member of this team.';
    } else {
        $token     = createInvitation($pdo, $teamId, $email, $rolesStr, (int) $_SESSION['user_id']);
        $acceptUrl = rtrim($config['app_url'], '/') . '/invitations/accept.php?token=' . urlencode($token);

        // Fetch org name for email.
        $orgStmt = $pdo->prepare('SELECT name FROM organisations WHERE id = ?');
        $orgStmt->execute([$team['org_id']]);
        $orgName = (string) ($orgStmt->fetchColumn() ?: '');

        $rolesReadable = implode(', ', array_map('ucfirst', $roles)) ?: 'Developer';

        ob_start();
        extract([
            'team_name'   => $team['name'],
            'org_name'    => $orgName,
            'inviter_name' => $currentUser['display_name'] ?? $currentUser['email'],
            'accept_url'  => $acceptUrl,
            'expires_days' => 7,
            'roles'       => $rolesReadable,
        ], EXTR_SKIP);
        include __DIR__ . '/../../templates/email/invitation.php';
        $body = ob_get_clean();

        try {
            sendMail($config, $email, $email, 'You have been invited to ' . $team['name'], (string) $body);
            setFlash('success', 'Invitation sent to ' . $email);
        } catch (RuntimeException $e) {
            setFlash('error', 'Invitation created but email could not be sent: ' . $e->getMessage());
        }

        header('Location: /teams/members.php?team_id=' . $teamId);
        exit;
    }
}

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<h1 class="page-title">Invite to <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></h1>

<?php foreach ($errors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card">
<form method="POST" action="/invitations/send.php?team_id=<?= (int) $teamId ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label>Roles</label><br>
        <label><input type="checkbox" name="roles[]" value="owner"> Owner</label><br>
        <label><input type="checkbox" name="roles[]" value="developer" checked> Developer</label><br>
        <label><input type="checkbox" name="roles[]" value="recipient"> Recipient</label>
    </div>

    <button type="submit" class="btn btn-primary">Send invitation</button>
    <a href="/teams/members.php?team_id=<?= (int) $teamId ?>" class="btn btn-secondary">Cancel</a>
</form>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Send Invitation';
include __DIR__ . '/../../templates/layout.php';
