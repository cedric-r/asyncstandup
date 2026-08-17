<?php

declare(strict_types=1);

// =============================================================================
// AsyncStandUp — PHPUnit bootstrap
// =============================================================================
// Loaded by: php tests/phpunit.phar --configuration tests/phpunit.xml
//
// Note: src/Auth.php is intentionally NOT required here — it sets
// ini_set('display_errors', '0') and installs a set_exception_handler()
// at file scope, which would suppress PHPUnit's own error output.
// =============================================================================

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/StandupEmailer.php';
require_once __DIR__ . '/../src/SummaryEmailer.php';
require_once __DIR__ . '/../src/TeamRepository.php';
require_once __DIR__ . '/../src/DashboardRepository.php';
require_once __DIR__ . '/../src/InvitationRepository.php';
require_once __DIR__ . '/../src/OrgRepository.php';
require_once __DIR__ . '/../src/SubmissionRepository.php';
require_once __DIR__ . '/../src/ApiAuth.php';

/**
 * Create an isolated in-memory SQLite PDO with the AsyncStandUp schema applied.
 *
 * Each test class calls this in setUp() — completely isolated per test.
 */
function createTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Enforce FK constraints — catches cascade-order bugs.
    $pdo->exec('PRAGMA foreign_keys = ON');

    $schema = file_get_contents(__DIR__ . '/schema-sqlite.sql');

    if ($schema === false) {
        throw new RuntimeException('Could not read tests/schema-sqlite.sql');
    }

    $pdo->exec($schema);

    return $pdo;
}

/**
 * Seed a minimal user row and return its ID.
 */
function seedUser(PDO $pdo, string $email = 'test@example.com', string $displayName = 'Test User'): int
{
    $pdo->prepare('INSERT INTO users (email, password_hash, display_name) VALUES (?, ?, ?)')
        ->execute([$email, password_hash('password', PASSWORD_BCRYPT), $displayName]);

    return (int) $pdo->lastInsertId();
}

/**
 * Seed a minimal org + org_member and return the org ID.
 */
function seedOrg(PDO $pdo, int $userId, string $name = 'Test Org'): int
{
    $pdo->prepare('INSERT INTO organisations (name, created_by) VALUES (?, ?)')->execute([$name, $userId]);
    $orgId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO org_members (org_id, user_id) VALUES (?, ?)')->execute([$orgId, $userId]);

    return $orgId;
}

/**
 * Seed a minimal team row and return its ID.
 */
function seedTeam(PDO $pdo, int $orgId, int $userId, string $timezone = 'UTC', string $standupTime = '09:00:00'): int
{
    $pdo->prepare(
        'INSERT INTO teams (org_id, name, timezone, standup_time, created_by) VALUES (?, ?, ?, ?, ?)'
    )->execute([$orgId, 'Test Team', $timezone, $standupTime, $userId]);

    return (int) $pdo->lastInsertId();
}

/**
 * Seed a team_member row.
 */
function seedTeamMember(PDO $pdo, int $teamId, int $userId, int $isOwner = 0, int $isDev = 1): void
{
    $pdo->prepare(
        'INSERT INTO team_members (team_id, user_id, is_owner, is_developer) VALUES (?, ?, ?, ?)'
    )->execute([$teamId, $userId, $isOwner, $isDev]);
}
