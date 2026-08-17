# US-25: Configurable Database Backend

**Status**: APPROVED (autonomous mode)  
**Feature**: Configurable DB Backend  
**Branch**: `feature/us-25-db-backend-abstraction`

---

## Story

**As a** DevOps engineer  
**I want** to set `config['db']['driver']` to `mysql`, `pgsql`, or `sqlite`  
**So that** I can deploy AsyncStandUp against any PDO-supported database without modifying source code

---

## Acceptance Criteria

### AC-1 — `config/config.example.php` documents all three driver variants

```php
'db' => [
    'driver'  => 'mysql',   // 'mysql' | 'pgsql' | 'sqlite'

    // MySQL / PostgreSQL fields (ignored for sqlite):
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'name'    => 'asyncstandup',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',  // MySQL only; ignored for pgsql/sqlite

    // SQLite only (ignored for mysql/pgsql):
    'path'    => '/var/data/asyncstandup.sqlite',
],
```

A comment block above the `db` key documents all three complete driver examples.

---

### AC-2 — `src/Db.php` builds correct DSN per driver

`buildDsn(array $db): string` is extracted as a pure function (or static method if refactored to a class — but `getDb()` function signature stays unchanged):

| driver | DSN format |
|---|---|
| `mysql` | `mysql:host={host};port={port};dbname={name};charset={charset}` |
| `pgsql` | `pgsql:host={host};port={port};dbname={name}` |
| `sqlite` | `sqlite:{path}` |
| other | throws `\RuntimeException("Unsupported DB driver: {driver}")` |

For SQLite, `getDb()` passes `null, null` as username/password to PDO. MySQL and PostgreSQL pass `$db['user']` and `$db['pass']`.

---

### AC-3 — `src/SummaryEmailer.php` is driver-portable

`attemptInsertSummaryLock()` is refactored to:
1. Use `gmdate('Y-m-d H:i:s')` to generate the UTC timestamp in PHP, passed as a bind parameter
2. Use the `dbInsertIgnore()` helper (see below) instead of `INSERT IGNORE`

`dbInsertIgnore(PDO $pdo, string $driver, string $table, array $columns, array $values): int` — added to `src/Db.php`:

| driver | SQL used |
|---|---|
| `mysql` | `INSERT IGNORE INTO {table} ({cols}) VALUES ({placeholders})` |
| `pgsql` | `INSERT INTO {table} ({cols}) VALUES ({placeholders}) ON CONFLICT DO NOTHING` |
| `sqlite` | `INSERT OR IGNORE INTO {table} ({cols}) VALUES ({placeholders})` |

Returns the PDO `rowCount()` — 1 if inserted, 0 if duplicate.

`SummaryEmailer.php` reads the driver from the PDO's attribute or from a passed `string $driver` parameter (see tasks for chosen approach).

---

### AC-4 — `db/schema-postgresql.sql` exists

Full schema for PostgreSQL — replaces all MySQL-specific types:

| MySQL | PostgreSQL |
|---|---|
| `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | `SERIAL PRIMARY KEY` (or `BIGSERIAL`) |
| `TINYINT(1)` | `BOOLEAN` |
| `VARCHAR(n)` | `VARCHAR(n)` (kept) |
| `TEXT` | `TEXT` (kept) |
| `DATETIME` | `TIMESTAMP` |
| `DATE` | `DATE` (kept) |
| `TIME` | `TIME` (kept) |
| `DEFAULT (UTC_TIMESTAMP())` | `DEFAULT NOW()` |
| `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` | (removed) |
| `UNIQUE KEY uq_... (col1, col2)` | `CONSTRAINT uq_... UNIQUE (col1, col2)` |
| `SET NAMES / SET foreign_key_checks` | (removed) |

Migration section (the `ALTER TABLE` lines at the bottom of `schema.sql`) must also be ported or explicitly documented as "run `schema-postgresql.sql` on fresh installs only — not a migration file".

---

### AC-5 — All 66 existing PHPUnit tests pass

No changes to `tests/bootstrap.php`, `tests/schema-sqlite.sql`, or `createTestPdo()`. The refactored `src/Db.php` and `src/SummaryEmailer.php` must not break SQLite test compatibility.

---

### AC-6 — New PHPUnit test: `DbDsnBuilderTest`

```php
// tests/DbDsnBuilderTest.php
class DbDsnBuilderTest extends TestCase
{
    public function testMysqlDsn(): void
    {
        $dsn = buildDsn(['driver' => 'mysql', 'host' => 'db.example.com',
                          'port' => 3306, 'name' => 'mydb', 'charset' => 'utf8mb4']);
        $this->assertSame('mysql:host=db.example.com;port=3306;dbname=mydb;charset=utf8mb4', $dsn);
    }

    public function testPgsqlDsn(): void
    {
        $dsn = buildDsn(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'name' => 'mydb']);
        $this->assertSame('pgsql:host=127.0.0.1;port=5432;dbname=mydb', $dsn);
    }

    public function testSqliteDsn(): void
    {
        $dsn = buildDsn(['driver' => 'sqlite', 'path' => '/var/data/test.sqlite']);
        $this->assertSame('sqlite:/var/data/test.sqlite', $dsn);
    }

    public function testUnsupportedDriverThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported DB driver: oracle');
        buildDsn(['driver' => 'oracle']);
    }
}
```

---

## Files Changed

| File | Change |
|---|---|
| `src/Db.php` | Add `buildDsn()` + `dbInsertIgnore()`; update `getDb()` to call `buildDsn()` |
| `src/SummaryEmailer.php` | Replace `INSERT IGNORE` + `UTC_TIMESTAMP()` with `dbInsertIgnore()` + PHP timestamp |
| `config/config.example.php` | Add `driver` and `path` keys; add comment block with all three driver examples |
| `db/schema-postgresql.sql` (new) | PostgreSQL-compatible DDL |
| `tests/DbDsnBuilderTest.php` (new) | DSN builder unit tests (4 cases) |

---

## Open Questions

None — all design decisions specified by Team Lead.
