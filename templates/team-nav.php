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
<div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
  <!-- Breadcrumb -->
  <p class="text-xs text-gray-500 mb-3">
    <a href="/orgs/index.php" class="hover:text-indigo-600">Organisations</a>
    <span class="mx-1">&rsaquo;</span>
    <a href="/teams/index.php?org_id=<?= (int) $orgId ?>" class="hover:text-indigo-600"><?= h($orgName) ?></a>
    <span class="mx-1">&rsaquo;</span>
    <span class="text-gray-700 font-medium"><?= h($teamName) ?></span>
  </p>

  <!-- Nav links -->
  <div class="flex flex-wrap gap-2">
    <?php
    $navLinks = [
        'dashboard' => ['/teams/dashboard.php?team_id=' . (int) $teamId, 'Dashboard', false],
    ];
    if ($isOwner) {
        $navLinks['responses']  = ['/teams/responses.php?team_id=' . (int) $teamId,  'Responses',  false];
        $navLinks['members']    = ['/teams/members.php?team_id=' . (int) $teamId,    'Members',    false];
        $navLinks['questions']  = ['/teams/questions.php?team_id=' . (int) $teamId,  'Questions',  false];
        $navLinks['recipients'] = ['/teams/recipients.php?team_id=' . (int) $teamId, 'Recipients', false];
        $navLinks['edit']       = ['/teams/edit.php?id=' . (int) $teamId,            'Settings',   false];
    }
    foreach ($navLinks as $page => [$href, $label, $_]) {
        $active = ($currentPage === $page);
        $cls = $active
            ? 'bg-indigo-600 text-white text-xs font-medium px-3 py-1.5 rounded-md'
            : 'bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs font-medium px-3 py-1.5 rounded-md';
        echo '<a href="' . $href . '" class="' . $cls . '">' . $label . '</a>';
    }
    ?>
  </div>
</div>
