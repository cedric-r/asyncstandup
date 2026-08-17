<?php

declare(strict_types=1);

/**
 * Build a PDO DSN string for the configured driver.
 *
 * Pure function — no connection is made.
 *
 * @param  array{driver: string, host?: string, port?: int, name?: string,
 *                charset?: string, path?: string} $db
 * @throws \InvalidArgumentException for unsupported drivers
 */
function buildDsn(array $db): string
{
    return match ($db['driver']) {
        'mysql'  => sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'], $db['port'], $db['name'], $db['charset']
        ),
        'pgsql'  => sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $db['host'], $db['port'], $db['name']
        ),
        'sqlite' => 'sqlite:' . $db['path'],
        default  => throw new \InvalidArgumentException(
            'Unsupported DB driver: ' . $db['driver']
        ),
    };
}

/**
 * PDO singleton factory.
 *
 * Returns the same PDO instance for the lifetime of the request.
 * Connection is deferred until first call.
 * Public signature is unchanged — zero impact on existing callers.
 */
function getDb(array $config): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $db  = $config['db'];
    $dsn = buildDsn($db);

    // SQLite has no user/pass concept.
    $user = ($db['driver'] === 'sqlite') ? null : ($db['user'] ?? null);
    $pass = ($db['driver'] === 'sqlite') ? null : ($db['pass'] ?? null);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Driver-portable INSERT IGNORE.
 *
 * Returns 1 if the row was inserted; 0 if skipped due to a duplicate / constraint conflict.
 *
 * @param  string[] $columns  Column names.
 * @param  mixed[]  $values   Bind values (same order as $columns).
 * @throws \InvalidArgumentException for unsupported drivers
 */
function dbInsertIgnore(PDO $pdo, string $driver, string $table, array $columns, array $values): int
{
    $cols = implode(', ', $columns);
    $phs  = implode(', ', array_fill(0, count($columns), '?'));

    $sql = match ($driver) {
        'mysql'  => "INSERT IGNORE INTO {$table} ({$cols}) VALUES ({$phs})",
        'pgsql'  => "INSERT INTO {$table} ({$cols}) VALUES ({$phs}) ON CONFLICT DO NOTHING",
        'sqlite' => "INSERT OR IGNORE INTO {$table} ({$cols}) VALUES ({$phs})",
        default  => throw new \InvalidArgumentException('Unsupported DB driver: ' . $driver),
    };

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return $stmt->rowCount();
}
