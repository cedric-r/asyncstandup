<?php

declare(strict_types=1);

require_once __DIR__ . '/OrgRepository.php';

/**
 * Return all active teams from the DB.
 */
function getAllTeams(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT * FROM teams WHERE status = 'active'");
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Return true if team's standup_time (in team timezone) is within 60 s of nowUtc.
 */
function isTeamDue(array $team, DateTimeImmutable $nowUtc): bool
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

    $scheduledUtc = $scheduledLocal->setTimezone(new DateTimeZone('UTC'));
    $diff         = abs($nowUtc->getTimestamp() - $scheduledUtc->getTimestamp());

    return $diff < 60;
}

/**
 * Return true if a standup token already exists for this team+user+date.
 */
function hasSentTokenToday(PDO $pdo, int $teamId, int $userId, string $sendDate): bool
{
    $stmt = $pdo->prepare('
        SELECT id FROM standup_tokens
        WHERE team_id = ? AND user_id = ? AND send_date = ?
        LIMIT 1
    ');
    $stmt->execute([$teamId, $userId, $sendDate]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Create a standup token for a member and return the token string.
 *
 * Silently ignores a UNIQUE collision (race condition).
 *
 * @return string|null Token, or null if a collision occurred.
 */
function createStandupToken(PDO $pdo, int $teamId, int $userId, string $sendDate, DateTimeImmutable $nowUtc): ?string
{
    $token     = bin2hex(random_bytes(32));
    $sentAt    = $nowUtc->format('Y-m-d H:i:s');
    $expiresAt = $nowUtc->modify('+48 hours')->format('Y-m-d H:i:s');

    try {
        $pdo->prepare('
            INSERT INTO standup_tokens (team_id, user_id, token, send_date, sent_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$teamId, $userId, $token, $sendDate, $sentAt, $expiresAt]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return null; // UNIQUE collision — already sent by concurrent process
        }

        throw $e;
    }

    return $token;
}

/**
 * Load developer members for a team.
 */
function getDeveloperMembers(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('
        SELECT u.id, u.email, u.display_name
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ? AND tm.is_developer = 1
    ');
    $stmt->execute([$teamId]);

    return $stmt->fetchAll();
}

/**
 * Send a standup prompt email to a single developer.
 *
 * @throws RuntimeException on SMTP failure.
 */
function sendStandupPrompt(PDO $pdo, array $config, array $team, array $member, string $token, string $sendDate): void
{
    $standupUrl = rtrim($config['app_url'], '/') . '/submit.php?token=' . urlencode($token);

    // Load team questions.
    $stmt = $pdo->prepare('SELECT question FROM team_questions WHERE team_id = ? ORDER BY position ASC');
    $stmt->execute([$team['id']]);
    $questions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $userName     = $member['display_name'] ?? $member['email'];
    $teamName     = $team['name'];
    $teamTimezone = $team['timezone'];

    // Load org name for email subject and body (Feature 2).
    $orgRow  = getOrgById($pdo, (int) $team['org_id']);
    $orgName = $orgRow['name'] ?? '';

    ob_start();
    extract(compact('userName', 'teamName', 'orgName', 'standupUrl', 'sendDate', 'teamTimezone', 'questions'), EXTR_SKIP);
    include __DIR__ . '/../templates/email/standup_prompt.php';
    $body = (string) ob_get_clean();

    sendMail(
        $config,
        $member['email'],
        $userName,
        "[{$orgName}] {$teamName} — Daily Standup for {$sendDate}",
        $body
    );
}

/**
 * Return tokens that should receive a reminder:
 *   - not yet submitted (used_at IS NULL)
 *   - not yet reminded (reminder_sent_at IS NULL)
 *   - expires within the next 2 hours from $nowUtc
 *
 * @return array{id: int, token: string, user_id: int, team_id: int,
 *               send_date: string, expires_at: string,
 *               email: string, display_name: string|null,
 *               team_name: string, timezone: string}[]
 */
function getPendingUnremindedTokens(PDO $pdo, DateTimeImmutable $nowUtc): array
{
    $nowStr    = $nowUtc->format('Y-m-d H:i:s');
    $windowStr = $nowUtc->modify('+2 hours')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        SELECT st.id, st.token, st.user_id, st.team_id, st.send_date, st.expires_at,
               u.email, u.display_name, t.name AS team_name, t.timezone
        FROM standup_tokens st
        JOIN users u  ON u.id  = st.user_id
        JOIN teams t  ON t.id  = st.team_id
        WHERE st.used_at          IS NULL
          AND st.reminder_sent_at IS NULL
          AND st.expires_at > ?
          AND st.expires_at <= ?
    ');
    $stmt->execute([$nowStr, $windowStr]);

    return $stmt->fetchAll();
}

/**
 * Mark a token as having had a reminder sent.
 */
function markReminderSent(PDO $pdo, int $tokenId, DateTimeImmutable $nowUtc): void
{
    $pdo->prepare('UPDATE standup_tokens SET reminder_sent_at = ? WHERE id = ?')
        ->execute([$nowUtc->format('Y-m-d H:i:s'), $tokenId]);
}

/**
 * Send a submission reminder email for a single token.
 *
 * @param array{id: int, token: string, user_id: int, team_id: int,
 *              send_date: string, expires_at: string,
 *              email: string, display_name: string|null,
 *              team_name: string, timezone: string} $token
 */
function sendSubmissionReminder(PDO $pdo, array $config, array $token, DateTimeImmutable $nowUtc): void
{
    $to           = str_replace(["\r", "\n"], ' ', (string) $token['email']);
    $toName       = str_replace(["\r", "\n"], ' ', (string) ($token['display_name'] ?? $to));
    $userName     = $token['display_name'] ?? $to;
    $teamName     = $token['team_name'];
    $teamTimezone = $token['timezone'];
    $standupUrl   = rtrim($config['app_url'], '/') . '/submit.php?token=' . urlencode($token['token']);
    $subject      = "Reminder: submit your standup for {$teamName}";

    // Format expires_at (stored as UTC string) in team's local timezone.
    $expiresAt = (new DateTimeImmutable($token['expires_at'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($teamTimezone))
        ->format('H:i T');

    ob_start();
    extract(compact('userName', 'teamName', 'standupUrl', 'expiresAt', 'teamTimezone'), EXTR_SKIP);
    include __DIR__ . '/../templates/email/standup_reminder.php';
    $body = (string) ob_get_clean();

    sendMail($config, $to, $toName, $subject, $body);
}
