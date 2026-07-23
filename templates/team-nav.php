<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/View.php';

/**
 * Team navigation partial.
 *
 * Required variables (set by the calling page before include):
 * @var string $currentPage  'dashboard'|'members'|'questions'|'recipients'|'edit'|'responses'
 * @var int    $teamId
 * @var int    $orgId
 * @var string $teamName     Raw (not pre-escaped) — h() applied below
 * @var string $orgName      Raw (not pre-escaped) — h() applied below
 * @var bool   $isOwner      Whether the current user is a team owner
 *
 * No access control logic here — callers enforce access; this partial only renders.
 */
?>
<nav class="team-nav">
    <div class="breadcrumb">
        <a href="/orgs/index.php">Organisations</a> &rsaquo;
        <a href="/teams/index.php?org_id=<?= (int) $orgId ?>"><?= h($orgName) ?></a> &rsaquo;
        <span><?= h($teamName) ?></span>
    </div>

    <ul class="team-nav-links">
        <li class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">
            <a href="/teams/dashboard.php?team_id=<?= (int) $teamId ?>">Dashboard</a>
        </li>
        <?php if ($isOwner): ?>
        <li class="<?= $currentPage === 'responses' ? 'active' : '' ?>">
            <a href="/teams/responses.php?team_id=<?= (int) $teamId ?>">Responses</a>
        </li>
        <li class="<?= $currentPage === 'members' ? 'active' : '' ?>">
            <a href="/teams/members.php?team_id=<?= (int) $teamId ?>">Members</a>
        </li>
        <li class="<?= $currentPage === 'questions' ? 'active' : '' ?>">
            <a href="/teams/questions.php?team_id=<?= (int) $teamId ?>">Questions</a>
        </li>
        <li class="<?= $currentPage === 'recipients' ? 'active' : '' ?>">
            <a href="/teams/recipients.php?team_id=<?= (int) $teamId ?>">Recipients</a>
        </li>
        <li class="<?= $currentPage === 'edit' ? 'active' : '' ?>">
            <a href="/teams/edit.php?id=<?= (int) $teamId ?>">Settings</a>
        </li>
        <?php endif; ?>
    </ul>
</nav>
