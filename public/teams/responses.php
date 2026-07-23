<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/StandupEmailer.php';
require_once __DIR__ . '/../../src/DashboardRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$teamId = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;

// AC-5 / AC-6: owner check is the very first operation after requireLogin().
if (!$teamId || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) {
    forbid();
}

$team        = getTeamById($pdo, $teamId);
$members     = getDeveloperMembers($pdo, $teamId);   // is_developer = 1
$questions   = getQuestions($pdo, $teamId);           // position ASC
$currentUser = getCurrentUser($pdo);

// ---------------------------------------------------------------------------
// Filter parsing and validation
// ---------------------------------------------------------------------------

$rawDate   = $_GET['date'] ?? null;
$rawMember = isset($_GET['member_id']) ? (int) $_GET['member_id'] : null;

$dateFilter   = null;
$memberFilter = null;
$filterErrors = [];

if ($rawDate !== null && $rawDate !== '') {
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);

    if ($parsed === false || $parsed->format('Y-m-d') !== $rawDate) {
        $filterErrors[] = 'Invalid date format. Use YYYY-MM-DD.';
    } else {
        $dateFilter = $rawDate;
    }
}

if ($rawMember !== null && $rawMember > 0) {
    // Validate: must be an is_developer member of this team.
    $memberIds = array_column($members, 'id');

    if (!in_array($rawMember, array_map('intval', $memberIds), true)) {
        $filterErrors[] = 'Invalid member selection.';
    } else {
        $memberFilter = $rawMember;
    }
}

// ---------------------------------------------------------------------------
// Date window computation (team timezone)
// ---------------------------------------------------------------------------

$teamTz  = new DateTimeZone($team['timezone']);
$today   = new DateTimeImmutable('today', $teamTz);

if ($memberFilter !== null) {
    // by_member or single: 30-day window.
    $dateFrom = $today->modify('-29 days')->format('Y-m-d');
    $dateTo   = $today->format('Y-m-d');
} else {
    // default or by_date: 7-day window.
    $dateFrom = $today->modify('-6 days')->format('Y-m-d');
    $dateTo   = $today->format('Y-m-d');
}

// Determine view mode.
if ($dateFilter !== null && $memberFilter !== null) {
    $view = 'single';
} elseif ($dateFilter !== null) {
    $view = 'by_date';
} elseif ($memberFilter !== null) {
    $view = 'by_member';
} else {
    $view = 'default';
}

// ---------------------------------------------------------------------------
// Data load (only if no filter errors)
// ---------------------------------------------------------------------------

$data = [];  // $data[$send_date][$user_id] = ['display_name', 'submitted', 'answers', 'no_token']

if (empty($filterErrors)) {
    $rows = getResponseData($pdo, $teamId, $dateFilter, $memberFilter, $dateFrom, $dateTo);

    // Assemble nested structure from flat rows.
    foreach ($rows as $row) {
        $date   = (string) $row['send_date'];
        $uid    = (int) $row['user_id'];

        if (!isset($data[$date][$uid])) {
            $data[$date][$uid] = [
                'display_name' => $row['display_name'],
                'submitted'    => $row['submission_id'] !== null,
                'answers'      => [],
                'no_token'     => false,
            ];
        }

        if ($row['question_id'] !== null && $row['submission_id'] !== null) {
            $data[$date][$uid]['answers'][(int) $row['question_id']] = (string) ($row['answer'] ?? '');
        }
    }

    // For default + by_date views: inject members with no token for that day.
    if ($view === 'default' || $view === 'by_date') {
        foreach (array_keys($data) as $date) {
            foreach ($members as $m) {
                $uid = (int) $m['id'];

                if (!isset($data[$date][$uid])) {
                    $data[$date][$uid] = [
                        'display_name' => $m['display_name'],
                        'submitted'    => false,
                        'answers'      => [],
                        'no_token'     => true,
                    ];
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Member lookup map for rendering.
// ---------------------------------------------------------------------------
$memberMap = [];

foreach ($members as $m) {
    $memberMap[(int) $m['id']] = $m['display_name'];
}

// ---------------------------------------------------------------------------
// HTML rendering
// ---------------------------------------------------------------------------

$flash     = getFlash();
$csrfToken = generateCsrfToken(); // Not used for POST here, but may be needed for future inline forms.

// Raw values — the outer htmlspecialchars() at render time handles escaping.
// Do NOT pre-escape here; double-encoding would corrupt names like O'Brien.
$viewLabels = [
    'default'   => 'Last 7 days — all members',
    'by_date'   => 'Date: ' . ($dateFilter ?? ''),
    'by_member' => 'Member: ' . ($memberFilter !== null ? ($memberMap[$memberFilter] ?? '?') : '') . ' — last 30 days',
    'single'    => 'Date: ' . ($dateFilter ?? '') . ' — Member: ' . ($memberFilter !== null ? ($memberMap[$memberFilter] ?? '?') : ''),
];

ob_start();
?>
<h1 class="page-title">Standup Responses — <?= htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8') ?></h1>

<div class="card">
<form method="GET" action="/teams/responses.php">
    <input type="hidden" name="team_id" value="<?= $teamId ?>">
    <div class="form-row" style="align-items:flex-end">
        <div class="form-group">
            <label for="date">Date</label>
            <input type="date" id="date" name="date"
                   value="<?= htmlspecialchars($dateFilter ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="form-group">
            <label for="member_id">Member</label>
            <select id="member_id" name="member_id">
                <option value="">All members</option>
                <?php foreach ($members as $m): ?>
                <option value="<?= (int) $m['id'] ?>"
                    <?= $memberFilter === (int) $m['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['display_name'] ?? $m['email'], ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="margin-bottom:12px">
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <a href="/teams/responses.php?team_id=<?= $teamId ?>" class="btn btn-secondary btn-sm">Clear</a>
        </div>
    </div>
</form>
<p class="text-muted">Showing: <?= htmlspecialchars($viewLabels[$view], ENT_QUOTES, 'UTF-8') ?></p>
</div>

<?php foreach ($filterErrors as $err): ?>
<div class="alert alert-error"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (empty($data)): ?>
<div class="card mt-16"><p class="text-muted">No standup data for the selected range.</p></div>
<?php else: ?>

<?php foreach ($data as $date => $memberData): ?>
<div class="response-day card mt-16">
    <h3 style="margin-bottom:12px"><?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8') ?></h3>

    <?php foreach ($memberData as $uid => $entry): ?>
    <div style="margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #eee">
        <strong><?= htmlspecialchars($entry['display_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></strong>

        <?php if ($entry['no_token']): ?>
        <span class="text-muted" style="margin-left:8px">(No email sent)</span>
        <?php elseif (!$entry['submitted']): ?>
        <span style="color:#e65100;margin-left:8px">No response</span>
        <?php else: ?>
        <dl style="margin-top:8px">
        <?php foreach ($questions as $q): ?>
            <dt style="font-weight:500;margin-top:6px"><?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?></dt>
            <dd style="margin-left:16px;color:#444">
                <?php
                $answer = $entry['answers'][(int) $q['id']] ?? null;
                echo $answer !== null && $answer !== ''
                    ? nl2br(htmlspecialchars($answer, ENT_QUOTES, 'UTF-8'))
                    : '<span class="text-muted">(no answer)</span>';
                ?>
            </dd>
        <?php endforeach; ?>
        </dl>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<div class="mt-16">
    <a href="/teams/dashboard.php?team_id=<?= $teamId ?>" class="btn btn-secondary">← Back to Dashboard</a>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Responses — ' . htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8');
include __DIR__ . '/../../templates/layout.php';
