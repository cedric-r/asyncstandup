<?php

declare(strict_types=1);

/**
 * Create (or replace) an invitation for a team+email combination.
 *
 * If a pending invitation already exists for this team+email, it is replaced
 * with a new token and timestamp (AC-6).
 *
 * @return string The 64-char hex invitation token.
 */
function createInvitation(PDO $pdo, int $teamId, string $email, string $roles, int $invitedBy): string
{
    // Delete any existing pending invitation for this team+email.
    $pdo->prepare('DELETE FROM invitations WHERE team_id = ? AND invited_email = ? AND accepted_at IS NULL')
        ->execute([$teamId, $email]);

    $token = bin2hex(random_bytes(32));

    $pdo->prepare('
        INSERT INTO invitations (team_id, invited_email, token, invited_by, intended_roles)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([$teamId, $email, $token, $invitedBy, $roles]);

    return $token;
}

function getInvitationByToken(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('
        SELECT i.*, t.name AS team_name, o.name AS org_name
        FROM invitations i
        JOIN teams t ON t.id = i.team_id
        JOIN organisations o ON o.id = t.org_id
        WHERE i.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function markAccepted(PDO $pdo, int $invitationId): void
{
    $ts = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $pdo->prepare('UPDATE invitations SET accepted_at = ? WHERE id = ?')
        ->execute([$ts, $invitationId]);
}

/**
 * Check if a user (by email) is already a member of the team.
 */
function isAlreadyTeamMember(PDO $pdo, int $teamId, string $email): bool
{
    $stmt = $pdo->prepare('
        SELECT 1 FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ? AND u.email = ?
    ');
    $stmt->execute([$teamId, mb_strtolower(trim($email))]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Apply invitation roles and add user to team_members.
 *
 * Handles the case where the user is already a member (upsert behaviour):
 * tries INSERT first; falls back to UPDATE on UNIQUE violation (code 23000).
 * This pattern is compatible with both MySQL and SQLite.
 *
 * @param ?DateTimeImmutable $now  Injectable for testing; defaults to current UTC.
 */
function acceptInvitationForUser(
    PDO $pdo,
    string $token,
    int $userId,
    ?DateTimeImmutable $now = null
): bool {
    $invitation = getInvitationByToken($pdo, $token);

    if ($invitation === null) {
        return false;
    }

    if ($invitation['accepted_at'] !== null) {
        return false;
    }

    // Expiry check: 7 days from created_at.
    $createdAt = new DateTimeImmutable($invitation['created_at'], new DateTimeZone('UTC'));
    $expiresAt = $createdAt->modify('+7 days');
    $now     ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if ($now > $expiresAt) {
        return false;
    }

    $roles       = explode(',', $invitation['intended_roles']);
    $isOwner     = in_array('owner', $roles, true) ? 1 : 0;
    $isDeveloper = in_array('developer', $roles, true) ? 1 : 0;
    $isRecipient = in_array('recipient', $roles, true) ? 1 : 0;

    // Upsert: try INSERT; on UNIQUE collision UPDATE existing roles.
    // Compatible with MySQL (code '23000') and SQLite (same SQLSTATE).
    try {
        $pdo->prepare('
            INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$invitation['team_id'], $userId, $isOwner, $isDeveloper, $isRecipient]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }

        $pdo->prepare('
            UPDATE team_members SET is_owner=?, is_developer=?, is_recipient=?
            WHERE team_id=? AND user_id=?
        ')->execute([$isOwner, $isDeveloper, $isRecipient, $invitation['team_id'], $userId]);
    }

    markAccepted($pdo, (int) $invitation['id']);

    return true;
}
