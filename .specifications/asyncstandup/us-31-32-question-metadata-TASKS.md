# TASKS — US-31 + US-32: Question Metadata (Blocker Flagging + Mood Tracking)

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-31-32-question-metadata`  
**Agent**: PHP Developer (`fa2e6dbf`)

---

## Phase 1 — Branch + schema (US-31 AC-1, US-32 AC-1)

**T-1** `backend-dev` — Create shared branch
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout -b feature/us-31-32-question-metadata
```

**T-2** `backend-dev` — Add `is_blocker` and `is_mood` to `team_questions` in all 3 schema files

`tests/schema-sqlite.sql` — inside `CREATE TABLE team_questions` after `position`:
```sql
is_blocker  INTEGER NOT NULL DEFAULT 0,
is_mood     INTEGER NOT NULL DEFAULT 0,
```

`db/schema.sql` — append:
```sql
-- US-31: blocker question flagging
ALTER TABLE team_questions ADD COLUMN is_blocker TINYINT(1) NOT NULL DEFAULT 0;
-- US-32: mood question flagging
ALTER TABLE team_questions ADD COLUMN is_mood    TINYINT(1) NOT NULL DEFAULT 0;
```

`db/schema-postgresql.sql` — add both columns in `CREATE TABLE team_questions`; append migrations at bottom.

**T-3** `backend-dev` — Add `standup_mood_scores` table to all 3 schema files (US-32 AC-1)

`tests/schema-sqlite.sql` — append at end:
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

`db/schema.sql` — append `CREATE TABLE IF NOT EXISTS standup_mood_scores (...)` (MySQL version from STORY.md AC-1).

`db/schema-postgresql.sql` — PostgreSQL version (`SERIAL PRIMARY KEY`, `SMALLINT`, `TIMESTAMP`, `CONSTRAINT uq_... UNIQUE`).

---

## Phase 2 — `src/TeamRepository.php`: new functions (US-31 AC-2, US-32 AC-4)

**T-4** `backend-dev` — Add blocker helpers (US-31 AC-2)

Add after `getQuestions()`:
```php
function setBlockerQuestion(PDO $pdo, int $teamId, int $questionId): void
{
    $pdo->prepare('UPDATE team_questions SET is_blocker = 0 WHERE team_id = ?')->execute([$teamId]);
    $pdo->prepare('UPDATE team_questions SET is_blocker = 1 WHERE id = ? AND team_id = ?')->execute([$questionId, $teamId]);
}

function clearBlockerQuestion(PDO $pdo, int $teamId): void
{
    $pdo->prepare('UPDATE team_questions SET is_blocker = 0 WHERE team_id = ?')->execute([$teamId]);
}
```

**T-5** `backend-dev` — Add mood helpers (US-32 AC-4)

Add alongside blocker helpers:
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

**T-6** `backend-dev` — Update `getQuestions()` SELECT to include new columns

```sql
-- Before:
SELECT id, question, position FROM team_questions WHERE team_id = ? ORDER BY position
-- After:
SELECT id, question, position, is_blocker, is_mood FROM team_questions WHERE team_id = ? ORDER BY position
```

---

## Phase 3 — Summary email + response browser (US-31 AC-3, AC-4, AC-5)

**T-7** `backend-dev` — Update `assembleSummaryData()` questions query (US-31 AC-3)

In `src/SummaryEmailer.php`, update:
```sql
SELECT id, question, is_blocker FROM team_questions WHERE team_id = ? ORDER BY position
```

**T-8** `backend-dev` — Blocker highlighting in `templates/email/standup_summary.php` (US-31 AC-4)

In the answers loop, add prefix for non-empty blocker answers:
```php
$isBlocker = (bool) ($q['is_blocker'] ?? false);
$prefix    = ($isBlocker && $answer !== '') ? '⚠️ BLOCKER — ' : '';
echo $prefix . $answer;
```

**T-9** `backend-dev` — Blocker highlighting in `public/teams/responses.php` (US-31 AC-5)

In the `<dt>` and `<dd>` for each answer, apply conditional `text-red-700` class and `[blocker]` badge when `$q['is_blocker']` is set. See STORY.md US-31 AC-5 for exact HTML.

---

## Phase 4 — Mood scoring at submission time (US-32 AC-2, AC-3)

**T-10** `backend-dev` — Add `scoreMoodAnswer()` to `src/StandupEmailer.php` (US-32 AC-2)

Pure function — full implementation from US-32 STORY.md AC-2.

**T-11** `backend-dev` — Add `recordMoodScore()` and call it at submission time (US-32 AC-3)

Add `recordMoodScore(PDO $pdo, int $submissionId, int $questionId, string $answer, DateTimeImmutable $nowUtc): void` to `src/SubmissionRepository.php`.

Find where `standup_answers` rows are inserted. After the insert loop, check if any question has `is_mood = 1` for this team:
```php
// After answers are saved:
foreach ($answersPayload as $qa) {
    $q = getQuestionById($pdo, (int) $qa['question_id']);  // or use the questions array already loaded
    if ($q && (int)($q['is_mood'] ?? 0) === 1) {
        recordMoodScore($pdo, $submissionId, (int)$qa['question_id'], (string)($qa['answer'] ?? ''), $nowUtc);
    }
}
```

For portability: use `INSERT OR IGNORE` (SQLite) / `INSERT IGNORE` (MySQL) — implement via `dbInsertIgnore()` from US-25 if available; otherwise use a simple try/catch on duplicate key.

---

## Phase 5 — Mood trend on dashboard (US-32 AC-6)

**T-12** `backend-dev` — Add `getMoodTrend()` to `src/DashboardRepository.php`

Full implementation from US-32 STORY.md AC-6.

**T-13** `backend-dev` — Add mood trend section to `public/teams/dashboard.php`

1. Check if mood question exists: `$hasMoodQuestion = !empty(array_filter($questions, fn($q) => (int)($q['is_mood'] ?? 0) === 1));`
2. If yes, call `getMoodTrend($pdo, $teamId, $dateFrom30, $dateTo)` and render text table.
3. If no data yet, render "No mood data yet" message.
4. Add emoji scale legend below the table.

---

## Phase 6 — Questions UI (US-31 AC-6, US-32 AC-5)

**T-14** `backend-dev` — Update `public/teams/questions.php` POST handler

Add 4 new actions to the `if ($action === ...)` chain:
```php
elseif ($action === 'set_blocker' && $questionId > 0) {
    setBlockerQuestion($pdo, $teamId, $questionId);
    setFlash('success', 'Blocker question set.');
}
elseif ($action === 'clear_blocker') {
    clearBlockerQuestion($pdo, $teamId);
    setFlash('success', 'Blocker question cleared.');
}
elseif ($action === 'set_mood' && $questionId > 0) {
    setMoodQuestion($pdo, $teamId, $questionId);
    setFlash('success', 'Mood question set.');
}
elseif ($action === 'clear_mood') {
    clearMoodQuestion($pdo, $teamId);
    setFlash('success', 'Mood question cleared.');
}
```

**T-15** `backend-dev` — Add blocker + mood buttons to each question in the list HTML

Per question card, add two inline forms:
- Blocker: `⚠️ Blocker` (set/clear toggle) — red styling
- Mood: `😊 Mood` (set/clear toggle) — purple/indigo styling

See US-31 STORY.md AC-6 for blocker HTML pattern. Apply same pattern for mood.

---

## Phase 7 — PHPUnit tests (US-31 AC-7, US-32 AC-7)

**T-16** `backend-dev` — Create `tests/BlockerFlaggingTest.php` (US-31 — 3 tests)

```php
public function testSetBlockerClearsPreviousBlocker(): void
{
    // Insert team with 2 questions (q1 is_blocker=1, q2=0); call setBlockerQuestion(q2)
    // Assert: q1.is_blocker=0, q2.is_blocker=1
}

public function testBlockerFlagSurfacesInAssembledData(): void
{
    // Insert team, questions (one is_blocker=1), submission + answer
    // Call assembleSummaryData(); assert returned questions contain is_blocker=1 for that question
}

public function testClearBlockerRemovesAllFlags(): void
{
    // Set blocker on q1; call clearBlockerQuestion(); assert all is_blocker=0
}
```

**T-17** `backend-dev` — Create `tests/MoodTrackingTest.php` (US-32 — 3 tests)

```php
public function testScoreMoodAnswerKnownPatterns(): void
{
    $this->assertSame(5, scoreMoodAnswer('great'));
    $this->assertSame(1, scoreMoodAnswer('bad'));
    $this->assertSame(3, scoreMoodAnswer('ok'));
}

public function testScoreMoodAnswerReturnsNullForUnrecognised(): void
{
    $this->assertNull(scoreMoodAnswer('the PR was reviewed and merged'));
}

public function testIsMoodFlagOnlyOnePerTeam(): void
{
    // Insert 2 questions; setMoodQuestion(q1); then setMoodQuestion(q2)
    // Assert: q1.is_mood=0, q2.is_mood=1
}
```

**T-18** `backend-dev` — Run full test suite; target ≥94 tests (88 prior + 3 US-31 + 3 US-32)

---

## Phase 8 — Commit and signal

**T-19** `backend-dev` — Commit all changes
```bash
git add \
  db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
  src/TeamRepository.php src/StandupEmailer.php src/SummaryEmailer.php \
  src/SubmissionRepository.php src/DashboardRepository.php \
  templates/email/standup_summary.php \
  public/teams/questions.php public/teams/responses.php public/teams/dashboard.php \
  tests/BlockerFlaggingTest.php tests/MoodTrackingTest.php \
  .specifications/asyncstandup/us-31-blocker-flagging/ \
  .specifications/asyncstandup/us-32-mood-tracking/ \
  .specifications/asyncstandup/us-31-32-question-metadata-TASKS.md
git commit -m "feat(us-31-32): question metadata — is_blocker, is_mood, mood scoring, trend dashboard"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| US-31 AC-1 (schema is_blocker) | T-2 |
| US-31 AC-2 (setBlockerQuestion) | T-4 |
| US-31 AC-3 (assembleSummaryData) | T-6, T-7 |
| US-31 AC-4 (summary email highlight) | T-8 |
| US-31 AC-5 (responses browser) | T-9 |
| US-31 AC-6 (questions UI) | T-14, T-15 |
| US-31 AC-7 (3 tests) | T-16, T-18 |
| US-32 AC-1 (schema is_mood + table) | T-2, T-3 |
| US-32 AC-2 (scoreMoodAnswer) | T-10 |
| US-32 AC-3 (recordMoodScore) | T-11 |
| US-32 AC-4 (setMoodQuestion) | T-5 |
| US-32 AC-5 (questions UI) | T-14, T-15 |
| US-32 AC-6 (getMoodTrend + dashboard) | T-12, T-13 |
| US-32 AC-7 (3 tests) | T-17, T-18 |

**Estimate**: ~14h total (US-31 ~6h + US-32 ~8h)
