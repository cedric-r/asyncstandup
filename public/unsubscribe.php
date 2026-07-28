<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';   // startSession(), generateCsrfToken(), validateCsrfToken()
require_once __DIR__ . '/../src/Csrf.php';
require_once __DIR__ . '/../src/View.php';

// Session required for CSRF token storage — but login is NOT required.
startSession();

$pdo   = getDb($config);
$token = trim($_GET['token'] ?? '');
$flash = null;

function loadRecipient(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('
        SELECT tr.id, tr.email, tr.display_name, tr.team_id,
               t.name AS team_name, o.name AS org_name
        FROM team_recipients tr
        JOIN teams        t ON t.id = tr.team_id
        JOIN organisations o ON o.id = t.org_id
        WHERE tr.unsubscribe_token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

// POST: confirm unsubscribe
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $postToken = trim($_POST['token'] ?? '');
    $recipient = loadRecipient($pdo, $postToken);

    if ($recipient === null) {
        $errorMsg = 'Invalid unsubscribe link.';
        ob_start();
        ?>
        <div class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 max-w-md w-full text-center">
          <p class="text-gray-700"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        </div>
        <?php
        $content   = ob_get_clean();
        $pageTitle = 'Unsubscribe';
        $currentUser = isLoggedIn() ? getCurrentUser($pdo) : null;
        include __DIR__ . '/../templates/layout.php';
        exit;
    }

    $pdo->prepare('DELETE FROM team_recipients WHERE id = ?')->execute([(int) $recipient['id']]);

    ob_start();
    ?>
    <div class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 max-w-md w-full text-center">
      <h1 class="text-xl font-semibold text-gray-900 mb-3">You have been unsubscribed.</h1>
      <p class="text-sm text-gray-500">
        You will no longer receive standup summaries for
        <strong><?= htmlspecialchars($recipient['team_name'], ENT_QUOTES, 'UTF-8') ?></strong>.
      </p>
    </div>
    </div>
    <?php
    $content     = ob_get_clean();
    $pageTitle   = 'Unsubscribed';
    $currentUser = isLoggedIn() ? getCurrentUser($pdo) : null;
    include __DIR__ . '/../templates/layout.php';
    exit;
}

// GET: show confirm form
if ($token === '') {
    $recipient = null;
} else {
    $recipient = loadRecipient($pdo, $token);
}

$csrfToken   = generateCsrfToken();
$currentUser = isLoggedIn() ? getCurrentUser($pdo) : null;

ob_start();
?>
<div class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 max-w-md w-full text-center">

<?php if ($recipient === null): ?>
  <h1 class="text-xl font-semibold text-gray-900 mb-3">Invalid link</h1>
  <p class="text-sm text-gray-500">This unsubscribe link is invalid or has already been used.</p>
<?php else: ?>
  <h1 class="text-xl font-semibold text-gray-900 mb-2">Unsubscribe from summaries</h1>
  <p class="text-sm text-gray-600 mb-6">
    You are unsubscribing from standup summaries for
    <strong><?= htmlspecialchars($recipient['team_name'], ENT_QUOTES, 'UTF-8') ?></strong>
    at <strong><?= htmlspecialchars($recipient['org_name'], ENT_QUOTES, 'UTF-8') ?></strong>.
  </p>
  <form method="POST" action="/unsubscribe.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit"
            class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-6 rounded-lg text-sm">
      Confirm unsubscribe
    </button>
  </form>
  <p class="text-xs text-gray-400 mt-4">
    This only removes you from this team's summary emails. Your account (if any) is unaffected.
  </p>
<?php endif; ?>

</div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Unsubscribe';
include __DIR__ . '/../templates/layout.php';
