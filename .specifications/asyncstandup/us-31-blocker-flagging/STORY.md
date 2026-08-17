# US-31: Blocker Question Flagging

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-31-32-question-metadata` (shared with US-32)

---

## Story

**As a** team owner  
**I want** to mark one question as the "blocker question"  
**So that** non-empty answers to it are visually highlighted in the summary email and response browser, drawing immediate attention to blockers

---

## Acceptance Criteria

### AC-1 — Schema: `is_blocker` on `team_questions`

`is_blocker TINYINT(1) NOT NULL DEFAULT 0` (MySQL), `BOOLEAN NOT NULL DEFAULT FALSE` (PostgreSQL), `INTEGER NOT NULL DEFAULT 0` (SQLite).

At most one question per team should have `is_blocker = 1` — enforced in PHP, not by DB constraint.

Migrations:
```sql
-- db/schema.sql (append)
ALTER TABLE team_questions ADD COLUMN is_blocker TINYINT(1) NOT NULL DEFAULT 0;
```

---

### AC-2 — `setBlockerQuestion()` in `src/TeamRepository.php`

```php
/**
 * Set exactly one question as the blocker for this team.
 * Clears all other is_blocker flags first (one-per-team invariant).
 */
function setBlockerQuestion(PDO $pdo, int $teamId, int $questionId): void
{
    $pdo->prepare('UPDATE team_questions SET is_blocker = 0 WHERE team_id = ?')
        ->execute([$teamId]);
    $pdo->prepare('UPDATE team_questions SET is_blocker = 1 WHERE id = ? AND team_id = ?')
        ->execute([$questionId, $teamId]);
}

function clearBlockerQuestion(PDO $pdo, int $teamId): void
{
    $pdo->prepare('UPDATE team_questions SET is_blocker = 0 WHERE team_id = ?')
        ->execute([$teamId]);
}
```

---

### AC-3 — `assembleSummaryData()` includes `is_blocker`

In `src/SummaryEmailer.php`, update the questions query:
```sql
SELECT id, question, is_blocker FROM team_questions WHERE team_id = ? ORDER BY position
```

The returned `$questions` array now includes `is_blocker` per question. Callers reading `$data['questions']` gain this field without interface changes.

---

### AC-4 — Summary email highlights blocker answers

In `templates/email/standup_summary.php` (plain text), prefix non-empty blocker answers:

```php
<?php foreach ($questions as $q): ?>
  <?php
  $answer     = $answers[(int) $q['id']] ?? '';
  $isBlocker  = (bool) ($q['is_blocker'] ?? false);
  $prefix     = ($isBlocker && $answer !== '') ? '⚠️ BLOCKER — ' : '';
  ?>
  <?= ($i + 1) ?>. <?= $q['question'] ?>
  <?= $prefix . $answer ?>
<?php endforeach; ?>
```

---

### AC-5 — Response browser highlights blocker answers

In `public/teams/responses.php`, in the answers `<dl>` loop, add a visual cue for blocker answers:

```php
<?php foreach ($questions as $q): ?>
  <dt class="text-xs font-medium <?= ($q['is_blocker'] ?? 0) ? 'text-red-700' : 'text-gray-600' ?> mb-0.5">
    <?= htmlspecialchars($q['question'], ENT_QUOTES, 'UTF-8') ?>
    <?php if ($q['is_blocker'] ?? 0): ?><span class="ml-1 text-xs bg-red-100 text-red-700 px-1 rounded">blocker</span><?php endif; ?>
  </dt>
  <dd class="text-sm <?= (($q['is_blocker'] ?? 0) && ($entry['answers'][(int)$q['id']] ?? '') !== '') ? 'text-red-800 font-medium' : 'text-gray-800' ?> ml-3 mb-2">
    <?php /* ... existing answer rendering ... */ ?>
  </dd>
<?php endforeach; ?>
```

Note: `$questions` in `responses.php` comes from `getQuestions()` in `src/TeamRepository.php`. Update `getQuestions()` to `SELECT id, question, position, is_blocker` so the field is available.

---

### AC-6 — Questions management UI: `public/teams/questions.php`

Add a "Mark as blocker" radio per question. Use a separate POST action `set_blocker`:

```php
// In POST handler
elseif ($action === 'set_blocker' && $questionId > 0) {
    setBlockerQuestion($pdo, $teamId, $questionId);
    setFlash('success', 'Blocker question set.');
}
elseif ($action === 'clear_blocker') {
    clearBlockerQuestion($pdo, $teamId);
    setFlash('success', 'Blocker question cleared.');
}
```

In the question list HTML, next to each question add a small form:
```php
<?php if ((int) $q['is_blocker'] === 1): ?>
  <form method="POST" action="/teams/questions.php?team_id=<?= $teamId ?>" class="inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <input type="hidden" name="action" value="clear_blocker">
    <button type="submit" class="text-xs text-red-600 hover:text-red-800">⚠️ Blocker</button>
  </form>
<?php else: ?>
  <form method="POST" action="/teams/questions.php?team_id=<?= $teamId ?>" class="inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
    <input type="hidden" name="action" value="set_blocker">
    <input type="hidden" name="question_id" value="<?= (int) $q['id'] ?>">
    <button type="submit" class="text-xs text-gray-400 hover:text-red-600">Set blocker</button>
  </form>
<?php endif; ?>
```

---

### AC-7 — PHPUnit tests: 3 new tests

New test class `tests/BlockerFlaggingTest.php`:

| Test | What it verifies |
|---|---|
| `testSetBlockerQuestionClearsPreviousBlocker` | After `setBlockerQuestion(q2)`, `q1.is_blocker = 0`; `q2.is_blocker = 1` |
| `testBlockerFlagSurfacesInAssembledData` | `assembleSummaryData()` returns `is_blocker = 1` for the flagged question |
| `testClearBlockerQuestionRemovesAllFlags` | After `clearBlockerQuestion()`, all questions have `is_blocker = 0` |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append `is_blocker` migration |
| `db/schema-postgresql.sql` | Add column + migration |
| `tests/schema-sqlite.sql` | Add column to `CREATE TABLE team_questions` |
| `src/TeamRepository.php` | Add `setBlockerQuestion()`, `clearBlockerQuestion()`; update `getQuestions()` SELECT |
| `src/SummaryEmailer.php` | Update `assembleSummaryData()` questions query |
| `templates/email/standup_summary.php` | Add blocker prefix for non-empty answers |
| `public/teams/responses.php` | Blocker answer highlighting |
| `public/teams/questions.php` | Set/clear blocker POST actions + UI |
| `tests/BlockerFlaggingTest.php` (new) | 3 PHPUnit tests |
