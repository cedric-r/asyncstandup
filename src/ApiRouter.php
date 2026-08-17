<?php

declare(strict_types=1);

/**
 * Main API dispatcher.
 *
 * Strips the /api/v1 prefix and routes to the correct handler.
 *
 * @param array<string, mixed> $apiKey
 */
function routeApi(string $method, string $path, PDO $pdo, array $apiKey): never
{
    // Normalise: strip /api/v1 prefix and trailing slash.
    $path = rtrim((string) preg_replace('#^/api/v1#', '', $path), '/');

    if ($method === 'GET' && $path === '/teams') {
        handleGetTeams($pdo, $apiKey);
    }

    if ($method === 'GET' && preg_match('#^/teams/(\d+)/questions$#', $path, $m)) {
        handleGetQuestions($pdo, $apiKey, (int) $m[1]);
    }

    if ($method === 'GET' && preg_match('#^/teams/(\d+)/submissions$#', $path, $m)) {
        handleGetSubmissions($pdo, $apiKey, (int) $m[1]);
    }

    if ($method === 'GET' && preg_match('#^/teams/(\d+)/submissions/(\d+)$#', $path, $m)) {
        handleGetSubmission($pdo, $apiKey, (int) $m[1], (int) $m[2]);
    }

    if ($method === 'POST' && preg_match('#^/teams/(\d+)/submissions$#', $path, $m)) {
        handlePostSubmission($pdo, $apiKey, (int) $m[1]);
    }

    apiError('Not found', 404);
}

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/**
 * GET /teams
 *
 * @param array<string, mixed> $apiKey
 */
function handleGetTeams(PDO $pdo, array $apiKey): never
{
    $teams = getTeamsForUser($pdo, (int) $apiKey['user_id']);

    $out = array_map(static fn($t) => [
        'id'           => (int) $t['id'],
        'name'         => $t['name'],
        'timezone'     => $t['timezone'],
        'standup_time' => substr((string) $t['standup_time'], 0, 5),
        'org_name'     => $t['org_name'] ?? null,
    ], $teams);

    apiOk($out);
}

/**
 * GET /teams/{id}/questions
 *
 * @param array<string, mixed> $apiKey
 */
function handleGetQuestions(PDO $pdo, array $apiKey, int $teamId): never
{
    if (!isTeamMember($pdo, $teamId, (int) $apiKey['user_id'])) {
        apiError('forbidden', 403);
    }

    $questions = getQuestions($pdo, $teamId);

    apiOk(array_map(static fn($q) => [
        'id'       => (int) $q['id'],
        'question' => $q['question'],
        'position' => (int) $q['position'],
    ], $questions));
}

/**
 * GET /teams/{id}/submissions
 *
 * @param array<string, mixed> $apiKey
 */
function handleGetSubmissions(PDO $pdo, array $apiKey, int $teamId): never
{
    if (!isTeamMember($pdo, $teamId, (int) $apiKey['user_id'])) {
        apiError('forbidden', 403);
    }

    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
    $from    = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from'])
               ? (string) $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
    $to      = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['to'])
               ? (string) $_GET['to']   : date('Y-m-d');

    $rows = getResponseData($pdo, $teamId, null, null, $from, $to);

    // Pivot flat rows into per-submission objects keyed by submission_id.
    $bySubmission = [];
    foreach ($rows as $row) {
        $sid = (int) ($row['submission_id'] ?? 0);
        if ($sid === 0) {
            continue; // token exists but not submitted
        }
        if (!isset($bySubmission[$sid])) {
            $bySubmission[$sid] = [
                'submission_id' => $sid,
                'send_date'     => $row['send_date'],
                'user_id'       => (int) $row['user_id'],
                'display_name'  => $row['display_name'],
                'answers'       => [],
            ];
        }
        if ($row['question_id'] !== null) {
            $bySubmission[$sid]['answers'][] = [
                'question_id' => (int) $row['question_id'],
                'question'    => $row['question'],
                'answer'      => $row['answer'] ?? '',
            ];
        }
    }

    $all   = array_values($bySubmission);
    $total = count($all);
    $items = array_slice($all, ($page - 1) * $perPage, $perPage);

    apiList($items, $page, $perPage, $total);
}

/**
 * GET /teams/{id}/submissions/{sid}
 *
 * @param array<string, mixed> $apiKey
 */
function handleGetSubmission(PDO $pdo, array $apiKey, int $teamId, int $submissionId): never
{
    if (!isTeamMember($pdo, $teamId, (int) $apiKey['user_id'])) {
        apiError('forbidden', 403);
    }

    // Load submission and verify it belongs to this team.
    $stmt = $pdo->prepare('
        SELECT ss.id AS submission_id, ss.user_id, ss.team_id,
               t.send_date, u.display_name
        FROM standup_submissions ss
        JOIN standup_tokens t ON t.id = ss.token_id
        JOIN users u          ON u.id = ss.user_id
        WHERE ss.id = ? AND ss.team_id = ?
    ');
    $stmt->execute([$submissionId, $teamId]);
    $sub = $stmt->fetch();

    if ($sub === false) {
        apiError('not found', 404);
    }

    // Load answers.
    $aStmt = $pdo->prepare('
        SELECT q.id AS question_id, q.question, q.position, a.answer
        FROM standup_answers a
        JOIN team_questions q ON q.id = a.question_id
        WHERE a.submission_id = ?
        ORDER BY q.position ASC
    ');
    $aStmt->execute([$submissionId]);
    $answers = $aStmt->fetchAll();

    apiOk([
        'submission_id' => (int) $sub['submission_id'],
        'send_date'     => $sub['send_date'],
        'user_id'       => (int) $sub['user_id'],
        'display_name'  => $sub['display_name'],
        'answers'       => array_map(static fn($a) => [
            'question_id' => (int) $a['question_id'],
            'question'    => $a['question'],
            'answer'      => $a['answer'],
        ], $answers),
    ]);
}

/**
 * POST /teams/{id}/submissions
 *
 * @param array<string, mixed> $apiKey
 */
function handlePostSubmission(PDO $pdo, array $apiKey, int $teamId): never
{
    $userId = (int) $apiKey['user_id'];

    if (!isDeveloperMember($pdo, $teamId, $userId)) {
        apiError('forbidden', 403);
    }

    $rawBody = file_get_contents('php://input');
    $body    = json_decode((string) $rawBody, true);

    if (!is_array($body) || !isset($body['answers']) || !is_array($body['answers'])) {
        apiError('invalid body — expected {"answers":[{"question_id":N,"text":"..."}]}', 400);
    }

    // Build answers map [question_id => text].
    $answersMap = [];
    foreach ($body['answers'] as $a) {
        if (!isset($a['question_id']) || !is_int($a['question_id'])) {
            apiError('each answer must have an integer question_id', 400);
        }
        $answersMap[(int) $a['question_id']] = (string) ($a['text'] ?? '');
    }

    $nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $sendDate = $nowUtc->format('Y-m-d');

    // Re-fetch existing token for today if it exists (do NOT rely on INSERT IGNORE to return the id).
    $tokenStmt = $pdo->prepare('SELECT id FROM standup_tokens WHERE team_id = ? AND user_id = ? AND send_date = ?');
    $tokenStmt->execute([$teamId, $userId, $sendDate]);
    $existingToken = $tokenStmt->fetch();

    if ($existingToken !== false) {
        $tokenId = (int) $existingToken['id'];
    } else {
        // createStandupToken() returns the hex token string, not the row id.
        $tokenString = createStandupToken($pdo, $teamId, $userId, $sendDate, $nowUtc);
        if ($tokenString === null) {
            apiError('could not create submission token', 500);
        }
        // Fetch the integer PK for use as FK in saveSubmission().
        $idStmt = $pdo->prepare('SELECT id FROM standup_tokens WHERE team_id = ? AND user_id = ? AND send_date = ?');
        $idStmt->execute([$teamId, $userId, $sendDate]);
        $tokenId = (int) $idStmt->fetchColumn();
    }

    // Check not already submitted.
    $dupStmt = $pdo->prepare('SELECT id FROM standup_submissions WHERE token_id = ?');
    $dupStmt->execute([$tokenId]);
    if ($dupStmt->fetch() !== false) {
        apiError('standup already submitted for today', 409);
    }

    saveSubmission($pdo, $tokenId, $userId, $teamId, $answersMap);

    // Retrieve the new submission id.
    $sidStmt = $pdo->prepare('SELECT id FROM standup_submissions WHERE token_id = ?');
    $sidStmt->execute([$tokenId]);
    $sid = (int) $sidStmt->fetchColumn();

    apiOk(['submission_id' => $sid], 201);
}
