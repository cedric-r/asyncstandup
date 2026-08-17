<?php

declare(strict_types=1);

/**
 * Load a standup token row by its token string.
 */
function getTokenData(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('
        SELECT t.*, u.display_name, u.email
        FROM standup_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token = ?
    ');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Load an already-submitted standup with answers, keyed by question position.
 */
function getSubmissionWithAnswers(PDO $pdo, int $tokenId): ?array
{
    $stmt = $pdo->prepare('
        SELECT q.question, q.position, a.answer
        FROM standup_submissions ss
        JOIN standup_answers a ON a.submission_id = ss.id
        JOIN team_questions q  ON q.id = a.question_id
        WHERE ss.token_id = ?
        ORDER BY q.position ASC
    ');
    $stmt->execute([$tokenId]);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        return null;
    }

    return $rows;
}

/**
 * Save a standup submission atomically.
 *
 * Inserts standup_submissions, one standup_answers row per question,
 * and marks the token as used — all in a single transaction.
 *
 * @param array<int, string> $answers Keyed by question_id → answer text.
 */
function saveSubmission(PDO $pdo, int $tokenId, int $userId, int $teamId, array $answers): void
{
    $pdo->beginTransaction();

    try {
        // Insert submission.
        $pdo->prepare('
            INSERT INTO standup_submissions (token_id, user_id, team_id) VALUES (?, ?, ?)
        ')->execute([$tokenId, $userId, $teamId]);

        $submissionId = (int) $pdo->lastInsertId();

        // Insert one answer per question.
        $answerStmt = $pdo->prepare('
            INSERT INTO standup_answers (submission_id, question_id, answer) VALUES (?, ?, ?)
        ');

        foreach ($answers as $questionId => $answer) {
            $answerStmt->execute([$submissionId, (int) $questionId, (string) $answer]);
        }

        // Mark token as used. PHP-computed timestamp for MySQL+SQLite compatibility.
        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $pdo->prepare('UPDATE standup_tokens SET used_at = ? WHERE id = ?')
            ->execute([$nowUtc->format('Y-m-d H:i:s'), $tokenId]);

        // Record mood score if a mood question is configured for this team.
        $moodQStmt = $pdo->prepare('SELECT id FROM team_questions WHERE team_id = ? AND is_mood = 1 LIMIT 1');
        $moodQStmt->execute([$teamId]);
        $moodQ = $moodQStmt->fetch();
        if ($moodQ !== false && isset($answers[(int) $moodQ['id']])) {
            recordMoodScore($pdo, $submissionId, (int) $moodQ['id'], (string) $answers[(int) $moodQ['id']], $nowUtc);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Record a mood score for a submission answer.
 * Silently ignores duplicate-key violations (idempotent).
 */
function recordMoodScore(PDO $pdo, int $submissionId, int $questionId, string $answer, DateTimeImmutable $nowUtc): void
{
    $score = scoreMoodAnswer($answer);
    if ($score === null) {
        return;
    }

    try {
        $pdo->prepare('
            INSERT INTO standup_mood_scores (submission_id, question_id, score, scored_at)
            VALUES (?, ?, ?, ?)
        ')->execute([$submissionId, $questionId, $score, $nowUtc->format('Y-m-d H:i:s')]);
    } catch (PDOException) {
        // Duplicate key — score already recorded; ignore.
    }
}
