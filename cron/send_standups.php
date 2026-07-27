#!/usr/bin/env php
<?php

declare(strict_types=1);

// =============================================================================
// AsyncStandUp — Cron: send standup emails
// =============================================================================
// Run every minute:
//   * * * * * php /path/to/standup/cron/send_standups.php
//
// Two passes per run:
//   1. Prompt pass  — sends standup prompt to each developer when standup_time hits
//   2. Summary pass — sends summary to team recipients 1 hour after standup_time
// =============================================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Mailer.php';
require_once __DIR__ . '/../src/StandupEmailer.php';
require_once __DIR__ . '/../src/SummaryEmailer.php';

$config = require __DIR__ . '/../config/config.php';
$pdo    = getDb($config);

$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$teams  = getAllTeams($pdo);

foreach ($teams as $team) {
    $teamTz   = new DateTimeZone($team['timezone']);
    $nowLocal = $nowUtc->setTimezone($teamTz);
    $sendDate = $nowLocal->format('Y-m-d');

    // Feature 3: skip weekends in the team's local timezone.
    // format('N') = ISO 8601 day-of-week: 1=Mon … 7=Sun.
    $dayOfWeek = (int) $nowLocal->format('N');

    if ($dayOfWeek === 6 || $dayOfWeek === 7) {
        continue; // Saturday or Sunday — no emails for this team.
    }

    // ── Pass 1: Prompt emails ────────────────────────────────────────────────
    if (isTeamDue($team, $nowUtc)) {
        $developers = getDeveloperMembers($pdo, (int) $team['id']);

        foreach ($developers as $member) {
            $userId = (int) $member['id'];
            $teamId = (int) $team['id'];

            if (hasSentTokenToday($pdo, $teamId, $userId, $sendDate)) {
                continue; // Dedup — already sent for today.
            }

            $token = createStandupToken($pdo, $teamId, $userId, $sendDate, $nowUtc);

            if ($token === null) {
                continue; // UNIQUE collision — skip.
            }

            try {
                sendStandupPrompt($pdo, $config, $team, $member, $token, $sendDate);
            } catch (RuntimeException $e) {
                logCronError('[Prompt] Team ' . $team['id'] . ' user ' . $userId . ': ' . $e->getMessage());
            }
        }
    }

    // ── Pass 2: Summary emails ───────────────────────────────────────────────
    if (isSummaryDue($team, $nowUtc)) {
        try {
            sendSummaryEmail($pdo, $config, $team, $sendDate, $nowLocal);
        } catch (RuntimeException $e) {
            logCronError('[Summary] Team ' . $team['id'] . ': ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function logCronError(string $message): void
{
    $line = date('Y-m-d H:i:s') . ' [ERROR] ' . $message . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/standup-errors.log', $line, FILE_APPEND | LOCK_EX);
}
