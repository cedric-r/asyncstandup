<?php

declare(strict_types=1);

require_once __DIR__ . '/SubmissionRepository.php';
require_once __DIR__ . '/TeamsBot.php';

/**
 * Routes Bot Framework activity payloads to the appropriate handler.
 *
 * Supported activity types:
 *   - 'invoke'             : Adaptive Card submission (save answers, confirm DM)
 *   - 'conversationUpdate' : Save teams_conversation_ref for proactive DM (US-38)
 */
class BotActivityHandler
{
    public function __construct(
        private PDO   $pdo,
        private array $botConfig
    ) {}

    /**
     * Dispatch an incoming Bot Framework activity.
     *
     * @param  array<string, mixed> $activity
     * @return array{int, array<string, mixed>}  [HTTP status code, response body]
     */
    public function handle(array $activity): array
    {
        $type = (string) ($activity['type'] ?? '');

        return match ($type) {
            'invoke'             => $this->handleInvoke($activity),
            'conversationUpdate' => $this->handleConversationUpdate($activity),
            default              => [200, ['status' => 'ignored']],
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Invoke: Adaptive Card submission
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $activity
     * @return array{int, array<string, mixed>}
     */
    private function handleInvoke(array $activity): array
    {
        $value = $activity['value'] ?? [];
        if (!is_array($value)) {
            return [400, ['error' => 'Invalid value payload']];
        }

        $token = (string) ($value['token'] ?? '');
        if ($token === '') {
            return [400, ['error' => 'Missing token']];
        }

        $tokenRow = getTokenData($this->pdo, $token);
        if ($tokenRow === null) {
            return [404, ['error' => 'Token not found']];
        }

        // Replay guard: already submitted.
        if (!empty($tokenRow['used_at'])) {
            $this->sendConfirmationDm($activity, 'already_submitted');
            return [409, ['error' => 'Already submitted']];
        }

        // Extract answers: q_{questionId} => text.
        $answers = [];
        foreach ($value as $key => $answerValue) {
            if (preg_match('/^q_(\d+)$/', (string) $key, $m)) {
                $answers[(int) $m[1]] = (string) $answerValue;
            }
        }
        if (empty($answers)) {
            return [400, ['error' => 'No answers']];
        }

        // Resolve submitting user — prefer AAD lookup, fall back to token owner.
        $userId = $this->resolveUser($activity) ?? (int) $tokenRow['user_id'];

        // Save submission (also marks token used internally).
        saveSubmission(
            $this->pdo,
            (int) $tokenRow['id'],
            $userId,
            (int) $tokenRow['team_id'],
            $answers
        );

        // Retrieve the new submission ID.
        $stmt = $this->pdo->prepare(
            'SELECT id FROM standup_submissions WHERE token_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([(int) $tokenRow['id']]);
        $submissionId = (int) ($stmt->fetchColumn() ?: 0);

        $this->sendConfirmationDm($activity, 'submitted');

        return [200, ['status' => 'submitted', 'submission_id' => $submissionId]];
    }

    /**
     * Resolve the submitting user's DB id via their Teams AAD object id.
     * Returns null if the activity carries no aadObjectId or no matching user is found.
     */
    private function resolveUser(array $activity): ?int
    {
        $aadId = (string) ($activity['from']['aadObjectId'] ?? '');
        if ($aadId === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE teams_aad_id = ?');
        $stmt->execute([$aadId]);
        $row = $stmt->fetch();

        return $row !== false ? (int) $row['id'] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conversation update: persist conversation reference for proactive DM
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $activity
     * @return array{int, array<string, mixed>}
     */
    private function handleConversationUpdate(array $activity): array
    {
        $membersAdded = $activity['membersAdded'] ?? [];
        if (!is_array($membersAdded)) {
            return [200, ['status' => 'ok']];
        }

        foreach ($membersAdded as $member) {
            if (!is_array($member)) {
                continue;
            }
            $aadId = (string) ($member['aadObjectId'] ?? '');
            if ($aadId === '') {
                continue;
            }

            // Security: validate serviceUrl before storing — do NOT persist unvalidated URLs.
            $rawServiceUrl = (string) ($activity['serviceUrl'] ?? '');
            $safeServiceUrl = $this->sanitiseServiceUrl($rawServiceUrl);

            $convRef = json_encode([
                'serviceUrl'   => $safeServiceUrl,
                'conversation' => $activity['conversation'] ?? [],
                'bot'          => $activity['recipient']   ?? [],
                'channelId'    => (string) ($activity['channelId'] ?? 'msteams'),
            ], JSON_UNESCAPED_UNICODE);

            $this->pdo->prepare(
                'UPDATE users SET teams_conversation_ref = ? WHERE teams_aad_id = ?'
            )->execute([$convRef, $aadId]);
        }

        return [200, ['status' => 'ok']];
    }

    /**
     * Allow only known Bot Framework service URL prefixes.
     * Returns null and logs a warning if the URL does not match.
     */
    private function sanitiseServiceUrl(string $url): ?string
    {
        if (str_starts_with($url, 'https://smba.trafficmanager.net/')
            || str_starts_with($url, 'https://webchat.botframework.com/')
        ) {
            return $url;
        }

        if ($url !== '') {
            error_log("[AsyncStandUp] Rejected unvalidated serviceUrl: {$url}");
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Confirmation DM
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send a confirmation text DM back to the user via the Bot Framework REST API.
     * Failure is non-critical — the submission is already persisted.
     *
     * @param array<string, mixed> $activity
     */
    private function sendConfirmationDm(array $activity, string $status): void
    {
        try {
            $serviceUrl = (string) ($activity['serviceUrl'] ?? '');
            $convId     = (string) ($activity['conversation']['id'] ?? '');
            if ($serviceUrl === '' || $convId === '') {
                return;
            }

            $text = match ($status) {
                'submitted'         => '✅ Standup submitted! Thank you.',
                'already_submitted' => 'ℹ️ You have already submitted your standup for today.',
                default             => 'Done.',
            };

            $accessToken = getBotAccessToken($this->botConfig);
            $payload     = json_encode(
                ['type' => 'message', 'text' => $text],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $endpoint = rtrim($serviceUrl, '/') . "/v3/conversations/{$convId}/activities";

            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\n",
                'content'       => $payload,
                'timeout'       => 5,
                'ignore_errors' => true,
            ]]);
            @file_get_contents($endpoint, false, $ctx);
        } catch (\Throwable $e) {
            error_log('[AsyncStandUp] Confirmation DM failed: ' . $e->getMessage());
            // Non-critical — submission is already saved.
        }
    }
}
