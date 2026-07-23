# IMPL-PLAN — PHP Developer
## AsyncStandUp — All Stories (US-1 through US-8)

**Status**: APPROVED
**Branch**: `feature/asyncstandup-core`
**Agent**: PHP Developer

---

## File List (exhaustive)

Every file to be created or modified. Reviewers cross-check this list against the diff.

### Configuration & Schema

| Action | File |
|---|---|
| Create | `config/config.example.php` |
| Create | `config/config.php` *(from example; gitignored)* |
| Create | `db/schema.sql` |
| Create | `.gitignore` |
| Create | `README.md` |
| Create | `logs/.gitkeep` |

### Source classes (`src/`)

| Action | File | Story |
|---|---|---|
| Create | `src/Db.php` | US-1 |
| Create | `src/Csrf.php` | US-1 |
| Create | `src/Auth.php` | US-1 |
| Create | `src/Mailer.php` | US-5 |
| Create | `src/OrgRepository.php` | US-2 |
| Create | `src/TeamRepository.php` | US-3 |
| Create | `src/InvitationRepository.php` | US-4 |
| Create | `src/StandupEmailer.php` | US-5 |
| Create | `src/SubmissionRepository.php` | US-6 |
| Create | `src/SummaryEmailer.php` | US-8 |
| Create | `src/DashboardRepository.php` | US-7 |

### Public pages (`public/`)

| Action | File | Story |
|---|---|---|
| Create | `public/index.php` | US-1 |
| Create | `public/register.php` | US-1 |
| Create | `public/login.php` | US-1 |
| Create | `public/logout.php` | US-1 |
| Create | `public/profile.php` | US-1 |
| Create | `public/dashboard.php` | US-7 |
| Create | `public/submit.php` | US-6 |
| Create | `public/orgs/index.php` | US-2 |
| Create | `public/orgs/create.php` | US-2 |
| Create | `public/orgs/edit.php` | US-2 |
| Create | `public/orgs/delete.php` | US-2 |
| Create | `public/teams/index.php` | US-3 |
| Create | `public/teams/create.php` | US-3 |
| Create | `public/teams/edit.php` | US-3 |
| Create | `public/teams/members.php` | US-3 |
| Create | `public/teams/questions.php` | US-3 |
| Create | `public/teams/recipients.php` | US-3 |
| Create | `public/teams/delete.php` | US-3 |
| Create | `public/teams/dashboard.php` | US-7 |
| Create | `public/invitations/send.php` | US-4 |
| Create | `public/invitations/accept.php` | US-4 |
| Create | `public/assets/style.css` | US-1 |

### Templates (`templates/`)

| Action | File | Story |
|---|---|---|
| Create | `templates/layout.php` | US-1 |
| Create | `templates/email/invitation.php` | US-4 |
| Create | `templates/email/standup_prompt.php` | US-5 |
| Create | `templates/email/standup_summary.php` | US-8 |

### Cron

| Action | File | Story |
|---|---|---|
| Create | `cron/send_standups.php` | US-5 / US-8 |

**No other files will be created.** If an unplanned file is required during implementation: STOP; create `PLAN-AMENDMENT-N.md`; notify Team Lead.

---

## Database Schema (`db/schema.sql`)

Full schema — all 12 tables in dependency order so the file can be executed cleanly from top to bottom.

```sql
-- AsyncStandUp schema
-- All DATETIME columns store UTC. Timezone conversion is handled in PHP only.
-- CASCADE deletes are handled in PHP (not DB) to make the order explicit.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100),
    timezone      VARCHAR(50) NOT NULL DEFAULT 'UTC',
    created_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organisations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    created_by  INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS org_members (
    org_id  INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id, user_id),
    FOREIGN KEY (org_id)  REFERENCES organisations(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teams (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id       INT UNSIGNED NOT NULL,
    name         VARCHAR(255) NOT NULL,
    timezone     VARCHAR(50) NOT NULL,
    standup_time TIME NOT NULL,
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (org_id)     REFERENCES organisations(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_members (
    team_id      INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    is_owner     TINYINT(1) NOT NULL DEFAULT 0,
    is_developer TINYINT(1) NOT NULL DEFAULT 0,
    is_recipient TINYINT(1) NOT NULL DEFAULT 0,
    joined_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (team_id, user_id),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_questions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id    INT UNSIGNED NOT NULL,
    question   VARCHAR(500) NOT NULL,
    position   INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_recipients (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id      INT UNSIGNED NOT NULL,
    email        VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    added_by     INT UNSIGNED,
    created_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    UNIQUE KEY uq_team_recipient (team_id, email),
    FOREIGN KEY (team_id)  REFERENCES teams(id),
    FOREIGN KEY (added_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invitations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id        INT UNSIGNED NOT NULL,
    invited_email  VARCHAR(255) NOT NULL,
    token          VARCHAR(64) NOT NULL UNIQUE,
    invited_by     INT UNSIGNED NOT NULL,
    intended_roles VARCHAR(50) NOT NULL DEFAULT 'developer',
    created_at     DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    accepted_at    DATETIME NULL,
    FOREIGN KEY (team_id)    REFERENCES teams(id),
    FOREIGN KEY (invited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS standup_tokens (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    send_date  DATE NOT NULL,
    sent_at    DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    UNIQUE KEY uq_token_team_user_date (team_id, user_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE IF NOT EXISTS summary_sent (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id   INT UNSIGNED NOT NULL,
    send_date DATE NOT NULL,
    sent_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    UNIQUE KEY uq_summary_team_date (team_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;
```

---

## Key Function Signatures

### `src/Db.php`

```php
function getDb(array $config): PDO
// Returns a singleton PDO instance with:
//   PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
//   PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
//   PDO::ATTR_EMULATE_PREPARES => false
//   charset=utf8mb4 in DSN
// Stored in a static variable to avoid reconnecting per request.
```

### `src/Csrf.php`

```php
function generateCsrfToken(): string
// If $_SESSION['csrf_token'] not set, generate bin2hex(random_bytes(32));
// store in session; return it.

function validateCsrfToken(string $submitted): void
// Retrieves $_SESSION['csrf_token']; calls hash_equals(); on mismatch:
// http_response_code(403); exit.
// Does NOT regenerate the token on validation (stable per session).
```

### `src/Auth.php`

```php
function startSession(): void
// Calls ini_set('session.cookie_httponly','1'), ini_set('session.cookie_samesite','Lax');
// then session_start().

function isLoggedIn(): bool
// Returns isset($_SESSION['user_id']).

function requireLogin(): void
// If not logged in: header('Location: /login.php'); exit.

function getCurrentUser(PDO $pdo): ?array
// SELECT * FROM users WHERE id = $_SESSION['user_id']; returns row or null.

function loginUser(PDO $pdo, string $email, string $password): bool
// SELECT user by email; password_verify(); set $_SESSION['user_id'];
// session_regenerate_id(true); return true on success, false otherwise.

function registerUser(PDO $pdo, string $email, string $password, string $displayName): int
// INSERT INTO users; return lastInsertId().

function logoutUser(): void
// session_destroy(); header Location login; exit.
```

### `src/Mailer.php`

```php
function sendMail(array $config, string $to, string $toName, string $subject, string $body): void
// Raw socket SMTP:
// 1. stream_socket_client("tcp://host:port", ..., 10 s timeout)
// 2. fgets() — read 220 greeting
// 3. smtpCommand($socket, "EHLO asyncstandup")       → expect 2xx
// 4. smtpCommand($socket, "MAIL FROM:<from>")         → expect 2xx
// 5. smtpCommand($socket, "RCPT TO:<$to>")            → expect 2xx
// 6. smtpCommand($socket, "DATA")                     → expect 354
// 7. fwrite headers: From, To, Subject, Date, MIME-Version, Content-Type
// 8. fwrite blank line then body
// 9. fwrite ".\r\n"                                   → expect 2xx
// 10. smtpCommand($socket, "QUIT")
// 11. fclose($socket)

function smtpRead(resource $socket): string
// fgets($socket, 512); reads full multi-line responses (250-... prefix loop).

function smtpCommand(resource $socket, string $command): string
// fwrite($socket, $command . "\r\n"); return smtpRead($socket).
// Throws RuntimeException if response code not 2xx or 3xx.
```

### `src/OrgRepository.php`

```php
function getOrgsForUser(PDO $pdo, int $userId): array
function createOrg(PDO $pdo, string $name, int $userId): int
function getOrgById(PDO $pdo, int $orgId): ?array
function updateOrg(PDO $pdo, int $orgId, string $name): void
function deleteOrg(PDO $pdo, int $orgId): void  // PHP cascade in FK-safe order
function isMember(PDO $pdo, int $orgId, int $userId): bool
```

### `src/TeamRepository.php`

```php
function getTeamsForOrg(PDO $pdo, int $orgId): array
function getTeamById(PDO $pdo, int $teamId): ?array
function createTeam(PDO $pdo, int $orgId, string $name, string $timezone, string $standupTime, int $userId): int
// Uses transaction: INSERT teams + INSERT team_members + INSERT team_questions (3 defaults)
function updateTeam(PDO $pdo, int $teamId, string $name, string $timezone, string $standupTime): void
function deleteTeam(PDO $pdo, int $teamId): void  // PHP cascade
function isOwner(PDO $pdo, int $teamId, int $userId): bool
function isMember(PDO $pdo, int $teamId, int $userId): bool
function getMembers(PDO $pdo, int $teamId): array
function updateMemberRoles(PDO $pdo, int $teamId, int $userId, int $isOwner, int $isDeveloper, int $isRecipient): void
function removeMember(PDO $pdo, int $teamId, int $userId): void
function getQuestions(PDO $pdo, int $teamId): array
function addQuestion(PDO $pdo, int $teamId, string $question): void
function updateQuestion(PDO $pdo, int $questionId, string $question): void
function deleteQuestion(PDO $pdo, int $questionId, int $teamId): void  // renumbers positions
function swapQuestionPositions(PDO $pdo, int $questionId, string $direction, int $teamId): void
function getRecipients(PDO $pdo, int $teamId): array
function addRecipient(PDO $pdo, int $teamId, string $email, string $displayName, int $addedBy): void
function removeRecipient(PDO $pdo, int $recipientId, int $teamId): void
```

### `src/InvitationRepository.php`

```php
function createInvitation(PDO $pdo, int $teamId, string $email, string $roles, int $invitedBy): string
// Replaces existing pending invitation for same team+email if present.
// Returns 64-char token.
function getInvitationByToken(PDO $pdo, string $token): ?array
function markAccepted(PDO $pdo, int $invitationId): void
function isAlreadyMember(PDO $pdo, int $teamId, string $email): bool
function hasPendingInvitation(PDO $pdo, int $teamId, string $email): bool
```

### `src/StandupEmailer.php`

```php
function getTeamsDueNow(PDO $pdo, DateTimeImmutable $nowUtc): array
// Fetches all teams; filters in PHP using timezone arithmetic.

function hasSentTokenToday(PDO $pdo, int $teamId, int $userId, string $sendDate): bool
function createToken(PDO $pdo, int $teamId, int $userId, string $sendDate, DateTimeImmutable $nowUtc): string
// Returns 64-char token. Ignores UNIQUE collision (race-safe INSERT IGNORE equivalent).

function sendStandupPrompt(PDO $pdo, array $config, array $team, array $member, string $token, string $sendDate): void
// Builds subject + body from template; calls sendMail().

function isTeamDue(array $team, DateTimeImmutable $nowUtc): bool
// Returns true if team's standup_time (in team TZ) is within 60s of nowUtc.
```

### `src/SubmissionRepository.php`

```php
function getTokenData(PDO $pdo, string $token): ?array
function getSubmissionWithAnswers(PDO $pdo, int $tokenId): ?array
function saveSubmission(PDO $pdo, int $tokenId, int $userId, int $teamId, array $answers): void
// Wraps INSERT standup_submissions + INSERT standup_answers + UPDATE token in a transaction.
```

### `src/SummaryEmailer.php`

```php
function getSummaryDueNow(PDO $pdo, DateTimeImmutable $nowUtc): array
// Fetches teams where standup_time + 1 hour matches current UTC minute.

function attemptInsertSummaryLock(PDO $pdo, int $teamId, string $sendDate): bool
// INSERT IGNORE INTO summary_sent; returns true if newly inserted (not duplicate).

function assembleSummaryData(PDO $pdo, int $teamId, string $sendDate): array
// Returns ['developers' => [...], 'questions' => [...], 'answers' => [userId][questionId] => text].

function sendSummaryEmail(PDO $pdo, array $config, array $team, string $sendDate, DateTimeImmutable $nowLocal): void
// Calls attemptInsertSummaryLock; assembles data; sends to each team_recipient.
```

### `src/DashboardRepository.php`

```php
function getTeamsForUser(PDO $pdo, int $userId): array
// All teams the user belongs to (any role).

function getTeamGrid(PDO $pdo, int $teamId, array $days): array
// Returns $grid[$userId][$date] = 'submitted'|'sent_not_submitted'|'not_sent'.

function getParticipationStats(PDO $pdo, int $teamId, string $dateFrom, string $dateTo): array
// Returns $stats[$userId] = ['sent' => int, 'submitted' => int].
```

---

## CSRF Strategy

**Token generation**: `generateCsrfToken()` in `src/Csrf.php`. On first call per session, `$_SESSION['csrf_token'] = bin2hex(random_bytes(32))`. Returns the token. Subsequent calls return the same token (stable per session — no per-form rotation, which would break back-button).

**Token embedding**: Every `<form method="POST">` includes:
```html
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
```

**Token validation**: Every POST handler calls `validateCsrfToken($_POST['csrf_token'] ?? '')` immediately after verifying the request method. `hash_equals($_SESSION['csrf_token'], $submitted)`. On mismatch: `http_response_code(403); echo 'Forbidden'; exit;`

**Session start**: All pages that need sessions call `startSession()` at the top (before any output). This sets `cookie_httponly` and `cookie_samesite=Lax` via `ini_set` before `session_start()`.

**Exception**: `public/submit.php` (standup submission) uses a CSRF token despite being session-free for general visitors. The token is embedded in a hidden field when the form is rendered on GET and validated on POST. The session is started only long enough to generate the CSRF token for the form.

---

## SMTP Strategy (`src/Mailer.php`)

**Connection**: `stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10)`.

**Handshake sequence**:
1. Read `220` greeting (fgets loop until non-`220-` line)
2. Send `EHLO asyncstandup\r\n` → read response (expect `250`)
3. Send `MAIL FROM:<{$from}>\r\n` → read (expect `250`)
4. Send `RCPT TO:<{$to}>\r\n` → read (expect `250`)
5. Send `DATA\r\n` → read (expect `354`)
6. Write headers:
   ```
   From: {from_name} <{from}>\r\n
   To: {toName} <{to}>\r\n
   Subject: {subject}\r\n
   Date: {date('r')}\r\n
   MIME-Version: 1.0\r\n
   Content-Type: text/plain; charset=UTF-8\r\n
   \r\n
   ```
7. Write body + `\r\n.\r\n` → read (expect `250`)
8. Send `QUIT\r\n` → fclose

**Error handling**: `smtpCommand()` throws `RuntimeException` on non-2xx/3xx response. Callers in `StandupEmailer.php` and `SummaryEmailer.php` catch this and call `logError()`, then continue to the next recipient.

**No SMTP AUTH** — plain relay assumed. If AUTH is needed, it would be added between EHLO and MAIL FROM (not in scope).

---

## Implementation Phases (ordered)

### Phase 0 — Project scaffold
`db/schema.sql`, `config/config.example.php`, `config/config.php`, `.gitignore`, `README.md`, `logs/.gitkeep`

### Phase 1 — US-1: Registration & Profile
`src/Db.php`, `src/Csrf.php`, `src/Auth.php`, `templates/layout.php`, `public/assets/style.css`, `public/index.php`, `public/register.php`, `public/login.php`, `public/logout.php`, `public/profile.php`

### Phase 2 — US-2: Organisations
`src/OrgRepository.php`, `public/orgs/index.php`, `public/orgs/create.php`, `public/orgs/edit.php`, `public/orgs/delete.php`

### Phase 3 — US-3: Teams
`src/TeamRepository.php`, `public/teams/index.php`, `public/teams/create.php`, `public/teams/edit.php`, `public/teams/members.php`, `public/teams/questions.php`, `public/teams/recipients.php`, `public/teams/delete.php`

### Phase 4 — US-4: Invitations
`src/InvitationRepository.php`, `templates/email/invitation.php`, `public/invitations/send.php`, `public/invitations/accept.php`

### Phase 5 — US-5: Daily Standup Emails
`src/Mailer.php`, `src/StandupEmailer.php`, `templates/email/standup_prompt.php`, `cron/send_standups.php` *(prompt pass only)*

### Phase 6 — US-6: Standup Submission
`src/SubmissionRepository.php`, `public/submit.php`

### Phase 7 — US-7: Dashboard
`src/DashboardRepository.php`, `public/dashboard.php`, `public/teams/dashboard.php`

### Phase 8 — US-8: Summary Email
`src/SummaryEmailer.php`, `templates/email/standup_summary.php`; modify `cron/send_standups.php` *(add summary pass)*

---

## PRG Pattern

All form POST handlers follow Post-Redirect-Get:
1. POST received → validate CSRF → process → set flash message in `$_SESSION['flash']`
2. `header('Location: <destination>'); exit;`
3. Destination page reads and clears `$_SESSION['flash']` on GET

Flash message structure: `['type' => 'success'|'error', 'text' => string]`.

---

## Template Rendering

Shared pattern for all pages:

```php
// In a public/*.php controller:
$vars = ['title' => 'Page Title', 'user' => $user, ...];
ob_start();
extract($vars, EXTR_SKIP);
include __DIR__ . '/../templates/layout.php';
$html = ob_get_clean();
echo $html;
```

`templates/layout.php` renders `<head>`, the `<body>` shell, and `include`s a content template. Each page defines `$content` or echoes directly within the layout.

---

## Security Rules (applied to every page)

1. `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on **all** output interpolated from DB or user input
2. PDO parameterised queries — no string interpolation into SQL
3. CSRF validation on every POST
4. `requireLogin()` at top of every authenticated page
5. Ownership/membership check before any mutating operation
6. `session_regenerate_id(true)` on login and registration
7. `password_hash($pw, PASSWORD_BCRYPT)` for storage; `password_verify()` for checks
8. No plaintext credentials in `config.php` committed to git (gitignored; only example committed)
9. Cron script: `php_sapi_name() !== 'cli'` guard at top — exit immediately if called over HTTP

---

## Cascade Delete Order (PHP-controlled)

Used by `deleteOrg()` and `deleteTeam()`. FK constraints are disabled during deletion (`SET foreign_key_checks = 0`) — alternatively, PHP deletes in the exact FK-safe order:

For org delete (includes all teams):
```
1. standup_answers
2. standup_submissions
3. standup_tokens
4. summary_sent
5. team_recipients
6. team_questions
7. invitations
8. team_members
9. teams
10. org_members
11. organisations
```

For team delete:
```
1. standup_answers
2. standup_submissions
3. standup_tokens
4. summary_sent
5. team_recipients
6. team_questions
7. invitations
8. team_members
9. teams
```

Each step uses `WHERE team_id = ?` (or joined via submission/token IDs).

---

## Self-Check Before Signalling READY FOR REVIEW

- [ ] All pages in file list exist and are syntactically valid PHP
- [ ] `htmlspecialchars()` on all output — no raw `echo $_GET`/`$_POST`/DB values
- [ ] No SQL string interpolation — PDO `?` or `:name` only
- [ ] CSRF validated on every POST handler
- [ ] `requireLogin()` on every authenticated page
- [ ] Cron script has `php_sapi_name() !== 'cli'` guard
- [ ] `session_regenerate_id(true)` on login and registration
- [ ] `password_hash()` / `password_verify()` — no plaintext comparison
- [ ] PRG on all forms
- [ ] `config.php` in `.gitignore` — `config.example.php` committed
- [ ] Schema creates all 12 tables; runs cleanly on a fresh DB
