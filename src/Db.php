<?php

declare(strict_types=1);

/**
 * PDO singleton factory.
 *
 * Returns the same PDO instance for the lifetime of the request.
 * Connection is deferred until first call.
 */
function getDb(array $config): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $db  = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
