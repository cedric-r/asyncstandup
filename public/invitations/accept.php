<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/InvitationRepository.php';
require_once __DIR__ . '/../../src/View.php';

startSession();

$pdo   = getDb($config);
$token = trim($_GET['token'] ?? '');
$flash = null;

function showAcceptMsg(string $msg, string $title): never {
    $content = '<div class="max-w-lg mx-auto bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">'
             . '<p class="text-gray-700">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div>';
    $GLOBALS['pageTitle']    = $title;
    $GLOBALS['flash']        = null;
    $GLOBALS['currentUser']  = null;
    include __DIR__ . '/../../templates/layout.php';
    exit;
}

if ($token === '') { showAcceptMsg('Invalid invitation link.', 'Invalid'); }

$invitation = getInvitationByToken($pdo, $token);

if ($invitation === null) { showAcceptMsg('Invitation not found.', 'Invalid'); }
if ($invitation['accepted_at'] !== null) { showAcceptMsg('This invitation has already been accepted.', 'Already Accepted'); }

$createdAt = new DateTimeImmutable($invitation['created_at'], new DateTimeZone('UTC'));
$expiresAt = $createdAt->modify('+7 days');
$now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($now > $expiresAt) { showAcceptMsg('This invitation has expired. Please contact the team owner.', 'Expired'); }

// Fix 1: POST-only acceptance — GET only shows confirmation form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    // Re-load invitation to prevent token-substitution on POST.
    $postToken = trim($_POST['token'] ?? '');
    $inv       = getInvitationByToken($pdo, $postToken);

    if ($inv === null || $inv['accepted_at'] !== null) {
        showAcceptMsg('This invitation is no longer valid.', 'Invalid');
    }

    // If user is logged in — accept immediately.
    if (isLoggedIn()) {
        acceptInvitationForUser($pdo, $postToken, (int) $_SESSION['user_id']);
        setFlash('success', 'You have joined the team: ' . $inv['team_name']);
        header('Location: /dashboard.php');
        exit;
    }

    // Not logged in — check if account exists.
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([mb_strtolower(trim($inv['invited_email']))]);
    $existingUser = $stmt->fetch();

    if ($existingUser !== false) {
        $_SESSION['pending_invite_token'] = $postToken;
        header('Location: /login.php');
        exit;
    }

    header('Location: /register.php?email=' . urlencode($inv['invited_email']) . '&invite=' . urlencode($postToken));
    exit;
}

// GET: show confirmation form.
$csrfToken   = generateCsrfToken();
$currentUser = isLoggedIn() ? getCurrentUser($pdo) : null;
$emailMismatch = $currentUser !== null
    && strtolower($currentUser['email']) !== strtolower($invitation['invited_email']);

ob_start();
?>
<div class="max-w-lg mx-auto">
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8">
  <h1 class="text-xl font-semibold text-gray-900 mb-2">Team Invitation</h1>
  <p class="text-sm text-gray-700 mb-4">
    You have been invited to join
    <strong><?= htmlspecialchars($invitation['team_name'], ENT_QUOTES, 'UTF-8') ?></strong>
    at <strong><?= htmlspecialchars($invitation['org_name'], ENT_QUOTES, 'UTF-8') ?></strong>.
  </p>

  <?php if ($emailMismatch): ?>
  <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-3 text-sm mb-4">
    Note: this invitation was sent to <strong><?= htmlspecialchars($invitation['invited_email'], ENT_QUOTES, 'UTF-8') ?></strong>.
    You are logged in as <strong><?= htmlspecialchars($currentUser['email'], ENT_QUOTES, 'UTF-8') ?></strong>.
  </div>
  <?php endif; ?>

  <form method="POST" action="/invitations/accept.php?token=<?= urlencode($token) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
    <div class="flex gap-3">
      <button type="submit"
              class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">
        Accept invitation
      </button>
      <a href="/dashboard.php"
         class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 font-medium py-2 px-4 rounded-lg text-sm">
        Decline
      </a>
    </div>
  </form>
  <p class="text-xs text-gray-400 mt-4">Declining does not delete the invitation — the link will remain valid until it expires.</p>
</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Team Invitation';
include __DIR__ . '/../../templates/layout.php';
