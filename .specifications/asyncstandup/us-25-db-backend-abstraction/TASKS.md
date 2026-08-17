# TASKS — US-25: Configurable Database Backend

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-25-db-backend-abstraction`  
**Agent**: PHP Developer (`fa2e6dbf`)

---

## Phase 1 — Branch setup

**T-1** `backend-dev` — Create feature branch  
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" \
  checkout -b feature/us-25-db-backend-abstraction
```
**AC covered**: prerequisite

---

## Phase 2 — `src/Db.php` driver adapter

**T-2** `backend-dev` — Extract `buildDsn(array $db): string` from `getDb()`

Add immediately above `getDb()`:
```php
/**
 * Build a PDO DSN string for the configured driver.
 *
 * @param  array{driver: string, host?: string, port?: int, name?: string,
 *                charset?: string, path?: string} $db
 * @throws \RuntimeException for unsupported drivers
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
        default  => throw new \RuntimeException(
            'Unsupported DB driver: ' . $db['driver']
        ),
    };
}
```

**T-3** `backend-dev` — Update `getDb()` to use `buildDsn()` and handle SQLite credentials

```php
function getDb(array $config): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $db  = $config['db'];
    $dsn = buildDsn($db);

    $user = ($db['driver'] === 'sqlite') ? null : $db['user'];
    $pass = ($db['driver'] === 'sqlite') ? null : $db['pass'];

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
```

**T-4** `backend-dev` — Add `dbInsertIgnore()` helper to `src/Db.php`

```php
/**
 * Driver-portable INSERT IGNORE.
 *
 * @param  string[]  $columns  column names
 * @param  mixed[]   $values   bind values (same order as $columns)
 * @return int  1 if row inserted; 0 if duplicate / constraint conflict
 */
function dbInsertIgnore(PDO $pdo, string $driver, string $table, array $columns, array $values): int
{
    $cols  = implode(', ', $columns);
    $phs   = implode(', ', array_fill(0, count($columns), '?'));

    $sql = match ($driver) {
        'mysql'  => "INSERT IGNORE INTO {$table} ({$cols}) VALUES ({$phs})",
        'pgsql'  => "INSERT INTO {$table} ({$cols}) VALUES ({$phs}) ON CONFLICT DO NOTHING",
        'sqlite' => "INSERT OR IGNORE INTO {$table} ({$cols}) VALUES ({$phs})",
        default  => throw new \RuntimeException('Unsupported DB driver: ' . $driver),
    };

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
    return $stmt->rowCount();
}
```

**AC covered**: AC-2, AC-3

---

## Phase 3 — `src/SummaryEmailer.php` portability fix

**T-5** `backend-dev` — Refactor `attemptInsertSummaryLock()` to use `dbInsertIgnore()` + PHP timestamp

Read `$driver` from the PDO connection attribute or from the config. The simplest approach — pass `string $driver` as a second parameter to `attemptInsertSummaryLock()`:

```php
function attemptInsertSummaryLock(PDO $pdo, string $driver, int $teamId, string $sendDate): bool
{
    $sentAt = gmdate('Y-m-d H:i:s');   // UTC — replaces UTC_TIMESTAMP()

    $inserted = dbInsertIgnore(
        $pdo,
        $driver,
        'summary_sent',
        ['team_id', 'send_date', 'sent_at'],
        [$teamId, $sendDate, $sentAt]
    );

    return $inserted > 0;
}
```

**T-6** `backend-dev` — Update the single call site of `attemptInsertSummaryLock()` in `cron/send-summary.php` (or wherever the cron calls it)

```php
// Before:
$locked = attemptInsertSummaryLock($pdo, $team['id'], $sendDate);

// After:
$locked = attemptInsertSummaryLock($pdo, $config['db']['driver'], $team['id'], $sendDate);
```

Grep first to confirm the only call site:
```bash
grep -rn "attemptInsertSummaryLock" --include="*.php"
```

**AC covered**: AC-3

---

## Phase 4 — `config/config.example.php`

**T-7** `backend-dev` — Replace the `db` block with a documented multi-driver example

```php
// ── Database ─────────────────────────────────────────────────────────────────
// Set 'driver' to 'mysql', 'pgsql', or 'sqlite'.
//
// MySQL example (default):
//   'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
//   'name' => 'asyncstandup', 'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4'
//
// PostgreSQL example:
//   'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
//   'name' => 'asyncstandup', 'user' => 'postgres', 'pass' => ''
//
// SQLite example (development / testing):
//   'driver' => 'sqlite', 'path' => '/var/data/asyncstandup.sqlite'
//   (host, port, name, user, pass, charset are ignored for sqlite)
//
'db' => [
    'driver'  => 'mysql',
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'name'    => 'asyncstandup',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',   // MySQL only; ignored for pgsql/sqlite
    'path'    => '',          // SQLite only; path to .sqlite file
],
```

**AC covered**: AC-1

---

## Phase 5 — `db/schema-postgresql.sql`

**T-8** `backend-dev` — Create `db/schema-postgresql.sql`

Full DDL translated from `db/schema.sql`:

Type mapping applied:
- `INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` → `SERIAL PRIMARY KEY`
- `TINYINT(1) NOT NULL DEFAULT 0` → `BOOLEAN NOT NULL DEFAULT FALSE`
- `TINYINT(1) NOT NULL DEFAULT 1` → `BOOLEAN NOT NULL DEFAULT TRUE`
- `DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())` → `TIMESTAMP NOT NULL DEFAULT NOW()`
- `DATETIME NULL` → `TIMESTAMP NULL`
- `DATE NOT NULL` → `DATE NOT NULL`
- `TIME NOT NULL` → `TIME NOT NULL`
- `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` → (removed)
- `UNIQUE KEY uq_name (col1, col2)` → `CONSTRAINT uq_name UNIQUE (col1, col2)` (inline on table)
- `SET NAMES` / `SET foreign_key_checks` → (removed)

Add header note:
```sql
-- AsyncStandUp schema — PostgreSQL
-- Run on a fresh database only. Not a migration file.
-- For MySQL schema: db/schema.sql
-- For SQLite test schema: tests/schema-sqlite.sql
```

Include all ALTER TABLE migration columns inline in the CREATE TABLE definitions (no separate migration section needed for fresh PostgreSQL installs).

**AC covered**: AC-4

---

## Phase 6 — Tests

**T-9** `backend-dev` — Create `tests/DbDsnBuilderTest.php` (4 test cases)

Exactly as specified in STORY.md AC-6:
- `testMysqlDsn()` — asserts correct mysql DSN string
- `testPgsqlDsn()` — asserts correct pgsql DSN string
- `testSqliteDsn()` — asserts correct sqlite DSN string
- `testUnsupportedDriverThrows()` — asserts `\RuntimeException` with message `'Unsupported DB driver: oracle'`

No PDO connection is made in any of these tests — `buildDsn()` is a pure string builder.

**T-10** `backend-dev` — Run full test suite and confirm all 66 + 4 = 70 tests pass

```bash
cd "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup"
./vendor/bin/phpunit
```

Expected: 70 tests, all green. If any existing test fails, fix the regression before committing.

**AC covered**: AC-5, AC-6

---

## Phase 7 — Commit and signal

**T-11** `backend-dev` — Commit all changes

```bash
git add src/Db.php src/SummaryEmailer.php \
        config/config.example.php \
        db/schema-postgresql.sql \
        tests/DbDsnBuilderTest.php \
        .specifications/asyncstandup/us-25-db-backend-abstraction/

git commit -m "feat(us-25): configurable DB backend — mysql/pgsql/sqlite DSN adapter"
```

Signal Team Lead with commit hash.

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (`config.example.php`) | T-7 |
| AC-2 (DSN builder + exception) | T-2, T-3 |
| AC-3 (`INSERT IGNORE` + `UTC_TIMESTAMP()`) | T-4, T-5, T-6 |
| AC-4 (`schema-postgresql.sql`) | T-8 |
| AC-5 (66 existing tests pass) | T-10 |
| AC-6 (4 new DSN tests) | T-9, T-10 |

---

## Estimate

| Phase | Tasks | Hours |
|---|---|---|
| Branch | T-1 | 0.25h |
| `Db.php` adapter | T-2, T-3, T-4 | 2h |
| `SummaryEmailer.php` | T-5, T-6 | 1h |
| `config.example.php` | T-7 | 0.5h |
| `schema-postgresql.sql` | T-8 | 2h |
| Tests | T-9, T-10 | 1.5h |
| Commit + signal | T-11 | 0.25h |
| **Total** | | **~7.5h** |
