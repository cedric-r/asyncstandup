# US-9: PHPUnit PHAR Test Suite

**Feature**: asyncstandup-core  
**Story**: US-9  
**Branch**: `feature/asyncstandup-tests-pwreset`

## User Story

**As a** developer  
**I can** run `php tests/phpunit.phar --bootstrap tests/bootstrap.php tests/` and see all tests pass  
**So that** the highest-risk pure functions and repository methods are automatically verified without needing Composer

## Acceptance Criteria

1. **Given** PHPUnit PHAR present in `tests/` and PHP 8.1 available, **When** `php tests/phpunit.phar --bootstrap tests/bootstrap.php tests/` is run, **Then** exits code 0 with all tests passing
2. **Given** `isTeamDue()` tested, **When** 6 boundary cases run, **Then** all pass: exact match (+0s), 1s before (just outside), 59s before (inside window), 59s after (inside window), 60s after (outside), different team timezone
3. **Given** `isSummaryDue()` tested, **When** same 6 cases run offset by 1 hour, **Then** all pass
4. **Given** `saveSubmission()` tested, **When** happy path run, **Then** one `standup_submissions` row + one `standup_answers` row per question created; token `used_at` set
5. **Given** `saveSubmission()` tested with simulated partial failure, **When** transaction rolls back, **Then** no orphan rows in either table
6. **Given** `assembleSummaryData()` tested, **When** run with seeded submissions and a non-submitter, **Then** submitters appear with their answers; non-submitter appears in `non_submitters` list
7. **Given** `acceptInvitationForUser()` tested with valid token, **When** called, **Then** returns `true`; `team_members` row inserted; `accepted_at` set
8. **Given** `acceptInvitationForUser()` tested with expired token (injectable `$now` set to 8 days after `created_at`), **When** called, **Then** returns `false`; no `team_members` row
9. **Given** `acceptInvitationForUser()` tested with already-accepted token, **When** called, **Then** returns `false`
10. **Given** `deleteOrg()` tested, **When** called with seeded org + full cascade, **Then** completes without FK violation; all related rows removed

## Definition of Done

- [ ] All ACs met
- [ ] PHPUnit PHAR committed to `tests/phpunit.phar` (or documented as manual download step in README with checksum)
- [ ] `tests/bootstrap.php` creates SQLite `:memory:` PDO; applies SQLite-compatible schema; seeds minimal test fixtures
- [ ] `InvitationRepository.php` refactored: `$now` extracted as optional parameter (1-line change, no behaviour change at call sites)
- [ ] No Composer, no autoloading — `require_once` in bootstrap
- [ ] All tests use PHPUnit `TestCase`; no `@runInSeparateProcess` needed (pure functions + in-memory SQLite)

## Files

| Action | File | Risk |
|---|---|---|
| Create | `tests/bootstrap.php` | — |
| Create | `tests/phpunit.xml` | — |
| Create | `tests/StandupEmailerTest.php` | — |
| Create | `tests/SummaryEmailerTest.php` | — |
| Create | `tests/RepositoryTest.php` | — |
| Create | `tests/schema-sqlite.sql` | — |
| Modify | `src/InvitationRepository.php` | ⚠️ Path B — already merged |
| Modify | `README.md` | — |
| Note | `tests/phpunit.phar` | download manually or commit binary |

## Implementation Details

### PHPUnit PHAR

Download from `https://phar.phpunit.de/phpunit-10.phar` (compatible with PHP 8.1).  
Rename to `tests/phpunit.phar`. Add to `.gitignore` or commit as binary (< 5 MB).  
Document in README: `wget https://phar.phpunit.de/phpunit-10.phar -O tests/phpunit.phar`

### SQLite-compatible schema (`tests/schema-sqlite.sql`)

Strip MySQL-specific syntax from `schema.sql`:
- Remove `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
- Remove `UNSIGNED` from INT columns
- Replace `AUTO_INCREMENT` with `AUTOINCREMENT` (on INTEGER PK only)
- Replace `TINYINT(1)` with `INTEGER`
- `DATETIME` → `TEXT` (SQLite stores datetimes as ISO strings)
- Remove `DEFAULT (UTC_TIMESTAMP())` — not supported in SQLite (handled in PHP)
- Remove FK constraints or keep (SQLite supports syntax but may not enforce without `PRAGMA foreign_keys = ON`)

### `tests/bootstrap.php`

```php
<?php
declare(strict_types=1);

// Load application source files
require_once __DIR__ . '/../src/StandupEmailer.php';
require_once __DIR__ . '/../src/SummaryEmailer.php';
require_once __DIR__ . '/../src/InvitationRepository.php';
require_once __DIR__ . '/../src/OrgRepository.php';
require_once __DIR__ . '/../src/SubmissionRepository.php';

// Create in-memory SQLite PDO (shared across tests via global or factory)
function createTestPdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = file_get_contents(__DIR__ . '/schema-sqlite.sql');
    $pdo->exec($schema);
    return $pdo;
}
```

Each test class creates its own PDO via `createTestPdo()` in `setUp()` — no shared state.

### `isTeamDue()` test cases (`StandupEmailerTest.php`)

Test team array: `['standup_time' => '09:00:00', 'timezone' => 'Europe/London']`

| Case | nowUtc | Expected |
|---|---|---|
| Exact match | 09:00:00 UTC (same as team tz = UTC offset 0) | `true` |
| 1s before window | 08:58:59 UTC | `false` |
| 59s before | 08:59:01 UTC | `true` |
| 59s after | 09:00:59 UTC | `true` |
| 60s after | 09:01:00 UTC | `false` |
| Different tz (`America/New_York`, standup `09:00`) | nowUtc = 14:00 UTC (09:00 ET in winter) | `true` |

Window logic: `abs(diff) < 60` (strictly less than 60 seconds).

### `isSummaryDue()` test cases (`SummaryEmailerTest.php`)

Same team; nowUtc offset by exactly `+1 hour` from each case above. All expected results identical.

### `saveSubmission()` test cases (`RepositoryTest.php`)

Seed: insert a `teams` row, `users` row, `team_questions` rows (2 questions), `standup_tokens` row (unused).

- Happy path: call `saveSubmission()` → assert 1 row in `standup_submissions`, 2 rows in `standup_answers`, `used_at` set on token
- Rollback test: mock or subclass repository to throw after `standup_submissions` INSERT; assert 0 rows in `standup_answers` (transaction rolled back); SQLite in-memory makes this straightforward with try/catch + savepoint pattern
- Expired token: insert token with `expires_at` = 1 hour ago; assert `saveSubmission()` returns false/throws

### `assembleSummaryData()` test cases

Seed: 1 team, 2 developer members, 2 questions, tokens for both for `send_date`; 1 submission with answers, 1 member with no submission.

Assert: `$data['submissions']` has 1 entry with correct answers; `$data['non_submitters']` has 1 entry with the non-submitting member's display name.

### `acceptInvitationForUser()` refactor

In `src/InvitationRepository.php`, extract `$now` parameter:

```php
// Before:
function acceptInvitationForUser(PDO $pdo, string $token, int $userId): bool {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    ...
}

// After:
function acceptInvitationForUser(PDO $pdo, string $token, int $userId, ?DateTimeImmutable $now = null): bool {
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    ...
}
```

All existing call sites unchanged (default parameter). Only tests pass an explicit `$now`.

### `deleteOrg()` test case

Seed: org → team → team_member → team_question → standup_token → standup_submission → standup_answer → summary_sent → team_recipient → invitation → org_member.

Call `deleteOrg()`. Assert: all seeded rows removed in each table; no PDO exception (FK violation would surface here with `PRAGMA foreign_keys = ON`).

### `phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="bootstrap.php" colors="true" failOnWarning="true">
    <testsuites>
        <testsuite name="AsyncStandUp">
            <directory>.</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

Placed in `tests/`. Run from `tests/` directory or with `--configuration tests/phpunit.xml`.

## Implementation Notes

- **No mocking library**: all dependencies are either pure functions (no mocking needed) or use real in-memory SQLite (repository tests) — no PHPUnit mock objects required
- **Test isolation**: each `TestCase::setUp()` creates a fresh `createTestPdo()` call — completely isolated per test class
- **SQLite FK enforcement**: `$pdo->exec('PRAGMA foreign_keys = ON')` in `createTestPdo()` to catch cascade order bugs
- **`isTeamDue()` and `isSummaryDue()` testability**: these functions already accept `DateTimeImmutable $nowUtc` as a parameter (per US-5/US-8 spec) — no refactor needed; only `acceptInvitationForUser()` requires the `$now` extraction
