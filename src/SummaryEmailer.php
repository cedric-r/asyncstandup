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
function attemptInsertSummaryLock(PDO $pdo, int $teamId, string $sendDate): bool
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO summary_sent (team_id, send_date, sent_at) VALUES (?, ?, UTC_TIMESTAMP())'
    );
    $stmt->execute([$teamId, $sendDate]);

    return $stmt->rowCount() > 0;
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

function getMergedRecipients(PDO $pdo, int $teamId): array
{
    // External recipients — include id and unsubscribe_token for lazy generation at send time.
    $stmt = $pdo->prepare('SELECT id, email, display_name, unsubscribe_token FROM team_recipients WHERE team_id = ?');
    $stmt->execute([$teamId]);
    $external = $stmt->fetchAll();

    // Member recipients (is_recipient = 1).
    $stmt2 = $pdo->prepare('
        SELECT u.email, u.display_name
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ? AND tm.is_recipient = 1
    ');
    $stmt2->execute([$teamId]);
    $members = $stmt2->fetchAll();

    // Merge and deduplicate case-insensitively.
    $seen   = [];
    $merged = [];

    foreach (array_merge($external, $members) as $r) {
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
    if (!attemptInsertSummaryLock($pdo, $teamId, $sendDate)) {
        return;
    }

    // Load merged recipients: external (team_recipients) + member recipients (is_recipient=1).
    $recipients = getMergedRecipients($pdo, $teamId);

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
        $unsubscribeUrl = null;
        if (isset($recipient['id'])) {
            $unsub_token    = ensureUnsubscribeToken($pdo, (int) $recipient['id']);
            $unsubscribeUrl = $appUrl . '/unsubscribe.php?token=' . urlencode($unsub_token);
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
