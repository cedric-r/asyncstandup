# IMPL-PLAN — US-31 + US-32: Question Metadata (Blocker Flagging + Mood Tracking)

**Status**: APPROVED
**Branch**: `feature/us-31-32-question-metadata`
**Agent**: PHP Developer (`fa2e6dbf`)
**Stories**: US-31 (Blocker Question Flagging) + US-32 (Mood Tracking)

---

## Scope

All changes within bounds of STORY.md AC-1–7 for US-31 and AC-1–7 for US-32, and TASKS.md T-1–T-19.

No new Composer dependencies. 15 files modified/created.

---

## Files to Create or Modify

| File | Type | Change |
|---|---|---|
| `tests/schema-sqlite.sql` | Modify | Add `is_blocker`, `is_mood` to `CREATE TABLE team_questions`; add `standup_mood_scores` table |
| `db/schema.sql` | Modify | Append ALTER TABLE for `is_blocker`, `is_mood`; append `standup_mood_scores` CREATE TABLE (MySQL) |
| `db/schema-postgresql.sql` | Modify | Add columns inline + migrations + `standup_mood_scores` table (PostgreSQL) |
| `src/TeamRepository.php` | Modify | Add `setBlockerQuestion()`, `clearBlockerQuestion()`, `setMoodQuestion()`, `clearMoodQuestion()`; update `getQuestions()` SELECT |
| `src/StandupEmailer.php` | Modify | Add `scoreMoodAnswer()` |
| `src/SummaryEmailer.php` | Modify | Update `assembleSummaryData()` questions query to include `is_blocker` |
| `src/SubmissionRepository.php` | Modify | In `saveSubmission()`, after answer insert loop: detect mood question and call `recordMoodScore()` |
| `src/DashboardRepository.php` | Modify | Add `getMoodTrend()` |
| `templates/email/standup_summary.php` | Modify | Prefix non-empty blocker answers with `⚠️ BLOCKER — ` |
| `public/teams/questions.php` | Modify | 4 new POST actions (`set_blocker`, `clear_blocker`, `set_mood`, `clear_mood`); per-question blocker + mood toggle buttons |
| `public/teams/responses.php` | Modify | Conditional `text-red-700` on `<dt>`, `[blocker]` badge, `font-medium` on `<dd>` for blocker answers |
| `public/teams/dashboard.php` | Modify | 30-day mood trend section (text table + emoji legend) for owners |
| `tests/BlockerFlaggingTest.php` | Create | 3 PHPUnit tests (US-31) |
| `tests/MoodTrackingTest.php` | Create | 3 PHPUnit tests (US-32) |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-31-32-question-metadata` created from `main`.

---

### T-2 — Schema: `is_blocker` + `is_mood` on `team_questions` (US-31 AC-1, US-32 AC-1)

**`tests/schema-sqlite.sql`** — inside `CREATE TABLE team_questions`, after `position INTEGER NOT NULL DEFAULT 0,`:
```sql
is_blocker  INTEGER NOT NULL DEFAULT 0,
is_mood     INTEGER NOT NULL DEFAULT 0,
```

**`db/schema.sql`** — append:
```sql
-- US-31: blocker question flagging
ALTER TABLE team_questions ADD COLUMN is_blocker TINYINT(1) NOT NULL DEFAULT 0;
-- US-32: mood question flagging
ALTER TABLE team_questions ADD COLUMN is_mood    TINYINT(1) NOT NULL DEFAULT 0;
```

**`db/schema-postgresql.sql`** — add both columns inline in `CREATE TABLE team_questions`; append migrations at bottom.

---

### T-3 — Schema: `standup_mood_scores` table (US-32 AC-1)

**`tests/schema-sqlite.sql`** — append at end of file:
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

**`db/schema.sql`** — append MySQL CREATE TABLE as specified in US-32 STORY.md AC-1.

**`db/schema-postgresql.sql`** — append PostgreSQL version (`SERIAL PRIMARY KEY`, `SMALLINT`, `TIMESTAMP`, `CONSTRAINT uq_mood_submission_question UNIQUE`).

---

### T-4 — Add blocker helpers to `src/TeamRepository.php` (US-31 AC-2)

Add `setBlockerQuestion()` + `clearBlockerQuestion()` after `getQuestions()`. Exact implementation from US-31 STORY.md AC-2. Both use two `UPDATE` statements (clear all → set one).

---

### T-5 — Add mood helpers to `src/TeamRepository.php` (US-32 AC-4)

Add `setMoodQuestion()` + `clearMoodQuestion()` alongside blocker helpers. Same two-statement pattern.

---

### T-6 — Update `getQuestions()` SELECT (US-31 AC-3 / US-32 implied)

Current: `SELECT * FROM team_questions WHERE team_id = ? ORDER BY position ASC`

Change to: `SELECT id, question, position, is_blocker, is_mood FROM team_questions WHERE team_id = ? ORDER BY position ASC`

This makes `is_blocker` and `is_mood` available everywhere `getQuestions()` is called (responses.php, questions.php, dashboard.php).

---

### T-7 — Update `assembleSummaryData()` query (US-31 AC-3)

In `src/SummaryEmailer.php` line 78, change:
```sql
SELECT id, question FROM team_questions WHERE team_id = ? ORDER BY position
```
to:
```sql
SELECT id, question, is_blocker FROM team_questions WHERE team_id = ? ORDER BY position
```

---

### T-8 — Blocker highlighting in `templates/email/standup_summary.php` (US-31 AC-4)

Current line 22: `<?= !empty($sub['answers'][(int) $q['id']]) ? $sub['answers'][(int) $q['id']] : '(no answer)' ?>`

Replace the answer echo with:
```php
$answer    = !empty($sub['answers'][(int) $q['id']]) ? $sub['answers'][(int) $q['id']] : '';
$isBlocker = (bool) ($q['is_blocker'] ?? false);
$prefix    = ($isBlocker && $answer !== '') ? '⚠️ BLOCKER — ' : '';
echo $prefix . ($answer !== '' ? $answer : '(no answer)');
```

---

### T-9 — Blocker highlighting in `public/teams/responses.php` (US-31 AC-5)

Current `<dt>` at line 159: plain `text-gray-600`.
Current `<dd>` at line 160: plain `text-gray-800`.

Replace with conditional classes:
- `<dt>`: `text-red-700` when `$q['is_blocker']`; add `[blocker]` badge span
- `<dd>`: `text-red-800 font-medium` when blocker AND non-empty answer

---

### T-10 — Add `scoreMoodAnswer()` to `src/StandupEmailer.php` (US-32 AC-2)

Pure function — no DB access. Full pattern-matching implementation from US-32 STORY.md AC-2. Returns `int` 1–5 or `null`.

---

### T-11 — Add `recordMoodScore()` + call in `saveSubmission()` (US-32 AC-3)

Add `recordMoodScore(PDO $pdo, int $submissionId, int $questionId, string $answer, DateTimeImmutable $nowUtc): void` to `src/SubmissionRepository.php`.

For portability across MySQL/SQLite/PostgreSQL: use `try/catch` on a plain INSERT — on duplicate key/unique violation the catch block does nothing (idempotent: score already recorded). This avoids driver-specific upsert syntax.

**Calling it**: In `saveSubmission()`, after the `foreach ($answers as $questionId => $answer)` loop, fetch the team's mood question:
```php
$moodQStmt = $pdo->prepare('SELECT id FROM team_questions WHERE team_id = ? AND is_mood = 1 LIMIT 1');
$moodQStmt->execute([$teamId]);
$moodQ = $moodQStmt->fetch();
if ($moodQ && isset($answers[(int) $moodQ['id']])) {
    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    recordMoodScore($pdo, $submissionId, (int) $moodQ['id'], (string) $answers[(int) $moodQ['id']], $nowUtc);
}
```

This is inside the existing transaction, so a mood scoring failure rolls back cleanly.

---

### T-12 — Add `getMoodTrend()` to `src/DashboardRepository.php` (US-32 AC-6)

Full implementation from US-32 STORY.md AC-6. Parameters: `(PDO $pdo, int $teamId, string $dateFrom, string $dateTo): array`.

---

### T-13 — Mood trend section in `public/teams/dashboard.php` (US-32 AC-6)

1. Load `$questions` via `getQuestions($pdo, $teamId)` (already available in dashboard; verify or add).
2. Check `$hasMoodQuestion = !empty(array_filter($questions, fn($q) => (int)($q['is_mood'] ?? 0) === 1))`.
3. If `$hasMoodQuestion` (owner only): compute `$dateFrom30 = date('Y-m-d', strtotime('-30 days'))`, `$dateTo = date('Y-m-d')`, call `getMoodTrend()`.
4. Render text table with `Date | Avg | Responses`, emoji scale legend below.
5. If no data: "No mood data yet" message.

---

### T-14 — `public/teams/questions.php` POST handler: 4 new actions (US-31 AC-6, US-32 AC-5)

Append to the existing `if ($action === ...)` chain (after existing `delete`, `up`, `down` branches):
```php
elseif ($action === 'set_blocker' && $questionId > 0) { setBlockerQuestion($pdo, $teamId, $questionId); setFlash('success', 'Blocker question set.'); }
elseif ($action === 'clear_blocker')                  { clearBlockerQuestion($pdo, $teamId);             setFlash('success', 'Blocker question cleared.'); }
elseif ($action === 'set_mood' && $questionId > 0)    { setMoodQuestion($pdo, $teamId, $questionId);     setFlash('success', 'Mood question set.'); }
elseif ($action === 'clear_mood')                     { clearMoodQuestion($pdo, $teamId);                setFlash('success', 'Mood question cleared.'); }
```

---

### T-15 — Per-question blocker + mood toggle buttons in `public/teams/questions.php` (US-31 AC-6, US-32 AC-5)

Per question card, append two inline forms after the existing edit/delete buttons:

- **Blocker**: if `is_blocker=1` → `⚠️ Blocker` button (clear_blocker action, red); else → `Set blocker` button (set_blocker action, gray)
- **Mood**: if `is_mood=1` → `😊 Mood ✓` button (clear_mood action, indigo); else → `Set mood` button (set_mood action, gray)

All forms include `csrf_token` hidden field + `question_id`.

---

### T-16 — Create `tests/BlockerFlaggingTest.php` (US-31 — 3 tests)

Tests exactly as specified in TASKS.md T-16. Uses `createTestPdo()` (SQLite in-memory). Requires `assembleSummaryData()` available via `require_once src/SummaryEmailer.php`.

---

### T-17 — Create `tests/MoodTrackingTest.php` (US-32 — 3 tests)

Tests exactly as specified in TASKS.md T-17. `scoreMoodAnswer()` tests are pure (no DB). The `setMoodQuestion` test uses SQLite in-memory.

---

### T-18 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥95 tests (89 prior + 3 US-31 + 3 US-32), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors.

---

### T-19 — Commit

Single commit, all 15 files:
`feat(us-31-32): question metadata — is_blocker, is_mood, mood scoring, trend dashboard`

---

## Risk Notes

1. **`getQuestions()` currently uses `SELECT *`** — changing to explicit column list adds `is_blocker` and `is_mood` safely; no callers depend on `SELECT *` behaviour.
2. **`assembleSummaryData()` vs `getQuestions()`** — separate query, must be updated independently (T-7 separate from T-6).
3. **`recordMoodScore()` portability** — using `try/catch (PDOException)` for duplicate detection rather than driver-specific upsert syntax; this is the safest cross-driver approach.
4. **`saveSubmission()` already in a transaction** — `recordMoodScore()` call inside the existing `try` block will rollback if mood scoring fails, which is acceptable since a failed score on a submitted standup is more recoverable than a lost submission.
5. **Dashboard `$questions` availability** — need to verify `getQuestions()` is already called in `dashboard.php`; if not, add the call. Will check before T-13.
6. **PHPStan `is_mood` / `is_blocker` from DB** — PDO returns strings; `(int)($q['is_mood'] ?? 0) === 1` pattern avoids type coercion issues and is PHPStan-clean.
