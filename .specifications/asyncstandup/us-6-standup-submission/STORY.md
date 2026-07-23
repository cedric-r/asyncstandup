# US-6: Standup Submission

**Feature**: asyncstandup-core  
**Story**: US-6

## User Story

**As a** team member  
**I can** click my unique standup link and submit answers to my team's custom questions  
**So that** my team can see my daily update

## Acceptance Criteria

1. **Given** valid and unused token, **When** link visited, **Then** form shown with team's questions in `position` order; member's display name shown
2. **Given** form completed and submitted, **When** POST processed, **Then** one `standup_submissions` row created; one `standup_answers` row per question (even if answer is empty); token's `used_at` set to UTC now; confirmation page shown with submitted answers
3. **Given** token already used (`used_at` is not null), **When** link visited, **Then** shows "Standup already submitted" with the original answers (read-only)
4. **Given** token's `expires_at` has passed, **When** link visited (used or not), **Then** shows "Link expired — please contact your team owner"
5. **Given** token string not found in DB, **When** link visited, **Then** shows "Invalid link"
6. **Given** form submitted, **When** processed, **Then** CSRF token validated; 403 on invalid
7. **Given** user not logged in, **When** submission link visited, **Then** form is accessible without login (token is the authenticator); no session required for submission

## Definition of Done

- [ ] All ACs met
- [ ] Token lookup and expiry check run before rendering form
- [ ] Submission is atomic: `standup_submissions` + all `standup_answers` in a single transaction
- [ ] No duplicate submission possible: UNIQUE constraint on `standup_submissions.token_id`
- [ ] No auth middleware on `submit.php` — token replaces session auth for this page
- [ ] `htmlspecialchars` on all displayed answer content (XSS prevention)

## Files

| Action | File |
|---|---|
| Create | `public/submit.php` — token validation, form, POST handler, confirmation |
| Create | `src/SubmissionRepository.php` |

## Implementation Details

### Token validation sequence

```php
$token = $_GET['token'] ?? '';
if (empty($token)) { showError('Invalid link'); exit; }

$row = $pdo->prepare('SELECT * FROM standup_tokens WHERE token = ?');
$row->execute([$token]);
$tokenData = $row->fetch();

if (!$tokenData) { showError('Invalid link'); exit; }

$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expiry = new DateTimeImmutable($tokenData['expires_at'], new DateTimeZone('UTC'));

if ($now > $expiry) { showError('Link expired — please contact your team owner'); exit; }

if ($tokenData['used_at'] !== null) {
    // Show read-only submitted answers
    showAlreadySubmitted($pdo, $tokenData); exit;
}
```

### Form rendering

1. Load team's questions: `SELECT * FROM team_questions WHERE team_id = ? ORDER BY position ASC`
2. Load user's display name from `users` table
3. Render `<form method="POST">` with one `<textarea name="answer[{question_id}]">` per question
4. Hidden CSRF token field; hidden token field

### Submission handler (POST)

```php
// 1. Re-validate token (race condition protection)
// 2. Begin transaction
// 3. INSERT INTO standup_submissions (token_id, user_id, team_id, submitted_at)
// 4. For each question_id in team:
//    INSERT INTO standup_answers (submission_id, question_id, answer)
//    answer = $_POST['answer'][$question_id] ?? ''
// 5. UPDATE standup_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?
// 6. Commit
// 7. Redirect to /submit.php?token=<token> (PRG pattern → shows read-only view)
```

### Already-submitted view

Load submission + answers via JOIN:

```sql
SELECT q.question, q.position, a.answer
FROM standup_submissions s
JOIN standup_answers a ON a.submission_id = s.id
JOIN team_questions q  ON q.id = a.question_id
WHERE s.token_id = ?
ORDER BY q.position ASC
```

Display as read-only list with submission timestamp.

### Schema fragments

```sql
CREATE TABLE IF NOT EXISTS standup_submissions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_id     INT UNSIGNED NOT NULL UNIQUE,
    user_id      INT UNSIGNED NOT NULL,
    team_id      INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (token_id) REFERENCES standup_tokens(id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (team_id)  REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS standup_answers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    question_id   INT UNSIGNED NOT NULL,
    answer        TEXT,
    FOREIGN KEY (submission_id) REFERENCES standup_submissions(id),
    FOREIGN KEY (question_id)   REFERENCES team_questions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
