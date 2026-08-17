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

/**
 * Fetch raw standup response rows for a team, with optional date and member filters.
 *
 * All four view modes (default 7-day, by_date, by_member, single) are handled
 * by the same query — $date and $memberId control which rows are returned.
 *
 * Returns a flat array of rows; caller assembles into $data[$send_date][$user_id].
 *
 * @param string $dateFrom Inclusive lower bound in Y-m-d format.
 * @param string $dateTo   Inclusive upper bound in Y-m-d format.
 */
function getResponseData(
    PDO $pdo,
    int $teamId,
    ?string $date,
    ?int $memberId,
    string $dateFrom,
    string $dateTo
): array {
    $params = [':teamId' => $teamId];
    $where  = ['t.team_id = :teamId', 'tm.is_developer = 1'];

    if ($date !== null) {
        $where[]            = 't.send_date = :date';
        $params[':date']    = $date;
    } else {
        $where[]            = 't.send_date BETWEEN :dateFrom AND :dateTo';
        $params[':dateFrom'] = $dateFrom;
        $params[':dateTo']   = $dateTo;
    }

    if ($memberId !== null) {
        $where[]              = 't.user_id = :memberId';
        $params[':memberId']  = $memberId;
    }

    $sql = '
        SELECT
            t.send_date,
            t.user_id,
            u.display_name,
            t.id         AS token_id,
            ss.id        AS submission_id,
            q.id         AS question_id,
            q.question,
            q.position,
            a.answer
        FROM standup_tokens t
        JOIN users u             ON u.id = t.user_id
        JOIN team_members tm     ON tm.team_id = t.team_id AND tm.user_id = t.user_id
        LEFT JOIN standup_submissions ss ON ss.token_id = t.id
        LEFT JOIN standup_answers a      ON a.submission_id = ss.id
        LEFT JOIN team_questions q       ON q.id = a.question_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.send_date DESC, u.display_name ASC, q.position ASC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Return all unexpired, unsubmitted standup tokens for a developer user.
 *
 * Surfaces tokens where:
 *   - used_at IS NULL (not yet submitted)
 *   - expires_at > now (not expired; datetime() wrapper for SQLite compat)
 *   - team_members.is_developer = 1 for this user on this team
 *
 * The 48-hour expiry window intentionally allows late submissions from
 * the previous send_date to surface — consistent with the US-6 token spec.
 *
 * @return array{token: string, send_date: string, team_name: string, timezone: string}[]
 */
function getPendingTokensForUser(PDO $pdo, int $userId): array
{
    // PHP-computed UTC timestamp: avoids datetime('now') which is SQLite-only;
    // plain string comparison works correctly in MySQL (DATETIME vs string)
    // and SQLite (TEXT vs TEXT) when the column stores ISO 8601 UTC strings.
    $nowUtc = gmdate('Y-m-d H:i:s');

    $stmt = $pdo->prepare('
        SELECT st.token, st.send_date, t.name AS team_name, t.timezone
        FROM standup_tokens st
        JOIN teams t         ON t.id  = st.team_id
        JOIN team_members tm ON tm.team_id = st.team_id
                             AND tm.user_id = st.user_id
        WHERE st.user_id = ?
          AND st.used_at IS NULL
          AND st.expires_at > ?
          AND tm.is_developer = 1
          AND t.status = \'active\'
        ORDER BY t.name ASC
    ');

    $stmt->execute([$userId, $nowUtc]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Return daily average mood scores for a team over a date range.
 *
 * @return array{send_date: string, avg_score: string, responses: int}[]
 */
function getMoodTrend(PDO $pdo, int $teamId, string $dateFrom, string $dateTo): array
{
    $stmt = $pdo->prepare('
        SELECT t.send_date, AVG(ms.score) AS avg_score, COUNT(ms.id) AS responses
        FROM standup_tokens t
        JOIN standup_submissions ss ON ss.token_id = t.id
        JOIN standup_mood_scores ms ON ms.submission_id = ss.id
        JOIN team_questions q       ON q.id = ms.question_id
        WHERE t.team_id = ?
          AND t.send_date BETWEEN ? AND ?
          AND q.is_mood = 1
        GROUP BY t.send_date
        ORDER BY t.send_date ASC
    ');
    $stmt->execute([$teamId, $dateFrom, $dateTo]);

    return $stmt->fetchAll();
}
