# RETRO — US-25: Configurable Database Backend

**Story**: US-25 — DB Backend Abstraction (mysql / pgsql / sqlite)
**Branch**: `feature/us-25-db-backend-abstraction`
**Merge commit**: `f05423b`
**Review cycles**: 1
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `src/Db.php` | Added `buildDsn()` (pure DSN builder, `InvalidArgumentException` for unknown driver), updated `getDb()` to call it (skips user/pass for sqlite), added `dbInsertIgnore()` driver-portable INSERT IGNORE helper |
| `src/SummaryEmailer.php` | `attemptInsertSummaryLock()` now accepts `string $driver`, replaces `UTC_TIMESTAMP()` with `gmdate('Y-m-d H:i:s')`, delegates to `dbInsertIgnore()` |
| `config/config.example.php` | Added `driver` key to `db` block; documented mysql / pgsql / sqlite examples in comments |
| `db/schema-postgresql.sql` | Full fresh-install PostgreSQL DDL — SERIAL, BOOLEAN, TIMESTAMP WITH TIME ZONE patterns, UNIQUE inline, includes US-24 tables (`login_attempts`, `password_reset_requests`), no MySQL-specific syntax |
| `tests/DbDsnBuilderTest.php` | 4 unit tests for `buildDsn()` — mysql, pgsql, sqlite, unsupported driver → `InvalidArgumentException` |
| `tests/bootstrap.php` | Added `require_once src/Db.php` (necessary because `SummaryEmailer.php` now calls `dbInsertIgnore()`) |

**Test result**: 70 tests, 144 assertions — all pass (66 pre-existing + 4 new DSN builder tests)
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle** — no reviewer findings; Gate D approved on first submission.

---

## Notes

1. **No `vendor/` in this repo** — PHPUnit runs via PHAR (`tests/phpunit.phar`); PHPStan was borrowed from the assembly-service vendor directory. PHPUnit PHAR was downloaded fresh for this story (not previously present).
2. **`getDb()` static singleton** — the `static $pdo = null` pattern means only the first call connects; driver switching across requests is not supported by design (matches existing behaviour).
3. **`attemptInsertSummaryLock()` call site** — the only call is inside `sendSummaryEmail()` which already receives `$config`; passing `$config['db']['driver']` required no changes to external callers.
4. **PostgreSQL schema** — fresh-install only, no migration section needed; ALTER TABLE columns from the MySQL migration history were folded inline into CREATE TABLE definitions.
