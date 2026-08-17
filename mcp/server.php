#!/usr/bin/env php
<?php

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/ApiAuth.php';
require_once __DIR__ . '/../src/McpServer.php';
require_once __DIR__ . '/../src/McpTools.php';
require_once __DIR__ . '/../src/TeamRepository.php';
require_once __DIR__ . '/../src/DashboardRepository.php';
require_once __DIR__ . '/../src/SubmissionRepository.php';
require_once __DIR__ . '/../src/StandupEmailer.php';

$config = require __DIR__ . '/../config/config.php';
$pdo    = getDb($config);

$server = new McpServer($pdo);
$server->run();
