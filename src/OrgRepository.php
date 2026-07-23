<?php

declare(strict_types=1);

function getOrgsForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT o.id, o.name, o.created_by, o.created_at
        FROM organisations o
        JOIN org_members om ON om.org_id = o.id
        WHERE om.user_id = ?
        ORDER BY o.name
    ');
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function createOrg(PDO $pdo, string $name, int $userId): int
{
    $stmt = $pdo->prepare('INSERT INTO organisations (name, created_by) VALUES (?, ?)');
    $stmt->execute([trim($name), $userId]);
    $orgId = (int) $pdo->lastInsertId();

    $stmt2 = $pdo->prepare('INSERT INTO org_members (org_id, user_id) VALUES (?, ?)');
    $stmt2->execute([$orgId, $userId]);

    return $orgId;
}

function getOrgById(PDO $pdo, int $orgId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM organisations WHERE id = ?');
    $stmt->execute([$orgId]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function updateOrg(PDO $pdo, int $orgId, string $name): void
{
    $stmt = $pdo->prepare('UPDATE organisations SET name = ? WHERE id = ?');
    $stmt->execute([trim($name), $orgId]);
}

/**
 * Delete an organisation and all related data in FK-safe order.
 */
function deleteOrg(PDO $pdo, int $orgId): void
{
    // Collect all team IDs for this org.
    $stmt = $pdo->prepare('SELECT id FROM teams WHERE org_id = ?');
    $stmt->execute([$orgId]);
    $teamIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($teamIds)) {
        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));

        // 1. standup_answers — subquery form (MySQL + SQLite compatible).
        $pdo->prepare("
            DELETE FROM standup_answers
            WHERE submission_id IN (
                SELECT ss.id FROM standup_submissions ss
                JOIN standup_tokens t ON t.id = ss.token_id
                WHERE t.team_id IN ({$placeholders})
            )
        ")->execute($teamIds);

        // 2. standup_submissions — subquery form.
        $pdo->prepare("
            DELETE FROM standup_submissions
            WHERE token_id IN (
                SELECT id FROM standup_tokens WHERE team_id IN ({$placeholders})
            )
        ")->execute($teamIds);

        // 3. standup_tokens
        $pdo->prepare("DELETE FROM standup_tokens WHERE team_id IN ({$placeholders})")->execute($teamIds);

        // 4. summary_sent
        $pdo->prepare("DELETE FROM summary_sent WHERE team_id IN ({$placeholders})")->execute($teamIds);

        // 5. team_recipients
        $pdo->prepare("DELETE FROM team_recipients WHERE team_id IN ({$placeholders})")->execute($teamIds);

        // 6. team_questions
        $pdo->prepare("DELETE FROM team_questions WHERE team_id IN ({$placeholders})")->execute($teamIds);

        // 7. invitations
        $pdo->prepare("DELETE FROM invitations WHERE team_id IN ({$placeholders})")->execute($teamIds);

        // 8. team_members
        $pdo->prepare("DELETE FROM team_members WHERE team_id IN ({$placeholders})")->execute($teamIds);

        // 9. teams
        $pdo->prepare("DELETE FROM teams WHERE org_id = ?")->execute([$orgId]);
    }

    // 10. org_members
    $pdo->prepare('DELETE FROM org_members WHERE org_id = ?')->execute([$orgId]);

    // 11. organisations
    $pdo->prepare('DELETE FROM organisations WHERE id = ?')->execute([$orgId]);
}

function isOrgMember(PDO $pdo, int $orgId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM org_members WHERE org_id = ? AND user_id = ?');
    $stmt->execute([$orgId, $userId]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Return true if the given user is the creator of the organisation.
 */
function isOrgCreator(PDO $pdo, int $orgId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM organisations WHERE id = ? AND created_by = ?');
    $stmt->execute([$orgId, $userId]);

    return $stmt->fetchColumn() !== false;
}
