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
