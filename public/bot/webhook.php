<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/TeamsBot.php';
require_once __DIR__ . '/../../src/SubmissionRepository.php';
require_once __DIR__ . '/../../src/TeamRepository.php';
require_once __DIR__ . '/../../src/BotActivityHandler.php';

$pdo    = getDb($config);
$botCfg = $config['teams_bot'] ?? [];

// Validate Bot Framework JWT (audience, issuer, expiry).
// TODO(security): Full RS256 signature verification via JWKS is required before production.
// JWKS endpoint: https://login.botframework.com/v1/.well-known/openidconfiguration
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!validateBotJwt($authHeader, $botCfg)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Read and parse the incoming activity.
$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Empty body']);
    exit;
}

try {
    $activity = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

header('Content-Type: application/json');
$handler = new BotActivityHandler($pdo, $botCfg);
[$code, $responseBody] = $handler->handle($activity);

http_response_code($code);
echo json_encode($responseBody);
