<?php
require_once __DIR__ . '/../../src/Auth.php';
startSession();
header('Location: /settings/api-keys.php', true, 301);
exit;
