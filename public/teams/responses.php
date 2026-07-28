<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/StandupEmailer.php';
require_once __DIR__ . '/../../src/DashboardRepository.php';
require_once __DIR__ . '/../../src/OrgRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;

if (!$teamId || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) { forbid(); }

$team      = getTeamById($pdo, $teamId);
$members   = getDeveloperMembers($pdo, $teamId);
$questions = getQuestions($pdo, $teamId);

$rawDate   = $_GET['date'] ?? null;
$rawMember = isset($_GET['member_id']) ? (int) $_GET['member_id'] : null;

$dateFilter   = null;
$memberFilter = null;
$filterErrors = [];

if ($rawDate !== null && $rawDate !== '') {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);
    if ($parsed === false || $parsed->format('Y-m-d') !== $rawDate) {
        $filterErrors[] = 'Invalid date format.';
    } else {
        $dateFilter = $rawDate;
    }
}

if ($rawMember !== null && $rawMember > 0) {
    $memberIds = array_map('intval', array_column($members, 'id'));
    if (!in_array($rawMember, $memberIds, true)) { $filterErrors[] = 'Invalid member.'; }
    else { $memberFilter = $rawMember; }
}

$teamTz  = new DateTimeZone($team['timezone']);
$today   = new DateTimeImmutable('today', $teamTz);
$dateFrom = $memberFilter !== null ? $today->modify('-29 days')->format('Y-m-d') : $today->modify('-6 days')->format('Y-m-d');
$dateTo   = $today->format('Y-m-d');

$view = ($dateFilter !== null && $memberFilter !== null) ? 'single'
     : ($dateFilter !== null ? 'by_date'
     : ($memberFilter !== null ? 'by_member' : 'default'));

$data = [];
if (empty($filterErrors)) {
    $rows = getResponseData($pdo, $teamId, $dateFilter, $memberFilter, $dateFrom, $dateTo);
    foreach ($rows as $row) {
        $date = (string) $row['send_date'];
        $uid  = (int) $row['user_id'];
        if (!isset($data[$date][$uid])) {
            $data[$date][$uid] = ['display_name' => $row['display_name'], 'submitted' => $row['submission_id'] !== null, 'answers' => [], 'no_token' => false];
        }
        if ($row['question_id'] !== null && $row['submission_id'] !== null) {
            $data[$date][$uid]['answers'][(int) $row['question_id']] = (string) ($row['answer'] ?? '');
        }
    }
    if ($view === 'default' || $view === 'by_date') {
        foreach (array_keys($data) as $date) {
            foreach ($members as $m) {
                $uid = (int) $m['id'];
                if (!isset($data[$date][$uid])) {
                    $data[$date][$uid] = ['display_name' => $m['display_name'], 'submitted' => false, 'answers' => [], 'no_token' => true];
                }
            }
        }
    }
}

$memberMap = [];
foreach ($members as $m) { $memberMap[(int) $m['id']] = $m['display_name']; }

$org      = getOrgById($pdo, (int) $team['org_id']);
$orgId    = (int) $team['org_id'];
$orgName  = (string) ($org['name'] ?? '');
$teamName = (string) $team['name'];
$currentPage = 'responses';
$isOwner  = true;

$flash       = getFlash();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<?php include __DIR__ . '/../../templates/team-nav.php'; ?>
<h1 class="text-xl font-bold text-gray-900 mb-4">Standup Responses</h1>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
<form method="GET" action="/teams/responses.php" class="flex flex-wrap items-end gap-3">
  <input type="hidden" name="team_id" value="<?= $teamId ?>">
  <div><label class="block text-xs text-gray-600 mb-1">Date</label><input type="date" name="date" value="<?= htmlspecialchars($dateFilter ?? '', ENT_QUOTES, 'UTF-8') ?>" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
  <div><label class="block text-xs text-gray-600 mb-1">Member</label>
    <select name="member_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">All</option>
      <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>" <?= $memberFilter === (int) $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['display_name'] ?? $m['email'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2 px-4 rounded-lg">Apply</button>
  <a href="/teams/responses.php?team_id=<?= $teamId ?>" class="bg-white hover:bg-gray-50 text-gray-700 text-sm font-medium py-2 px-4 rounded-lg border border-gray-300">Clear</a>
</form>
</div>

<?php foreach ($filterErrors as $err): ?><div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>

<?php if (empty($data)): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-12 text-center"><p class="text-gray-500">No standup data for the selected range.</p></div>
<?php else: ?>
<?php foreach ($data as $date => $memberData): ?>
<div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4">
  <h3 class="font-semibold text-gray-700 mb-3"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></h3>
  <?php foreach ($memberData as $uid => $entry): ?>
  <div class="mb-4 last:mb-0 border-b border-gray-100 last:border-0 pb-3 last:pb-0">
    <p class="font-medium text-gray-900 mb-1"><?= htmlspecialchars($entry['display_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($entry['no_token']): ?>
      <p class="text-xs text-gray-400">No email sent</p>
    <?php elseif (!$entry['submitted']): ?>
      <p class="text-xs text-amber-600">No response</p>
    <?php else: ?>
      <dl class="space-y-1">
      <?php foreach ($questions as $q): ?>
        <dt class="text-xs font-medium text-gray-600"><?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?></dt>
        <dd class="text-sm text-gray-800 ml-3"><?php $ans = $entry['answers'][(int) $q['id']] ?? null; echo $ans !== null && $ans !== '' ? nl2br(htmlspecialchars($ans, ENT_QUOTES, 'UTF-8')) : '<span class="text-gray-400 text-xs">(no answer)</span>'; ?></dd>
      <?php endforeach; ?>
      </dl>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = 'Responses — ' . htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8');
include __DIR__ . '/../../templates/layout.php';
