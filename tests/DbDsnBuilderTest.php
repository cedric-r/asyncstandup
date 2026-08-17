<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for buildDsn().
 *
 * Pure function — no live DB connection is made in any of these tests.
 */
class DbDsnBuilderTest extends TestCase
{
    public function testMysqlDsn(): void
    {
        $db = [
            'driver'  => 'mysql',
            'host'    => '127.0.0.1',
            'port'    => 3306,
            'name'    => 'asyncstandup',
            'charset' => 'utf8mb4',
        ];

        $dsn = buildDsn($db);

        $this->assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=asyncstandup;charset=utf8mb4',
            $dsn
        );
    }

    public function testPgsqlDsn(): void
    {
        $db = [
            'driver' => 'pgsql',
            'host'   => '127.0.0.1',
            'port'   => 5432,
            'name'   => 'asyncstandup',
        ];

        $dsn = buildDsn($db);

        $this->assertSame(
            'pgsql:host=127.0.0.1;port=5432;dbname=asyncstandup',
            $dsn
        );
    }

    public function testSqliteDsn(): void
    {
        $db = [
            'driver' => 'sqlite',
            'path'   => '/var/data/asyncstandup.sqlite',
        ];

        $dsn = buildDsn($db);

        $this->assertSame(
            'sqlite:/var/data/asyncstandup.sqlite',
            $dsn
        );
    }

    public function testUnsupportedDriverThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported DB driver: oracle');

        buildDsn(['driver' => 'oracle']);
    }
}
