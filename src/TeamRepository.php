<?php

declare(strict_types=1);

function getTeamsForOrg(PDO $pdo, int $orgId, int $userId): array
{
    $stmt = $pdo->prepare('
        SELECT t.* FROM teams t
        JOIN team_members tm ON tm.team_id = t.id
        WHERE t.org_id = ? AND tm.user_id = ?
        ORDER BY t.name
    ');
    $stmt->execute([$orgId, $userId]);

    return $stmt->fetchAll();
}

function getTeamById(PDO $pdo, int $teamId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

function createTeam(PDO $pdo, int $orgId, string $name, string $timezone, string $standupTime, int $userId): int
{
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO teams (org_id, name, timezone, standup_time, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$orgId, trim($name), $timezone, $standupTime, $userId]);
        $teamId = (int) $pdo->lastInsertId();

        // Add creator as owner + developer.
        $stmt2 = $pdo->prepare(
            'INSERT INTO team_members (team_id, user_id, is_owner, is_developer) VALUES (?, ?, 1, 1)'
        );
        $stmt2->execute([$teamId, $userId]);

        // Insert 3 default questions.
        $defaults = [
            'What did you do yesterday?',
            'What will you do today?',
            'Any blockers?',
        ];
        $stmt3 = $pdo->prepare(
            'INSERT INTO team_questions (team_id, question, position) VALUES (?, ?, ?)'
        );
        foreach ($defaults as $pos => $q) {
            $stmt3->execute([$teamId, $q, $pos + 1]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $teamId;
}

function updateTeam(PDO $pdo, int $teamId, string $name, string $timezone, string $standupTime): void
{
    $stmt = $pdo->prepare(
        'UPDATE teams SET name = ?, timezone = ?, standup_time = ? WHERE id = ?'
    );
    $stmt->execute([trim($name), $timezone, $standupTime, $teamId]);
}

/**
 * Delete a team and all cascaded data in FK-safe order.
 */
function deleteTeam(PDO $pdo, int $teamId): void
{
    // 1. standup_answers
    $pdo->prepare('
        DELETE a FROM standup_answers a
        JOIN standup_submissions ss ON ss.id = a.submission_id
        JOIN standup_tokens t ON t.id = ss.token_id
        WHERE t.team_id = ?
    ')->execute([$teamId]);

    // 2. standup_submissions
    $pdo->prepare('
        DELETE ss FROM standup_submissions ss
        JOIN standup_tokens t ON t.id = ss.token_id
        WHERE t.team_id = ?
    ')->execute([$teamId]);

    // 3. standup_tokens
    $pdo->prepare('DELETE FROM standup_tokens WHERE team_id = ?')->execute([$teamId]);

    // 4. summary_sent
    $pdo->prepare('DELETE FROM summary_sent WHERE team_id = ?')->execute([$teamId]);

    // 5. team_recipients
    $pdo->prepare('DELETE FROM team_recipients WHERE team_id = ?')->execute([$teamId]);

    // 6. team_questions
    $pdo->prepare('DELETE FROM team_questions WHERE team_id = ?')->execute([$teamId]);

    // 7. invitations
    $pdo->prepare('DELETE FROM invitations WHERE team_id = ?')->execute([$teamId]);

    // 8. team_members
    $pdo->prepare('DELETE FROM team_members WHERE team_id = ?')->execute([$teamId]);

    // 9. teams
    $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$teamId]);
}

function isTeamOwner(PDO $pdo, int $teamId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND is_owner = 1'
    );
    $stmt->execute([$teamId, $userId]);

    return $stmt->fetchColumn() !== false;
}

function isTeamMember(PDO $pdo, int $teamId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$teamId, $userId]);

    return $stmt->fetchColumn() !== false;
}

function getTeamMembers(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare('
        SELECT u.id, u.email, u.display_name, tm.is_owner, tm.is_developer, tm.is_recipient
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ?
        ORDER BY u.display_name
    ');
    $stmt->execute([$teamId]);

    return $stmt->fetchAll();
}

function updateMemberRoles(PDO $pdo, int $teamId, int $userId, int $isOwner, int $isDeveloper, int $isRecipient): void
{
    $stmt = $pdo->prepare('
        UPDATE team_members SET is_owner = ?, is_developer = ?, is_recipient = ?
        WHERE team_id = ? AND user_id = ?
    ');
    $stmt->execute([$isOwner, $isDeveloper, $isRecipient, $teamId, $userId]);
}

function removeMember(PDO $pdo, int $teamId, int $userId): void
{
    $stmt = $pdo->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?');
    $stmt->execute([$teamId, $userId]);
}

function getQuestions(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM team_questions WHERE team_id = ? ORDER BY position ASC'
    );
    $stmt->execute([$teamId]);

    return $stmt->fetchAll();
}

function addQuestion(PDO $pdo, int $teamId, string $question): void
{
    $maxPos = $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM team_questions WHERE team_id = ?');
    $maxPos->execute([$teamId]);
    $nextPos = (int) $maxPos->fetchColumn() + 1;

    $stmt = $pdo->prepare('INSERT INTO team_questions (team_id, question, position) VALUES (?, ?, ?)');
    $stmt->execute([$teamId, trim($question), $nextPos]);
}

function updateQuestion(PDO $pdo, int $questionId, int $teamId, string $question): void
{
    $stmt = $pdo->prepare('UPDATE team_questions SET question = ? WHERE id = ? AND team_id = ?');
    $stmt->execute([trim($question), $questionId, $teamId]);
}

function deleteQuestion(PDO $pdo, int $questionId, int $teamId): void
{
    $pdo->prepare('DELETE FROM team_questions WHERE id = ? AND team_id = ?')
        ->execute([$questionId, $teamId]);

    // Renumber remaining positions.
    $stmt = $pdo->prepare(
        'SELECT id FROM team_questions WHERE team_id = ? ORDER BY position ASC'
    );
    $stmt->execute([$teamId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $upd = $pdo->prepare('UPDATE team_questions SET position = ? WHERE id = ?');
    foreach ($ids as $i => $id) {
        $upd->execute([$i + 1, $id]);
    }
}

function swapQuestionPositions(PDO $pdo, int $questionId, string $direction, int $teamId): void
{
    $questions = getQuestions($pdo, $teamId);
    $index     = null;

    foreach ($questions as $i => $q) {
        if ((int) $q['id'] === $questionId) {
            $index = $i;
            break;
        }
    }

    if ($index === null) {
        return;
    }

    $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;

    if ($swapIndex < 0 || $swapIndex >= count($questions)) {
        return;
    }

    $posA = (int) $questions[$index]['position'];
    $posB = (int) $questions[$swapIndex]['position'];
    $idA  = (int) $questions[$index]['id'];
    $idB  = (int) $questions[$swapIndex]['id'];

    $upd = $pdo->prepare('UPDATE team_questions SET position = ? WHERE id = ?');
    $upd->execute([$posB, $idA]);
    $upd->execute([$posA, $idB]);
}

function getRecipients(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM team_recipients WHERE team_id = ? ORDER BY email'
    );
    $stmt->execute([$teamId]);

    return $stmt->fetchAll();
}

function addRecipient(PDO $pdo, int $teamId, string $email, string $displayName, int $addedBy): void
{
    $stmt = $pdo->prepare('
        INSERT INTO team_recipients (team_id, email, display_name, added_by)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$teamId, trim($email), trim($displayName), $addedBy]);
}

function removeRecipient(PDO $pdo, int $recipientId, int $teamId): void
{
    $pdo->prepare('DELETE FROM team_recipients WHERE id = ? AND team_id = ?')
        ->execute([$recipientId, $teamId]);
}
