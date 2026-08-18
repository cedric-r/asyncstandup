<?php

declare(strict_types=1);

/**
 * Acquire a Bot Framework OAuth 2.0 access token via client credentials grant.
 *
 * Uses a file-based JSON cache (no APCu required). Token is refreshed 60 seconds
 * before expiry to prevent in-flight failures near the boundary.
 *
 * @param array<string, string> $botConfig  Keys: app_id, app_secret
 * @throws \RuntimeException on network failure or empty token response
 */
function getBotAccessToken(array $botConfig): string
{
    $cacheFile = sys_get_temp_dir() . '/asyncstandup_bot_token.json';

    if (file_exists($cacheFile)) {
        $raw    = file_get_contents($cacheFile);
        $cached = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($cached) && isset($cached['expires_at'], $cached['access_token'])
            && $cached['expires_at'] > time() + 60
        ) {
            return (string) $cached['access_token'];
        }
    }

    $payload = http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $botConfig['app_id']     ?? '',
        'client_secret' => $botConfig['app_secret']  ?? '',
        'scope'         => 'https://api.botframework.com/.default',
    ]);

    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 10,
    ]]);

    $url  = 'https://login.microsoftonline.com/botframework.com/oauth2/v2.0/token';
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new \RuntimeException('Failed to fetch bot access token');
    }

    $data  = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $token = (string) ($data['access_token'] ?? '');
    if ($token === '') {
        throw new \RuntimeException('Empty access token: ' . $body);
    }

    $cacheData = json_encode([
        'access_token' => $token,
        'expires_at'   => time() + (int) ($data['expires_in'] ?? 3600),
    ]);
    file_put_contents($cacheFile, $cacheData, LOCK_EX);
    // Restrict read access to current process user where the OS supports it.
    @chmod($cacheFile, 0600);

    return $token;
}

/**
 * Build an Adaptive Card v1.4 standup prompt for delivery via Bot Framework proactive DM.
 *
 * @param array<string, mixed>             $team      teams row (name, timezone, standup_time)
 * @param array<int, array<string, mixed>> $questions rows with 'id' and 'question' keys
 * @param string                           $token     standup submission token (embedded in Action.Submit)
 * @return array<string, mixed>  Outer 'message' wrapper ready for json_encode + POST
 */
function buildPromptCard(array $team, array $questions, string $token): array
{
    $teamName = (string) ($team['name'] ?? 'Team');
    $timezone = (string) ($team['timezone'] ?? 'UTC');
    $today    = date('l j F Y');

    // Compute expiry: standup_time + 2 hours in team timezone.
    $standupTimeStr = (string) ($team['standup_time'] ?? '09:00:00');
    $expiry = DateTimeImmutable::createFromFormat('H:i:s', $standupTimeStr, new DateTimeZone($timezone));
    if ($expiry === false) {
        // Fallback for HH:MM format.
        $expiry = DateTimeImmutable::createFromFormat('H:i', substr($standupTimeStr, 0, 5), new DateTimeZone($timezone));
    }
    $expiryFormatted = ($expiry !== false)
        ? $expiry->modify('+2 hours')->format('H:i T')
        : 'end of day';

    // Header blocks.
    $body = [
        [
            'type'   => 'TextBlock',
            'text'   => '🤖 AsyncStandUp — Daily Standup',
            'weight' => 'Bolder',
            'size'   => 'Medium',
            'wrap'   => true,
        ],
        [
            'type'     => 'TextBlock',
            'text'     => "{$teamName} · {$today}",
            'isSubtle' => true,
            'spacing'  => 'None',
            'wrap'     => true,
        ],
    ];

    // Per-question Input.Text blocks.
    foreach ($questions as $q) {
        $body[] = [
            'type'    => 'TextBlock',
            'text'    => (string) ($q['question'] ?? ''),
            'weight'  => 'Bolder',
            'wrap'    => true,
            'spacing' => 'Medium',
        ];
        $body[] = [
            'type'        => 'Input.Text',
            'id'          => 'q_' . (int) $q['id'],
            'isMultiline' => true,
            'placeholder' => 'Your answer...',
        ];
    }

    // Expiry footer.
    $body[] = [
        'type'     => 'TextBlock',
        'text'     => "⏰ Expires {$expiryFormatted}",
        'isSubtle' => true,
        'spacing'  => 'Medium',
        'wrap'     => true,
    ];

    return [
        'type'        => 'message',
        'attachments' => [[
            'contentType' => 'application/vnd.microsoft.card.adaptive',
            'content'     => [
                '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                'type'    => 'AdaptiveCard',
                'version' => '1.4',
                'body'    => $body,
                'actions' => [[
                    'type'  => 'Action.Submit',
                    'title' => 'Submit Standup',
                    'data'  => ['token' => $token],
                ]],
            ],
        ]],
    ];
}

/**
 * Send a proactive standup prompt DM via the Bot Framework REST API.
 *
 * Returns false (without throwing) if:
 * - The user has no conversation reference yet (first-time user — populated by US-39 webhook)
 * - Token acquisition fails
 * - The HTTP POST to the Bot Framework endpoint returns a non-2xx status
 *
 * @param array<string, mixed>             $user      users row (must include 'teams_conversation_ref')
 * @param array<string, mixed>             $team      teams row
 * @param array<int, array<string, mixed>> $questions rows with 'id' and 'question' keys
 * @param array<string, string>            $botConfig Keys: app_id, app_secret, service_url
 */
function sendDmPrompt(
    PDO    $pdo,
    array  $user,
    array  $team,
    array  $questions,
    string $token,
    array  $botConfig
): bool {
    $convRefRaw = $user['teams_conversation_ref'] ?? null;
    if ($convRefRaw === null || $convRefRaw === '') {
        // No conversation ref yet — caller falls back to email.
        return false;
    }

    $convRef = json_decode((string) $convRefRaw, true);
    if (!is_array($convRef)) {
        error_log("[AsyncStandUp] Invalid conversation_ref JSON for user {$user['id']}");
        return false;
    }

    try {
        $accessToken = getBotAccessToken($botConfig);
    } catch (\Throwable $e) {
        error_log("[AsyncStandUp] Bot token error for user {$user['id']}: " . $e->getMessage());
        return false;
    }

    $card = buildPromptCard($team, $questions, $token);

    $serviceUrl = rtrim((string) ($convRef['serviceUrl'] ?? $botConfig['service_url'] ?? ''), '/');
    $convId     = (string) ($convRef['conversation']['id'] ?? '');
    if ($serviceUrl === '' || $convId === '') {
        error_log("[AsyncStandUp] Missing serviceUrl or convId for user {$user['id']}");
        return false;
    }

    $endpoint = "{$serviceUrl}/v3/conversations/{$convId}/activities";
    $payload  = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
        'content'       => $payload,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);

    $response = @file_get_contents($endpoint, false, $ctx);
    if ($response === false) {
        error_log("[AsyncStandUp] Bot DM network failure for user {$user['id']} team {$team['id']}");
        return false;
    }
    $code = 0;
    /** @var string[] $http_response_header */
    foreach ($http_response_header as $h) {
        if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) {
            $code = (int) $m[1];
        }
    }

    if ($code < 200 || $code >= 300) {
        error_log("[AsyncStandUp] Bot DM failed for user {$user['id']}: HTTP {$code} — {$response}");
        return false;
    }

    return true;
}

/**
 * Validate an incoming Bot Framework JWT (audience, issuer, expiry).
 *
 * ⚠️ Legacy-risk: RS256 signature verification against Microsoft's JWKS endpoint is NOT
 * performed in v1. Acceptable for dev/staging. Production deployments MUST add signature
 * verification before go-live.
 * TODO(security): fetch JWKS from https://login.botframework.com/v1/.well-known/openidconfiguration
 *                 and verify RSA signature before deploying to production.
 *
 * @param array<string, string> $botConfig  Must contain 'app_id'.
 */
function validateBotJwt(string $authHeader, array $botConfig): bool
{
    if (!str_starts_with($authHeader, 'Bearer ')) {
        return false;
    }
    $jwt = substr($authHeader, 7);

    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return false;
    }

    // Base64url decode the payload segment.
    $padded  = str_pad(strtr($parts[1], '-_', '+/'), (4 - strlen($parts[1]) % 4) % 4, '=', STR_PAD_RIGHT);
    $payload = json_decode(base64_decode($padded), true);
    if (!is_array($payload)) {
        return false;
    }

    // Audience must be our Bot AppId.
    $aud = (string) ($payload['aud'] ?? '');
    if ($aud !== ($botConfig['app_id'] ?? '')) {
        return false;
    }

    // Issuer must be Bot Framework or Azure AD.
    $iss = (string) ($payload['iss'] ?? '');
    if (!str_contains($iss, 'botframework.com') && !str_contains($iss, 'microsoftonline.com')) {
        return false;
    }

    // Token must not be expired.
    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp < time()) {
        return false;
    }

    return true;
}
