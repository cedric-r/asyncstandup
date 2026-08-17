<?php

declare(strict_types=1);

/**
 * Return true if team's summary time (standup_time + 1 hour) is within 60 s of nowUtc.
 */
function isSummaryDue(array $team, DateTimeImmutable $nowUtc): bool
{
    $teamTz   = new DateTimeZone($team['timezone']);
    $nowLocal = $nowUtc->setTimezone($teamTz);

    $scheduledLocal = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $nowLocal->format('Y-m-d') . ' ' . substr((string) $team['standup_time'], 0, 5),
        $teamTz
    );

    if ($scheduledLocal === false) {
        return false;
    }

    $summaryLocal = $scheduledLocal->modify('+1 hour');
    $summaryUtc   = $summaryLocal->setTimezone(new DateTimeZone('UTC'));
    $diff         = abs($nowUtc->getTimestamp() - $summaryUtc->getTimestamp());

    return $diff < 60;
}

/**
 * Attempt to claim the summary_sent slot for this team + date.
 *
 * Uses INSERT IGNORE as the dedup guard. Returns true only if the row was newly
 * inserted — false means a summary was already sent (or is being sent concurrently).
 */
function attemptInsertSummaryLock(PDO $pdo, string $driver, int $teamId, string $sendDate): bool
{
    $sentAt   = gmdate('Y-m-d H:i:s'); // UTC — driver-portable alternative to UTC_TIMESTAMP()
    $inserted = dbInsertIgnore(
        $pdo,
        $driver,
        'summary_sent',
        ['team_id', 'send_date', 'sent_at'],
        [$teamId, $sendDate, $sentAt]
    );

    return $inserted > 0;
}

/**
 * Assemble summary data: developers, questions, and answer map.
 */
function assembleSummaryData(PDO $pdo, int $teamId, string $sendDate): array
{
    // All developer members.
    $devStmt = $pdo->prepare('
        SELECT u.id, u.display_name, u.email
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ? AND tm.is_developer = 1
        ORDER BY u.display_name
    ');
    $devStmt->execute([$teamId]);
    $developers = $devStmt->fetchAll();

    // Questions in order.
    $qStmt = $pdo->prepare('SELECT id, question FROM team_questions WHERE team_id = ? ORDER BY position');
    $qStmt->execute([$teamId]);
    $questions = $qStmt->fetchAll();

    // Submissions for today.
    $subStmt = $pdo->prepare('
        SELECT ss.user_id, a.question_id, a.answer
        FROM standup_tokens t
        JOIN standup_submissions ss ON ss.token_id = t.id
        JOIN standup_answers a ON a.submission_id = ss.id
        WHERE t.team_id = ? AND t.send_date = ?
    ');
    $subStmt->execute([$teamId, $sendDate]);
    $submissions = $subStmt->fetchAll();

    // Build answer map: $answerMap[$userId][$questionId] = answer.
    $answerMap = [];

    foreach ($submissions as $row) {
        $answerMap[(int) $row['user_id']][(int) $row['question_id']] = (string) $row['answer'];
    }

    return [
        'developers' => $developers,
        'questions'  => $questions,
        'answerMap'  => $answerMap,
    ];
}

/**
 * Send the daily summary email to all team recipients.
 *
 * Inserts summary_sent BEFORE sending to prevent double-send even if process crashes.
 * AC-6: if no recipients, inserts summary_sent row and returns (no emails sent, no error).
 */
/**
 * Return the merged, deduplicated list of summary email recipients.
 *
 * Unions external recipients (team_recipients table) with team members who have
 * is_recipient = 1. Deduplication is case-insensitive (strtolower + trim).
 *
 * @return array{email: string, display_name: string|null}[]
 */
/**
 * Ensure an external recipient has an unsubscribe token; generate + save if absent.
 *
 * Lazy-generation pattern: safe to call at send time.
 * Uses bin2hex(random_bytes(32)) — CSPRNG; idempotent (UPDATE WHERE id).
 *
 * @return string The 64-char hex unsubscribe token.
 */
function ensureUnsubscribeToken(PDO $pdo, int $recipientId): string
{
    $stmt = $pdo->prepare('SELECT id, unsubscribe_token FROM team_recipients WHERE id = ?');
    $stmt->execute([$recipientId]);
    $row = $stmt->fetch();

    if ($row !== false && !empty($row['unsubscribe_token'])) {
        return (string) $row['unsubscribe_token'];
    }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE team_recipients SET unsubscribe_token = ? WHERE id = ?')
        ->execute([$token, $recipientId]);

    return $token;
}

/**
 * Return all developer members for a team as potential summary recipients.
 *
 * Used when summary_to_all_developers = 1. These rows have no id or
 * unsubscribe_token because they have no team_recipients row.
 * Developers opt out via profile.php, not via an unsubscribe link.
 *
 * @return array{email: string, display_name: string|null, unsubscribe_token: null}[]
 */
function queryDeveloperMembers(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('
        SELECT u.email, u.display_name, NULL AS unsubscribe_token
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ? AND tm.is_developer = 1
    ');
    $stmt->execute([$teamId]);

    return $stmt->fetchAll();
}

/**
 * Return the merged, deduplicated list of summary email recipients.
 *
 * Sources (applied in priority order for dedup):
 *   1. External team_recipients rows (have unsubscribe token)
 *   2. is_recipient=1 team members
 *   3. All developer members (only when team.summary_to_all_developers = 1)
 *
 * Dedup is case-insensitive. External rows (with unsubscribe token) take
 * priority over developer-only entries when the same email appears in both.
 *
 * @param array $team Full team row (must include summary_to_all_developers).
 */
function getMergedRecipients(PDO $pdo, array $team): array
{
    $teamId = (int) $team['id'];

    // Source 1: explicit external recipients (include id + unsubscribe_token).
    $stmt = $pdo->prepare('SELECT id, email, display_name, unsubscribe_token FROM team_recipients WHERE team_id = ?');
    $stmt->execute([$teamId]);
    $external = $stmt->fetchAll();

    // Source 2: is_recipient=1 members.
    $stmt2 = $pdo->prepare('
        SELECT u.email, u.display_name, NULL AS unsubscribe_token
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ? AND tm.is_recipient = 1
    ');
    $stmt2->execute([$teamId]);
    $members = $stmt2->fetchAll();

    // Source 3: all developer members (only when flag is set).
    $developers = !empty($team['summary_to_all_developers'])
        ? queryDeveloperMembers($pdo, $teamId)
        : [];

    // Merge in priority order: external first (keeps unsubscribe token on dedup),
    // then is_recipient members, then developer-auto entries.
    $seen   = [];
    $merged = [];

    foreach (array_merge($external, $members, $developers) as $r) {
        $key = strtolower(trim((string) $r['email']));

        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $merged[]   = $r;
        }
    }

    return $merged;
}

function sendSummaryEmail(PDO $pdo, array $config, array $team, string $sendDate, DateTimeImmutable $nowLocal): void
{
    $teamId = (int) $team['id'];

    // Dedup guard — exit if already sent.
    if (!attemptInsertSummaryLock($pdo, $config['db']['driver'], $teamId, $sendDate)) {
        return;
    }

    // Load merged recipients (pass full $team for summary_to_all_developers flag access).
    $recipients = getMergedRecipients($pdo, $team);

    if (empty($recipients)) {
        return; // AC-6: no recipients — summary_sent row already inserted; no error.
    }

    $data         = assembleSummaryData($pdo, $teamId, $sendDate);
    $developers   = $data['developers'];
    $questions    = $data['questions'];
    $answerMap    = $data['answerMap'];
    $teamName     = $team['name'];

    // Build submission + non-submitter lists.
    $submitterData  = [];
    $nonSubmitters  = [];

    foreach ($developers as $dev) {
        $devId = (int) $dev['id'];

        if (isset($answerMap[$devId])) {
            $answers = [];

            foreach ($questions as $q) {
                $answers[(int) $q['id']] = $answerMap[$devId][(int) $q['id']] ?? '';
            }

            $submitterData[] = [
                'display_name' => $dev['display_name'] ?? $dev['email'],
                'answers'      => $answers,
            ];
        } else {
            $nonSubmitters[] = $dev['display_name'] ?? $dev['email'];
        }
    }

    $subject  = "AsyncStandUp Summary — {$teamName} ({$sendDate})";
    $appUrl   = rtrim($config['app_url'] ?? '', '/');

    foreach ($recipients as $recipient) {
        $to     = str_replace(["\r", "\n"], ' ', (string) $recipient['email']);
        $toName = str_replace(["\r", "\n"], ' ', (string) ($recipient['display_name'] ?? $to));

        // For external recipients (have an id column): ensure unsubscribe token + generate URL.
        // Unsubscribe URL logic:
        //   - External recipient with pre-generated token: use it directly
        //   - External recipient without token (legacy row): lazy-generate via ensureUnsubscribeToken()
        //   - Developer-auto recipient (no id): no unsubscribe URL (opt-out via profile)
        if (!empty($recipient['unsubscribe_token'])) {
            $unsubscribeUrl = $appUrl . '/unsubscribe.php?token=' . urlencode($recipient['unsubscribe_token']);
        } elseif (isset($recipient['id'])) {
            $unsub_token    = ensureUnsubscribeToken($pdo, (int) $recipient['id']);
            $unsubscribeUrl = $appUrl . '/unsubscribe.php?token=' . urlencode($unsub_token);
        } else {
            $unsubscribeUrl = null; // Developer-auto: opt out via profile.php
        }

        // Render body per recipient (unsubscribe URL differs per external recipient).
        ob_start();
        extract(compact('teamName', 'sendDate', 'questions', 'submitterData', 'nonSubmitters', 'unsubscribeUrl'), EXTR_SKIP);
        include __DIR__ . '/../templates/email/standup_summary.php';
        $body = (string) ob_get_clean();

        try {
            sendMail($config, $to, $toName, $subject, $body);
        } catch (RuntimeException $e) {
            $line = date('Y-m-d H:i:s') . ' [ERROR] [Summary] Team ' . $teamId . ' recipient ' . $to . ': ' . $e->getMessage() . PHP_EOL;
            file_put_contents(__DIR__ . '/../logs/standup-errors.log', $line, FILE_APPEND | LOCK_EX);
        }
    }
}
