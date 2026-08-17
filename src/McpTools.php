<?php

declare(strict_types=1);

/**
 * MCP tool definitions and implementations for AsyncStandUp.
 *
 * Each public method maps to one MCP tool. Thin wrappers over existing
 * repository functions — no direct SQL except where a specific join is needed.
 */
class McpTools
{
    private PDO   $pdo;
    private int   $userId;

    /** @param array<string, mixed> $apiKey */
    public function __construct(PDO $pdo, array $apiKey)
    {
        $this->pdo    = $pdo;
        $this->userId = (int) $apiKey['user_id'];
    }

    // -----------------------------------------------------------------------
    // Tool registry
    // -----------------------------------------------------------------------

    /**
     * Return the MCP tool definitions for the tools/list response.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getToolDefinitions(): array
    {
        return [
            [
                'name'        => 'list_teams',
                'description' => 'List standup teams the authenticated user is a member of.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'list_questions',
                'description' => 'List standup questions for a team.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['team_id' => ['type' => 'integer']],
                    'required'   => ['team_id'],
                ],
            ],
            [
                'name'        => 'get_submissions',
                'description' => 'Recent standup submissions for a team.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'team_id'   => ['type' => 'integer'],
                        'limit'     => ['type' => 'integer', 'default' => 10],
                        'from_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required' => ['team_id'],
                ],
            ],
            [
                'name'        => 'get_submission',
                'description' => 'Get a single standup submission with all answers.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['submission_id' => ['type' => 'integer']],
                    'required'   => ['submission_id'],
                ],
            ],
            [
                'name'        => 'submit_standup',
                'description' => "Submit today's standup answers for a team.",
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'team_id' => ['type' => 'integer'],
                        'answers' => [
                            'type'  => 'array',
                            'items' => [
                                'type'       => 'object',
                                'properties' => [
                                    'question_id' => ['type' => 'integer'],
                                    'text'        => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                    'required' => ['team_id', 'answers'],
                ],
            ],
            [
                'name'        => 'get_team_stats',
                'description' => '30-day participation stats for a team.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['team_id' => ['type' => 'integer']],
                    'required'   => ['team_id'],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Dispatcher
    // -----------------------------------------------------------------------

    /**
     * Dispatch a tool call by name.
     *
     * @param array<string, mixed> $args
     * @return array<mixed>
     * @throws \InvalidArgumentException for unknown tool names
     * @throws \RuntimeException for forbidden access
     */
    public function call(string $toolName, array $args): array
    {
        return match ($toolName) {
            'list_teams'      => $this->listTeams(),
            'list_questions'  => $this->listQuestions((int) ($args['team_id'] ?? 0)),
            'get_submissions' => $this->getSubmissions(
                (int) ($args['team_id'] ?? 0),
                (int) ($args['limit'] ?? 10),
                isset($args['from_date']) ? (string) $args['from_date'] : null
            ),
            'get_submission'  => $this->getSubmission((int) ($args['submission_id'] ?? 0)),
            'submit_standup'  => $this->submitStandup(
                (int) ($args['team_id'] ?? 0),
                is_array($args['answers'] ?? null) ? $args['answers'] : []
            ),
            'get_team_stats'  => $this->getTeamStats((int) ($args['team_id'] ?? 0)),
            default           => throw new \InvalidArgumentException("Unknown tool: $toolName"),
        };
    }

    // -----------------------------------------------------------------------
    // Implementations
    // -----------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function listTeams(): array
    {
        $teams = getTeamsForUser($this->pdo, $this->userId);

        return array_map(static fn($t) => [
            'id'           => (int) $t['id'],
            'name'         => $t['name'],
            'timezone'     => $t['timezone'],
            'standup_time' => substr((string) $t['standup_time'], 0, 5),
            'org_name'     => $t['org_name'] ?? null,
        ], $teams);
    }

    /** @return array<int, array<string, mixed>> */
    private function listQuestions(int $teamId): array
    {
        $this->assertMember($teamId);

        return array_map(static fn($q) => [
            'id'       => (int) $q['id'],
            'question' => $q['question'],
            'position' => (int) $q['position'],
        ], getQuestions($this->pdo, $teamId));
    }

    /** @return array<int, array<string, mixed>> */
    private function getSubmissions(int $teamId, int $limit, ?string $fromDate): array
    {
        $this->assertMember($teamId);

        $from = ($fromDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate))
                ? $fromDate
                : date('Y-m-d', strtotime('-30 days'));
        $to   = date('Y-m-d');

        $rows = getResponseData($this->pdo, $teamId, null, null, $from, $to);

        // Pivot flat rows into per-submission objects.
        $bySubmission = [];
        foreach ($rows as $row) {
            $sid = (int) ($row['submission_id'] ?? 0);
            if ($sid === 0) {
                continue;
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

        return array_slice(array_values($bySubmission), 0, max(1, $limit));
    }

    /** @return array<string, mixed> */
    private function getSubmission(int $submissionId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT ss.id AS submission_id, ss.user_id, ss.team_id,
                   t.send_date, u.display_name
            FROM standup_submissions ss
            JOIN standup_tokens t ON t.id = ss.token_id
            JOIN users u          ON u.id = ss.user_id
            WHERE ss.id = ?
        ');
        $stmt->execute([$submissionId]);
        $sub = $stmt->fetch();

        if ($sub === false) {
            throw new \RuntimeException('Submission not found');
        }

        $this->assertMember((int) $sub['team_id']);

        $aStmt = $this->pdo->prepare('
            SELECT q.id AS question_id, q.question, q.position, a.answer
            FROM standup_answers a
            JOIN team_questions q ON q.id = a.question_id
            WHERE a.submission_id = ?
            ORDER BY q.position ASC
        ');
        $aStmt->execute([$submissionId]);

        return [
            'submission_id' => (int) $sub['submission_id'],
            'send_date'     => $sub['send_date'],
            'user_id'       => (int) $sub['user_id'],
            'display_name'  => $sub['display_name'],
            'answers'       => array_map(static fn($a) => [
                'question_id' => (int) $a['question_id'],
                'question'    => $a['question'],
                'answer'      => $a['answer'],
            ], $aStmt->fetchAll()),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $answers
     * @return array<string, int>
     */
    private function submitStandup(int $teamId, array $answers): array
    {
        if (!isDeveloperMember($this->pdo, $teamId, $this->userId)) {
            throw new \RuntimeException('forbidden — developer membership required');
        }

        // Build [question_id => text] map.
        $answersMap = [];
        foreach ($answers as $a) {
            $answersMap[(int) ($a['question_id'] ?? 0)] = (string) ($a['text'] ?? '');
        }

        $nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sendDate = $nowUtc->format('Y-m-d');

        // Re-fetch existing token; do NOT assume createStandupToken returns an int PK.
        $tokenStmt = $this->pdo->prepare(
            'SELECT id FROM standup_tokens WHERE team_id = ? AND user_id = ? AND send_date = ?'
        );
        $tokenStmt->execute([$teamId, $this->userId, $sendDate]);
        $existing = $tokenStmt->fetch();

        if ($existing !== false) {
            $tokenId = (int) $existing['id'];
        } else {
            $tokenString = createStandupToken($this->pdo, $teamId, $this->userId, $sendDate, $nowUtc);
            if ($tokenString === null) {
                throw new \RuntimeException('could not create submission token');
            }
            // Fetch the integer PK — createStandupToken() returns the hex string, not the row id.
            $idStmt = $this->pdo->prepare(
                'SELECT id FROM standup_tokens WHERE team_id = ? AND user_id = ? AND send_date = ?'
            );
            $idStmt->execute([$teamId, $this->userId, $sendDate]);
            $tokenId = (int) $idStmt->fetchColumn();
        }

        // Guard against duplicate submission.
        $dupStmt = $this->pdo->prepare('SELECT id FROM standup_submissions WHERE token_id = ?');
        $dupStmt->execute([$tokenId]);
        if ($dupStmt->fetch() !== false) {
            throw new \RuntimeException('standup already submitted for today');
        }

        saveSubmission($this->pdo, $tokenId, $this->userId, $teamId, $answersMap);

        $sidStmt = $this->pdo->prepare('SELECT id FROM standup_submissions WHERE token_id = ?');
        $sidStmt->execute([$tokenId]);

        return ['submission_id' => (int) $sidStmt->fetchColumn()];
    }

    /** @return array<mixed> */
    private function getTeamStats(int $teamId): array
    {
        $this->assertMember($teamId);

        return getParticipationStats(
            $this->pdo,
            $teamId,
            date('Y-m-d', strtotime('-30 days')),
            date('Y-m-d')
        );
    }

    // -----------------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------------

    /** @throws \RuntimeException if the authenticated user is not a team member */
    private function assertMember(int $teamId): void
    {
        if (!isTeamMember($this->pdo, $teamId, $this->userId)) {
            throw new \RuntimeException('forbidden');
        }
    }
}
