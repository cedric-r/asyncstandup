# US-32: Mood / Sentiment Tracking

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-31-32-question-metadata` (shared with US-31)

---

## Story

**As a** team owner  
**I want** to designate one question as the "mood question" and see a 30-day sentiment trend on the dashboard  
**So that** I can monitor team wellbeing without reading every standup manually

---

## Acceptance Criteria

### AC-1 — Schema: `is_mood` on `team_questions` + `standup_mood_scores` table

**`team_questions.is_mood`**:
```sql
-- db/schema.sql (append alongside is_blocker migration)
ALTER TABLE team_questions ADD COLUMN is_mood TINYINT(1) NOT NULL DEFAULT 0;
```
SQLite: `is_mood INTEGER NOT NULL DEFAULT 0`. PostgreSQL: `is_mood BOOLEAN NOT NULL DEFAULT FALSE`.

At most one question per team should have `is_mood = 1` — enforced in PHP.

**`standup_mood_scores` table**:
```sql
CREATE TABLE IF NOT EXISTS standup_mood_scores (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    question_id   INT UNSIGNED NOT NULL,
    score         TINYINT NOT NULL,     -- 1–5
    scored_at     DATETIME NOT NULL,
    UNIQUE KEY uq_mood_submission_question (submission_id, question_id),
    FOREIGN KEY (submission_id) REFERENCES standup_submissions(id),
    FOREIGN KEY (question_id)   REFERENCES team_questions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

SQLite equivalent (in `tests/schema-sqlite.sql`):
```sql
CREATE TABLE IF NOT EXISTS standup_mood_scores (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    question_id   INTEGER NOT NULL,
    score         INTEGER NOT NULL,
    scored_at     TEXT NOT NULL,
    UNIQUE(submission_id, question_id),
    FOREIGN KEY (submission_id) REFERENCES standup_submissions(id),
    FOREIGN KEY (question_id)   REFERENCES team_questions(id)
);
```

---

### AC-2 — `scoreMoodAnswer(string $answer): ?int` in `src/StandupEmailer.php`

Pure function — no DB access. Returns 1–5 or `null` (unrecognised).

```php
function scoreMoodAnswer(string $answer): ?int
{
    $lower = mb_strtolower(trim($answer));
    if ($lower === '') { return null; }

    // Score 5 — very positive
    if (preg_match('/😀|🔥|great|awesome|excellent|fantastic|amazing/u', $lower)) { return 5; }
    // Score 4 — positive
    if (preg_match('/👍|good|well|solid|productive|happy/u', $lower))             { return 4; }
    // Score 3 — neutral
    if (preg_match('/ok|okay|fine|alright|average|normal|decent/u', $lower))     { return 3; }
    // Score 2 — negative
    if (preg_match('/tired|meh|slow|rough|struggling|stressed/u', $lower))       { return 2; }
    // Score 1 — very negative
    if (preg_match('/😞|😢|bad|blocked|terrible|awful|sick|burned/u', $lower))   { return 1; }

    return null;  // unrecognised — do not store
}
```

---

### AC-3 — Mood score stored at submission time

In `src/SubmissionRepository.php` (or wherever `standup_answers` rows are inserted), after saving answers, check if any question is the mood question for this team. If so, score the answer and insert into `standup_mood_scores`.

New function `recordMoodScore(PDO $pdo, int $submissionId, int $questionId, string $answer, DateTimeImmutable $nowUtc): void`:

```php
function recordMoodScore(PDO $pdo, int $submissionId, int $questionId, string $answer, DateTimeImmutable $nowUtc): void
{
    $score = scoreMoodAnswer($answer);
    if ($score === null) { return; }

    $pdo->prepare('
        INSERT INTO standup_mood_scores (submission_id, question_id, score, scored_at)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE score = VALUES(score), scored_at = VALUES(scored_at)
    ')->execute([$submissionId, $questionId, $score, $nowUtc->format('Y-m-d H:i:s')]);
}
```

For SQLite/PostgreSQL portability, use `dbInsertIgnore()` (from US-25) and a separate UPDATE if a row already exists — or use the driver-aware upsert helper. Implementation agent should choose the simplest portable approach.

---

### AC-4 — `setMoodQuestion()` / `clearMoodQuestion()` in `src/TeamRepository.php`

Same pattern as `setBlockerQuestion()` / `clearBlockerQuestion()`:

```php
function setMoodQuestion(PDO $pdo, int $teamId, int $questionId): void
{
    $pdo->prepare('UPDATE team_questions SET is_mood = 0 WHERE team_id = ?')->execute([$teamId]);
    $pdo->prepare('UPDATE team_questions SET is_mood = 1 WHERE id = ? AND team_id = ?')->execute([$questionId, $teamId]);
}

function clearMoodQuestion(PDO $pdo, int $teamId): void
{
    $pdo->prepare('UPDATE team_questions SET is_mood = 0 WHERE team_id = ?')->execute([$teamId]);
}
```

---

### AC-5 — Questions UI: `public/teams/questions.php`

Add "Set mood question" / "Mood ✓" button per question — same pattern as blocker in US-31. POST actions: `set_mood`, `clear_mood`.

`is_blocker` and `is_mood` are independent flags — a question can (theoretically) be both, though this would be unusual. No constraint prevents it.

---

### AC-6 — 30-day mood trend on `public/teams/dashboard.php`

New function `getMoodTrend(PDO $pdo, int $teamId, string $dateFrom, string $dateTo): array` in `src/DashboardRepository.php`:

```php
function getMoodTrend(PDO $pdo, int $teamId, string $dateFrom, string $dateTo): array
{
    $stmt = $pdo->prepare('
        SELECT t.send_date, AVG(ms.score) AS avg_score, COUNT(ms.id) AS responses
        FROM standup_tokens t
        JOIN standup_submissions ss ON ss.token_id = t.id
        JOIN standup_mood_scores ms ON ms.submission_id = ss.id
        JOIN team_questions q ON q.id = ms.question_id
        WHERE t.team_id = ?
          AND t.send_date BETWEEN ? AND ?
          AND q.is_mood = 1
        GROUP BY t.send_date
        ORDER BY t.send_date ASC
    ');
    $stmt->execute([$teamId, $dateFrom, $dateTo]);
    return $stmt->fetchAll();
}
```

On `public/teams/dashboard.php` (owner view), add a "Mood Trend" section below the participation grid. Render as a text table (no JS charting):

```
Mood Trend — last 30 days
Date         Avg   Responses
2026-08-17   4.2   5
2026-08-16   3.5   4
...
```

Add emoji scale legend: 😞 1 | 😐 2 | 😐 3 | 👍 4 | 😀 5

Display only if the team has a mood question configured (`any is_mood = 1` in `getQuestions()`). If no mood data yet: "No mood data yet. Set a mood question to start tracking."

---

### AC-7 — PHPUnit tests: 3 new tests

New test class `tests/MoodTrackingTest.php`:

| Test | What it verifies |
|---|---|
| `testScoreMoodAnswerKnownPatterns` | `scoreMoodAnswer('great')` = 5; `scoreMoodAnswer('bad')` = 1; `scoreMoodAnswer('ok')` = 3 |
| `testScoreMoodAnswerReturnsNullForUnrecognised` | `scoreMoodAnswer('the project is progressing well on tickets')` = null (too specific; no keyword match) |
| `testIsMoodFlagOnlyOnOneQuestionPerTeam` | After `setMoodQuestion(q2)`, `q1.is_mood = 0`; `q2.is_mood = 1` |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append `is_mood` migration + `standup_mood_scores` CREATE TABLE |
| `db/schema-postgresql.sql` | Add column + table |
| `tests/schema-sqlite.sql` | Add `is_mood` column + `standup_mood_scores` table |
| `src/StandupEmailer.php` | Add `scoreMoodAnswer()` |
| `src/SubmissionRepository.php` | Call `recordMoodScore()` after answer insert |
| `src/TeamRepository.php` | Add `setMoodQuestion()`, `clearMoodQuestion()`; update `getQuestions()` SELECT to include `is_mood` |
| `src/DashboardRepository.php` | Add `getMoodTrend()` |
| `public/teams/questions.php` | Set/clear mood POST actions + UI |
| `public/teams/dashboard.php` | 30-day mood trend section |
| `tests/MoodTrackingTest.php` (new) | 3 PHPUnit tests |
