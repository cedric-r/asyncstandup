<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../../config/config.php';

require_once __DIR__ . '/../../../src/Db.php';
require_once __DIR__ . '/../../../src/ApiAuth.php';
require_once __DIR__ . '/../../../src/ApiResponse.php';
require_once __DIR__ . '/../../../src/ApiRouter.php';
require_once __DIR__ . '/../../../src/TeamRepository.php';
require_once __DIR__ . '/../../../src/DashboardRepository.php';
require_once __DIR__ . '/../../../src/SubmissionRepository.php';
require_once __DIR__ . '/../../../src/StandupEmailer.php';

$pdo    = getDb($config);
$apiKey = authenticateApiKey($pdo);

if ($apiKey === null) {
    apiError('unauthorized', 401);
}

if (!checkRateLimit($pdo, (string) $apiKey['key_hash'])) {
    header('Retry-After: 3600');
    apiError('rate limit exceeded', 429);
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

routeApi($method, $path, $pdo, $apiKey);
