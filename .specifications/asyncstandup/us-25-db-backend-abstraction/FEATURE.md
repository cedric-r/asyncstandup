# FEATURE: Configurable Database Backend (US-25)

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-25-db-backend-abstraction`  
**Spec path**: `.specifications/asyncstandup/us-25-db-backend-abstraction/`

---

## Problem

`src/Db.php` hardcodes a MySQL DSN. Every deployment requires MySQL regardless of environment needs. The test suite already uses SQLite (via `tests/schema-sqlite.sql` and `createTestPdo()`), but the production code cannot. Two MySQL-specific SQL constructs in `src/SummaryEmailer.php` (`INSERT IGNORE`, `UTC_TIMESTAMP()`) further prevent portability.

---

## Solution

Introduce a lightweight driver adapter in `src/Db.php` that selects the correct PDO DSN at connection time based on `config['db']['driver']`. Add a portable helper for the `INSERT IGNORE` pattern. Replace `UTC_TIMESTAMP()` with a PHP-generated UTC timestamp passed as a bind parameter. Add `db/schema-postgresql.sql`. Update `config/config.example.php` to document all three driver configurations.

No new dependencies. No ORM. PDO only. Public API of `getDb(array $config): PDO` is unchanged.

---

## User Stories

| # | Story |
|---|---|
| US-25 | As a DevOps engineer, I want to configure the DB driver via `config.php` so I can run AsyncStandUp against MySQL, PostgreSQL, or SQLite without code changes. |

---

## Acceptance Criteria

| # | Criterion |
|---|---|
| AC-1 | `config/config.example.php` documents `driver` key; shows MySQL, PostgreSQL, and SQLite config blocks |
| AC-2 | `src/Db.php` builds correct PDO DSN for `mysql`, `pgsql`, and `sqlite`; throws `\RuntimeException` for unsupported drivers |
| AC-3 | `src/SummaryEmailer.php:attemptInsertSummaryLock()` uses a driver-aware helper — no `INSERT IGNORE` or `UTC_TIMESTAMP()` |
| AC-4 | `db/schema-postgresql.sql` exists with PostgreSQL-compatible DDL (`SERIAL`, `BOOLEAN`, `TEXT`, `NOW()`) |
| AC-5 | All 66 existing PHPUnit tests pass on SQLite after the refactor |
| AC-6 | New PHPUnit tests confirm `buildDsn()` returns correct DSN strings for each driver (no real connection required) |

---

## Out of Scope

- Database migration tooling (Flyway, Phinx, etc.)
- Multi-database connection pooling
- Query abstraction or ORM layer
- PostgreSQL- or SQLite-specific integration testing in CI (only SQLite in-memory tests are required)
- Any change to the test bootstrap or `createTestPdo()` (already SQLite-compatible)
