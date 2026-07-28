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
                // Generate unsubscribe token immediately on add.
                $unsubToken = bin2hex(random_bytes(32));
                $pdo->prepare('
                    INSERT INTO team_recipients (team_id, email, display_name, added_by, unsubscribe_token)
                    VALUES (?, ?, ?, ?, ?)
                ')->execute([$teamId, trim($email), trim($displayName), (int) $_SESSION['user_id'], $unsubToken]);
                setFlash('success', 'Recipient added.');
                header('Location: /teams/recipients.php?team_id=' . $teamId);
                exit;
            } catch (PDOException $e) {
                $errors[] = $e->getCode() === '23000' ? 'That email is already a recipient.' : 'Could not add recipient.';
            }
        }
    } elseif ($action === 'remove') {
        removeRecipient($pdo, (int) ($_POST['recipient_id'] ?? 0), $teamId);
        setFlash('success', 'Removed.');
        header('Location: /teams/recipients.php?team_id=' . $teamId);
        exit;
    }
}

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'recipients';
$isOwner  = true;

$csrfToken   = generateCsrfToken();
$flash       = getFlash();
$currentUser = getCurrentUser($pdo);
$recipients  = getRecipients($pdo, $teamId);
$inp         = 'w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none';

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<h1 class="text-xl font-bold text-gray-900 mb-4">Summary Recipients</h1>
<?php foreach ($errors as $e): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4">
<h3 class="text-sm font-medium text-gray-700 mb-3">Add external recipient</h3>
<form method="POST" action="/teams/recipients.php?team_id=<?= (int) $teamId ?>" class="grid grid-cols-2 gap-3">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="action" value="add">
  <div><label class="block text-xs text-gray-600 mb-1">Email</label><input type="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="<?= $inp ?>"></div>
  <div><label class="block text-xs text-gray-600 mb-1">Display name <span class="text-gray-400">(optional)</span></label><input type="text" name="display_name" maxlength="100" value="<?= htmlspecialchars($_POST['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="<?= $inp ?>"></div>
  <div class="col-span-2"><button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2 px-4 rounded-lg">Add</button></div>
</form>
</div>

<?php if (!empty($recipients)): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
<table class="w-full text-sm">
<thead class="bg-gray-50 border-b border-gray-200">
<tr><th class="px-4 py-3 text-left font-medium text-gray-700">Email</th><th class="px-4 py-3 text-left font-medium text-gray-700">Name</th><th class="px-4 py-3"></th></tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php foreach ($recipients as $r): ?>
<tr class="hover:bg-gray-50">
  <td class="px-4 py-3"><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></td>
  <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($r['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
  <td class="px-4 py-3">
    <form method="POST" action="/teams/recipients.php?team_id=<?= (int) $teamId ?>">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="recipient_id" value="<?= (int) $r['id'] ?>">
      <button type="submit" class="text-xs text-red-600 hover:text-red-700">Remove</button>
    </form>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = 'Recipients';
include __DIR__ . '/../../templates/layout.php';
