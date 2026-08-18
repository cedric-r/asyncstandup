<?php

declare(strict_types=1);

/**
 * POST an Adaptive Card payload to a Teams Incoming Webhook URL.
 *
 * Uses vanilla PHP stream context (no cURL required).
 * Teams Incoming Webhook returns HTTP 200 with body "1" on success.
 *
 * @param array<string, mixed> $card  Output of buildSummaryCard()
 * @return bool True if the webhook accepted the payload (HTTP 2xx); false otherwise.
 */
function postChannelSummary(string $webhookUrl, array $card): bool
{
    $payload = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($webhookUrl, false, $ctx);
    if ($response === false) {
        return false;
    }

    $code = 0;
    /** @var string[] $http_response_header */
    foreach ($http_response_header as $h) {
        if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) {
            $code = (int) $m[1];
        }
    }

    return $code >= 200 && $code < 300;
}
