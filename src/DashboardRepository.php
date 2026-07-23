<?php

declare(strict_types=1);

/**
 * Return all teams the user belongs to (any role).
 */
function getTeamsForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT t.*, o.name AS org_name, tm.is_owner, tm.is_developer, tm.is_recipient
        FROM team_members tm
        JOIN teams t ON t.id = tm.team_id
        JOIN organisations o ON o.id = t.org_id
        WHERE tm.user_id = ?
        ORDER BY o.name, t.name
    ');
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

/**
 * Build the 7-day participation grid for a team.
 *
 * Grid: $grid[$userId][$date] = 'submitted' | 'sent_not_submitted' | 'not_sent'
 *
 * @param string[] $days Seven date strings in Y-m-d format (oldest first).
 */
function getTeamGrid(PDO $pdo, int $teamId, array $days): array
{
    if (empty($days)) {
        return [];
    }

    $dateFrom = $days[0];
    $dateTo   = $days[count($days) - 1];

    $stmt = $pdo->prepare('
        SELECT
            u.id          AS user_id,
            u.display_name,
            t.send_date,
            t.id          AS token_id,
            s.id          AS submission_id
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        LEFT JOIN standup_tokens t ON t.user_id = tm.user_id
            AND t.team_id = :team_id
            AND t.send_date BETWEEN :date_from AND :date_to
        LEFT JOIN standup_submissions s ON s.token_id = t.id
        WHERE tm.team_id = :team_id2
          AND tm.is_developer = 1
        ORDER BY u.display_name, t.send_date
    ');
    $stmt->execute([
        ':team_id'   => $teamId,
        ':date_from' => $dateFrom,
        ':date_to'   => $dateTo,
        ':team_id2'  => $teamId,
    ]);
    $rows = $stmt->fetchAll();

    $grid  = [];
    $names = [];

    // Initialise all cells to 'not_sent'.
    foreach ($rows as $row) {
        $uid = (int) $row['user_id'];

        if (!isset($grid[$uid])) {
            $grid[$uid]  = array_fill_keys($days, 'not_sent');
            $names[$uid] = $row['display_name'] ?? '';
        }
    }

    // Fill in cells where a token was sent.
    foreach ($rows as $row) {
        if ($row['send_date'] === null) {
            continue;
        }

        $uid  = (int) $row['user_id'];
        $date = $row['send_date'];

        if (!in_array($date, $days, true)) {
            continue;
        }

        $grid[$uid][$date] = ($row['submission_id'] !== null) ? 'submitted' : 'sent_not_submitted';
    }

    return ['grid' => $grid, 'names' => $names];
}

/**
 * Load 30-day participation stats per member.
 *
 * Returns: $stats[$userId] = ['sent' => int, 'submitted' => int]
 */
function getParticipationStats(PDO $pdo, int $teamId, string $dateFrom, string $dateTo): array
{
    $stmt = $pdo->prepare('
        SELECT
            t.user_id,
            COUNT(t.id)  AS sent_count,
            COUNT(s.id)  AS submitted_count
        FROM standup_tokens t
        LEFT JOIN standup_submissions s ON s.token_id = t.id
        WHERE t.team_id = ?
          AND t.send_date BETWEEN ? AND ?
        GROUP BY t.user_id
    ');
    $stmt->execute([$teamId, $dateFrom, $dateTo]);
    $rows  = $stmt->fetchAll();
    $stats = [];

    foreach ($rows as $row) {
        $stats[(int) $row['user_id']] = [
            'sent'      => (int) $row['sent_count'],
            'submitted' => (int) $row['submitted_count'],
        ];
    }

    return $stats;
}
