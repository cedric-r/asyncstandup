# AsyncStandUp — Implementation Tasks

**Project**: AsyncStandUp  
**Stack**: Vanilla PHP 8.1 / MySQL / Raw socket SMTP  
**Branch**: `feature/asyncstandup-core`

---

## Phase 0: Project Scaffold & DB Schema
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
1. Create project directory structure
   - [ ] `public/` — web root (all browser-accessible PHP files)
   - [ ] `src/` — shared PHP classes/helpers (not web-accessible)
   - [ ] `templates/email/` — email templates
   - [ ] `cron/` — CLI scripts
   - [ ] `config/` — config files
   - [ ] `logs/` — error logs (`.gitignore`: `logs/*.log`)
   - [ ] `assets/` — placeholder logo PNG

2. Create `config/config.example.php`
   - [ ] DB, SMTP, app sections per FEATURE.md config schema
   - [ ] `config/config.php` excluded from git (add to `.gitignore`)

3. Create `schema.sql` — all 12 tables
   - [ ] `users`, `organisations`, `org_members`, `teams`, `team_members`
   - [ ] `team_recipients`, `team_questions`, `invitations`
   - [ ] `standup_tokens`, `standup_submissions`, `standup_answers`, `summary_sent`
   - [ ] All with `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`
   - [ ] All FK constraints; all UNIQUE constraints per story schemas
   - [ ] `CREATE TABLE IF NOT EXISTS` throughout (idempotent)

4. Create shared PHP helpers
   - [ ] `src/Db.php` — PDO singleton; reads from `config/config.php`; charset `utf8mb4`; `ATTR_ERRMODE = EXCEPTION`
   - [ ] `src/Auth.php` — `isLoggedIn()`, `requireLogin()`, `getCurrentUser(PDO)`; session cookie settings (`httponly`, `samesite=Lax`)
   - [ ] `src/Csrf.php` — `generateToken()`: stores in `$_SESSION['csrf_token']`; `validateToken(string)`: compare with `hash_equals`
   - [ ] `src/View.php` — `render(string $template, array $vars)`: `ob_start()` + `extract()` + `include` + `ob_get_clean()`; shared header/footer includes

5. Create `.htaccess` (Apache) / `nginx.conf` note in README
   - [ ] Deny direct access to `src/`, `cron/`, `config/`, `logs/`, `templates/`
   - [ ] All traffic to `public/` only

6. Create `README.md`
   - [ ] Requirements: PHP 8.1, MySQL 5.7+, extensions: pdo_mysql, openssl
   - [ ] Setup: copy `config.example.php` → `config.php`, fill credentials, run `schema.sql`
   - [ ] Cron: `* * * * * php /path/to/cron/send_standups.php >> /var/log/asyncstandup.log 2>&1`
   - [ ] SMTP: no-auth relay required; raw socket implementation
   - [ ] Timezone note: all DB datetimes UTC; team timezone used for scheduling

---

## Phase 1: Registration & Profile (US-1)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
7. Create `public/register.php`
   - [ ] GET: render form (email, password, display_name, CSRF token)
   - [ ] POST: validate CSRF → check duplicate email → `password_hash(PASSWORD_BCRYPT)` → INSERT → set session → `session_regenerate_id(true)` → redirect dashboard

8. Create `public/login.php`
   - [ ] GET: render login form with CSRF token
   - [ ] POST: validate CSRF → SELECT by email → `password_verify()` → set `$_SESSION['user_id']` → `session_regenerate_id(true)` → redirect

9. Create `public/logout.php`
   - [ ] POST only (with CSRF); `session_destroy()`; redirect to login

10. Create `public/profile.php`
    - [ ] `requireLogin()`
    - [ ] GET: load user; render form (display_name, timezone select via `DateTimeZone::listIdentifiers()`)
    - [ ] POST: validate CSRF → UPDATE users → flash "Profile saved" → redirect

11. Create `src/UserRepository.php`
    - [ ] `findByEmail(PDO, string): ?array`
    - [ ] `createUser(PDO, string $email, string $hash, string $displayName, string $tz): int`
    - [ ] `updateProfile(PDO, int $id, string $displayName, string $tz): void`

---

## Phase 2: Organisations (US-2)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
12. Create `src/OrgRepository.php`
    - [ ] `getOrgsForUser`, `createOrg`, `updateOrg`, `deleteOrg` (cascade in PHP per US-2 order), `isMember`

13. Create `public/orgs/index.php` — list orgs; link to create/edit/delete

14. Create `public/orgs/create.php` — form + POST handler; auto-add user to `org_members`

15. Create `public/orgs/edit.php` — membership + CSRF check; form + POST handler

16. Create `public/orgs/delete.php` — membership check; confirm form; cascade delete

---

## Phase 3: Teams (US-3)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
17. Create `src/TeamRepository.php`
    - [ ] `getTeamsForUser`, `createTeam` (+ insert 3 default questions in transaction), `updateTeam`
    - [ ] `isOwner(PDO, int $teamId, int $userId): bool`
    - [ ] `deleteTeam` (cascade in FK-safe order per US-3)
    - [ ] `getMembers`, `updateMemberRoles`, `removeMember`
    - [ ] `getQuestions`, `addQuestion`, `updateQuestion`, `deleteQuestion`, `reorderQuestions`
    - [ ] `getRecipients`, `addRecipient`, `removeRecipient`

18. Create `public/teams/index.php` — list teams per org

19. Create `public/teams/create.php` — form (name, timezone select, standup_time HH:MM)

20. Create `public/teams/edit.php` — settings form (name, timezone, standup_time); owner check

21. Create `public/teams/members.php` — list members; role toggle checkboxes (is_owner / is_developer / is_recipient); remove member button

22. Create `public/teams/questions.php` — list questions with edit/delete/reorder (up/down links); add question form

23. Create `public/teams/recipients.php` — list external recipients; add email + display_name; remove

24. Create `public/teams/delete.php` — owner check; confirm; cascade delete

---

## Phase 4: Invitations (US-4)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
25. Create `src/InvitationRepository.php`
    - [ ] `createInvitation` (upsert: delete old pending invite for same email+team, insert new)
    - [ ] `findByToken`, `markAccepted`, `isPendingForEmail`

26. Create `templates/email/invitation.php`
    - [ ] Plain-text body; `$subject` variable; all template variables per US-4 spec

27. Create `public/invitations/send.php`
    - [ ] Owner check; duplicate member check; role checkbox selection; `bin2hex(random_bytes(32))` token; send email via `Mailer::send()`; redirect with flash

28. Create `public/invitations/accept.php`
    - [ ] Load token → check `accepted_at` → check 7-day expiry
    - [ ] Registered path: if logged in → add to team; else redirect to login with `?redirect=...`
    - [ ] Unregistered path: redirect to `/register.php?email=...&invite=<token>`
    - [ ] After registration/login: detect `invite` session var or GET param → auto-accept

---

## Phase 5: Daily Standup Emails (US-5)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
29. Create `src/Mailer.php`
    - [ ] `send(array $config, string $to, string $toName, string $subject, string $body): void`
    - [ ] Raw socket: `stream_socket_client` → EHLO → MAIL FROM → RCPT TO → DATA → body → QUIT
    - [ ] `smtpRead(resource $socket): string` — read response line(s)
    - [ ] `smtpAssert(string $response, string $expected): void` — check response code prefix; throw on unexpected

30. Create `src/StandupEmailer.php`
    - [ ] `getDueTeams(PDO $pdo): array` — SELECT all teams
    - [ ] `isTeamDue(array $team, DateTimeImmutable $nowUtc): bool` — timezone math (< 60s diff)
    - [ ] `getTokensToSend(PDO $pdo, int $teamId, string $sendDate): array` — `is_developer` members minus already-sent
    - [ ] `createToken(PDO $pdo, array $member, array $team, DateTimeImmutable $nowUtc, string $sendDate): string`
    - [ ] `sendPromptEmail(array $config, array $member, array $team, string $token, array $questions): void`

31. Create `templates/email/standup_prompt.php`
    - [ ] `$subject` variable; questions listed as numbered plain-text items; `$standup_url` as clickable link

32. Create `cron/send_standups.php`
    - [ ] CLI guard: `if (php_sapi_name() !== 'cli') exit(1);`
    - [ ] Bootstrap: `require '../src/Db.php'` etc.; load config
    - [ ] **Prompt pass**: iterate teams → `isTeamDue()` → for each due team → get members needing token → create token → send email → catch/log SMTP errors
    - [ ] Log errors via `logError()` function

---

## Phase 6: Standup Submission (US-6)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
33. Create `src/SubmissionRepository.php`
    - [ ] `findToken(PDO, string $token): ?array`
    - [ ] `createSubmission(PDO, int $tokenId, int $userId, int $teamId, array $answers): int` — transactional
    - [ ] `getSubmissionWithAnswers(PDO, int $tokenId): ?array`
    - [ ] `markTokenUsed(PDO, int $tokenId): void`

34. Create `public/submit.php`
    - [ ] GET: token validation sequence (per US-6 spec) → render form with questions
    - [ ] POST: validate CSRF → re-validate token → call `createSubmission()` in transaction → PRG redirect
    - [ ] Already-submitted path: render read-only view of answers
    - [ ] Error paths: expired, invalid — distinct user-friendly messages
    - [ ] No `requireLogin()` — token is the authenticator

---

## Phase 7: Dashboard (US-7)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
35. Create `src/DashboardRepository.php`
    - [ ] `getTeamGridData(PDO, int $teamId, array $days): array` — member × day matrix
    - [ ] `getParticipationStats(PDO, int $teamId, string $date7ago, string $date30ago, string $today): array`
    - [ ] `getUserTeams(PDO, int $userId): array` — all teams with any role

36. Create `public/dashboard.php`
    - [ ] `requireLogin()`; list all teams (any role); link to per-team dashboard and org management

37. Create `public/teams/dashboard.php`
    - [ ] `requireLogin()`; load team; membership check (403 if not member)
    - [ ] Owner path: full grid (all is_developer members × 7 days) + participation stats table
    - [ ] Member path: own row only
    - [ ] Render cell states: ✓ (green) / ✗ (red) / N/A (grey) per US-7 spec
    - [ ] Date range in team timezone

---

## Phase 8: Summary Email (US-8)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
38. Create `src/SummaryEmailer.php`
    - [ ] `isSummaryDue(array $team, DateTimeImmutable $nowUtc): bool` — standup_time + 1h in team tz
    - [ ] `tryInsertSummaryDedup(PDO, int $teamId, string $sendDate): bool` — INSERT IGNORE; return false if already inserted
    - [ ] `assembleSummaryData(PDO, int $teamId, string $sendDate, array $questions): array` — returns `$submissions` + `$non_submitters`
    - [ ] `sendSummaryEmail(array $config, string $recipientEmail, string $recipientName, array $templateVars): void`

39. Create `templates/email/standup_summary.php`
    - [ ] `$subject` variable; grouped per developer; non-submitters section; plain-text

40. Modify `cron/send_standups.php` — add summary pass
    - [ ] After prompt pass: iterate all teams again (or reuse same team list) → `isSummaryDue()` → `tryInsertSummaryDedup()` → `assembleSummaryData()` → load `team_recipients` → send per recipient → log SMTP errors

---

## Phase 9: Polish & Security Hardening
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
41. Shared layout
    - [ ] `templates/layout/header.php` — nav (dashboard, orgs, profile, logout); placeholder logo from `$config['app']['logo_url']`
    - [ ] `templates/layout/footer.php`
    - [ ] All public pages include header/footer

42. Flash messages
    - [ ] `src/Flash.php` — `set(string $key, string $msg)`, `get(string $key): ?string` via `$_SESSION`
    - [ ] Used on all redirect-after-POST flows

43. Input validation helper
    - [ ] `src/Validate.php` — `email(string): bool`, `minLength(string, int): bool`, `timezone(string): bool`, `time(string): bool` (HH:MM)

44. XSS audit
    - [ ] Review all `echo` / `?>...<?php` output; ensure all user-supplied data goes through `htmlspecialchars(ENT_QUOTES, 'UTF-8')`

45. Error page
    - [ ] `public/error.php` — generic 403/404/500 handler; suppress PHP error output to browser (`display_errors = Off` in README note)

46. Verify `.gitignore`
    - [ ] `config/config.php`, `logs/*.log`, `*.db`

---

## Recommended Implementation Order

```
Phase 0 (scaffold + schema) →
Phase 1 (registration/auth) →
Phase 2 (organisations) →
Phase 3 (teams) →
Phase 4 (invitations) →
Phase 5 (standup emails + Mailer) →
Phase 6 (submission) →
Phase 7 (dashboard) →
Phase 8 (summary email) →
Phase 9 (polish) →
Phase 10 (PHPUnit tests) →
Phase 11 (password reset)
```

---

## Phase 10: PHPUnit PHAR Test Suite (US-9)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
47. Obtain PHPUnit PHAR
    - [ ] Download: `wget https://phar.phpunit.de/phpunit-10.phar -O tests/phpunit.phar`
    - [ ] Add to `.gitignore` OR commit as binary (< 5 MB) — document choice in README
    - [ ] Verify: `php tests/phpunit.phar --version` outputs `PHPUnit 10.x.x`

48. Create `tests/schema-sqlite.sql`
    - [ ] Copy `schema.sql`; strip: `ENGINE=InnoDB`, `DEFAULT CHARSET=utf8mb4`, `UNSIGNED`, `DEFAULT (UTC_TIMESTAMP())`
    - [ ] Replace `AUTO_INCREMENT` → `AUTOINCREMENT` (INTEGER PK only); `TINYINT(1)` → `INTEGER`
    - [ ] Add `PRAGMA foreign_keys = ON;` at top
    - [ ] Test: `sqlite3 :memory: < tests/schema-sqlite.sql` — no errors

49. Create `tests/bootstrap.php`
    - [ ] `require_once` all tested source files: `StandupEmailer.php`, `SummaryEmailer.php`, `InvitationRepository.php`, `OrgRepository.php`, `SubmissionRepository.php`
    - [ ] Define `createTestPdo(): PDO` — creates SQLite `:memory:`, enables FK pragma, runs `schema-sqlite.sql`

50. Refactor `src/InvitationRepository.php` ⚠️ **Path B — legacy risk**
    - [ ] Extract `$now` as optional parameter: `function acceptInvitationForUser(PDO $pdo, string $token, int $userId, ?DateTimeImmutable $now = null): bool`
    - [ ] Add `$now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));` as first line of function body
    - [ ] Verify: all existing call sites pass (no arguments changed)

51. Create `tests/phpunit.xml`
    - [ ] Bootstrap: `bootstrap.php`; testsuite directory: `.`; `colors=true`; `failOnWarning=true`

52. Create `tests/StandupEmailerTest.php`
    - [ ] `isTeamDue()` — 6 cases: exact match, 1s before window, 59s before (in window), 59s after (in window), 60s after (outside), different timezone (`America/New_York`, standup `09:00`, nowUtc = winter EST = UTC+5h offset)
    - [ ] Assert `true` for in-window cases; `false` for outside cases

53. Create `tests/SummaryEmailerTest.php`
    - [ ] `isSummaryDue()` — same 6 cases with `nowUtc` shifted +1 hour relative to `isTeamDue()` cases
    - [ ] All expected results identical to `isTeamDue()` equivalents

54. Create `tests/RepositoryTest.php`
    - [ ] `saveSubmission()` happy path: seed team + user + 2 questions + token; call; assert 1 submission row, 2 answer rows, `used_at` not null
    - [ ] `saveSubmission()` rollback test: subclass or modify to throw after submission INSERT; assert 0 answer rows (transaction rolled back)
    - [ ] `assembleSummaryData()`: seed 2 developers, 2 questions, 1 submission + answers for developer A; call; assert A in `submissions` with correct answers, B in `non_submitters`
    - [ ] `acceptInvitationForUser()` valid path: seed invitation (`created_at` = now); call with default `$now`; assert returns `true`; `team_members` row exists; `accepted_at` set
    - [ ] `acceptInvitationForUser()` expired path: seed invitation (`created_at` = 8 days ago); call with `$now` = now; assert returns `false`; no `team_members` row
    - [ ] `acceptInvitationForUser()` already-accepted path: seed invitation with `accepted_at` set; assert returns `false`
    - [ ] `deleteOrg()` cascade: seed full hierarchy (org → team → member → question → token → submission → answer + summary_sent + recipient + invitation + org_member); call `deleteOrg()`; assert 0 rows in each table; no PDO exception

55. Run full test suite and confirm clean pass
    - [ ] `php tests/phpunit.phar --configuration tests/phpunit.xml` exits 0
    - [ ] All 16 assertions pass
    - [ ] Update README with run command

---

## Phase 11: Password Reset Flow (US-10)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
56. Update `schema.sql` — add `password_resets` table ⚠️ **Path B — additive**
    - [ ] Add `CREATE TABLE IF NOT EXISTS password_resets (...)` per US-10 STORY.md schema
    - [ ] Also add to `tests/schema-sqlite.sql` (strip MySQL-specific syntax)

57. Add password reset functions to `src/Auth.php` ⚠️ **Path B — additive (new functions only)**
    - [ ] `createPasswordResetToken(PDO $pdo, int $userId): string`
    - [ ] `findValidResetToken(PDO $pdo, string $token): ?array`
    - [ ] `applyPasswordReset(PDO $pdo, int $tokenId, int $userId, string $newPassword): void` — transactional

58. Create `templates/email/password_reset.php`
    - [ ] `$subject` variable
    - [ ] Plain-text body: greeting using `$user_name`; `$reset_url` as link; expiry note (`$expires_minutes` = 60); security note ("If you did not request this, ignore this email")

59. Create `public/forgot-password.php`
    - [ ] GET: render form (email input, CSRF token)
    - [ ] POST: validate CSRF → sanitise email → `SELECT` user by email → if found: `createPasswordResetToken()` + load template + `Mailer::send()`; if not found: do nothing
    - [ ] Always set flash "If your email is registered, you will receive a reset link"
    - [ ] PRG redirect to `/forgot-password.php`

60. Create `public/reset-password.php`
    - [ ] GET: load token → validate (not found → "Invalid link"; `used_at` set → "already used"; expired → "has expired") → render form (new password, confirm, hidden token, CSRF)
    - [ ] POST: validate CSRF → re-load + re-validate token → validate password ≥ 8 chars + confirm match → `applyPasswordReset()` → flash "Password updated" → redirect `/login.php`
    - [ ] On validation error: re-render form with token preserved in hidden field; token NOT consumed

61. Commit all changes
    - [ ] `git add schema.sql tests/schema-sqlite.sql src/Auth.php src/InvitationRepository.php public/forgot-password.php public/reset-password.php templates/email/password_reset.php tests/`
    - [ ] `git commit -m "feat(us-9,us-10): PHPUnit PHAR test suite and password reset flow"`

## Technical Notes

- **CSRF**: all POST forms — hidden `csrf_token` field; `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])` — return 403 on mismatch
- **PDO**: `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION`; no string interpolation in queries
- **Timezone arithmetic**: always use `DateTimeImmutable`; store/read all MySQL datetimes as UTC; convert for display and scheduling in team timezone
- **Template rendering**: `ob_start()` + `extract($vars, EXTR_SKIP)` + `include` + `ob_get_clean()` — do not use `$$var` patterns
- **SMTP socket**: timeout 10s; log to `logs/standup-errors.log` on any `stream_socket_client` failure or unexpected SMTP response
- **No autoloading**: all `src/*.php` files `require_once`'d explicitly at top of each page script
- **PRG pattern**: all POST handlers redirect after write (prevents form resubmission on refresh)

---

## Phase 12: Standup Response Browser (US-12)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
62. Add query functions to `src/DashboardRepository.php` ⚠️ **Path B — additive**
    - [ ] `getResponseData(PDO, int $teamId, ?string $date, ?int $memberId, string $dateFrom, string $dateTo): array` — core LEFT JOIN query with conditional WHERE; returns flat row array
    - [ ] `getTeamDevelopers(PDO, int $teamId): array` — reuse if already in `TeamRepository.php`; otherwise add here
    - [ ] `getTeamQuestions(PDO, int $teamId): array` — reuse if already in `TeamRepository.php`; otherwise add here

63. Create `public/teams/responses.php`
    - [ ] `requireLogin()` + `(int)$_GET['team_id']` + `isTeamOwner()` → `forbid()` if not owner (first checks before any data load)
    - [ ] Load team, questions (`ORDER BY position ASC`), is_developer members
    - [ ] Parse + validate `?date` (format check via `DateTimeImmutable::createFromFormat('Y-m-d', ...)`) and `?member_id` (must be developer member of team)
    - [ ] Route to one of 4 views: `single` / `by_date` / `by_member` / `default` based on which filters are set
    - [ ] Compute date window in team timezone: default = last 7 days (`-6 days` to today); member = last 30 days (`-29 days` to today)
    - [ ] Call `getResponseData()` with appropriate params
    - [ ] Assemble nested `$data[$send_date][$user_id]` structure from flat rows
    - [ ] Cross-reference against `$members`: add `no_token = true` rows for members with no token that day; add `submitted = false` rows for members with token but no submission
    - [ ] Render: filter form (date input, member select, Apply, Clear link); sections per day (newest first); per-member answer list or "No response" / "No email sent" labels
    - [ ] All answer text via `htmlspecialchars(ENT_QUOTES, 'UTF-8')`; all IDs cast to `(int)` before use in SQL

64. Add "View Responses" link to `public/teams/dashboard.php` ⚠️ **Path B — additive**
    - [ ] Inside the owner-only rendering path, add link: `<a href="/teams/responses.php?team_id=<?= (int)$teamId ?>">View Responses</a>`
    - [ ] Verify link absent in non-owner (member) path

65. Manual verification
    - [ ] Default view (no filter): 7-day grid with answers renders correctly
    - [ ] Date filter: single-day view shows all members
    - [ ] Member filter: 30-day history for one member
    - [ ] Combined filter: single member + single day
    - [ ] Non-owner visits responses.php → 403
    - [ ] Invalid date format → error message shown; no crash
    - [ ] Commit: `git commit -m "feat(us-12): standup response browser"`

---

## Phase 13: Navigation Improvements (US-13)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
66. Add/verify `getTeamById()` and `getOrgById()` helpers
    - [ ] Check `TeamRepository.php` and `OrgRepository.php` for existing implementations
    - [ ] Add if absent: `getTeamById(PDO, int $teamId): ?array` and `getOrgById(PDO, int $orgId): ?array`

67. Add `h()` helper to shared location
    - [ ] Define `function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }` in `src/View.php` (if not already present)
    - [ ] Verify not redefined elsewhere; remove duplicates

68. Create `templates/team-nav.php`
    - [ ] Accepts variables: `$currentPage`, `$teamId`, `$orgId`, `$teamName`, `$orgName`, `$isOwner`
    - [ ] Renders: breadcrumb (Organisations → Org Name → Team Name) + `<ul class="team-nav-links">` with conditional owner links
    - [ ] Active page: `class="active"` on `<li>` matching `$currentPage`
    - [ ] Uses `h()` for all output; all URLs with `(int)` IDs
    - [ ] No access control logic — pure rendering

69. Update `public/assets/style.css` ⚠️ **Path B — additive**
    - [ ] Create file if absent; add `<link>` in `templates/layout/header.php` if not already present
    - [ ] Add `.breadcrumb`, `.team-nav`, `.team-nav-links`, `.team-nav-links li.active a`, `.back-link` CSS rules per US-13 STORY.md

70. Include `team-nav.php` in all 7 team pages ⚠️ **Path B — additive**
    - [ ] `teams/edit.php` — set `$currentPage = 'edit'`; load `$team`, `$org`, `$isOwner`; include team-nav
    - [ ] `teams/members.php` — set `$currentPage = 'members'`; include team-nav
    - [ ] `teams/questions.php` — set `$currentPage = 'questions'`; include team-nav
    - [ ] `teams/recipients.php` — set `$currentPage = 'recipients'`; include team-nav
    - [ ] `teams/dashboard.php` — set `$currentPage = 'dashboard'`; include team-nav
    - [ ] `teams/responses.php` — set `$currentPage = 'responses'`; include team-nav
    - [ ] Verify `$teamId`, `$team['org_id']`, `$teamName`, `$orgName`, `$isOwner` all available before include on each page

71. Add "View responses" links to `public/teams/members.php` ⚠️ **Path B — additive**
    - [ ] In member list render loop, for `is_developer = 1` members, add `<a href="/teams/responses.php?team_id=...&member_id=...">View responses</a>` inside `<?php if ($isOwner): ?>` conditional

72. Add per-team action links to `public/teams/index.php` ⚠️ **Path B — additive**
    - [ ] For each team in list: always show Dashboard link; additionally show Members / Questions / Recipients / Settings / Responses links inside `if ($t['is_owner'])` block
    - [ ] Verify `$t['is_owner']` present in the team list query (JOIN with `team_members` for current user)

73. Add back link to org edit and delete pages ⚠️ **Path B — additive**
    - [ ] `public/orgs/edit.php`: add `<p class="back-link"><a href="/orgs/index.php">&larr; Back to organisations</a></p>` before form
    - [ ] `public/orgs/delete.php`: same back link

74. Manual verification
    - [ ] Visit each of the 7 team pages as owner — team nav visible; correct link highlighted as active
    - [ ] Visit `teams/members.php` as owner — developer members have "View responses" links; non-developer members do not
    - [ ] Visit `teams/members.php` as non-owner — no "View responses" links
    - [ ] Visit `teams/index.php` as owner — all action links visible; as member — only Dashboard shown
    - [ ] Visit `orgs/edit.php` and `orgs/delete.php` — back link visible and working
    - [ ] Commit: `git commit -m "feat(us-13): navigation improvements and team nav bar"`

---

## Phase 14: Bug Fixes and Improvements (US-14)
**Agent:** PHP Developer (ID: `fa2e6dbf-d174-4a61-b2cc-710cc0a94a6e`)

### Tasks
75. Fix Bug 4 first — double config load in `cron/send_standups.php` ⚠️ **Path B**
    - [ ] Locate orphan `require_once __DIR__ . '/../config/config.php'` line
    - [ ] Delete it; retain only `$config = require __DIR__ . '/../config/config.php'`
    - [ ] Verify cron still loads `$config` correctly (echo a config key in a test run)

76. Fix Bug 1 — summary email recipients in `src/SummaryEmailer.php` ⚠️ **Path B**
    - [ ] Add `queryMemberRecipients(PDO $pdo, int $teamId): array` function — SELECT email + display_name for `is_recipient = 1` members via JOIN with `users`
    - [ ] Add `getMergedRecipients(PDO $pdo, int $teamId): array` function — merges external + member lists; dedup by `strtolower(trim($email))`
    - [ ] Replace existing single-source recipient query call with `getMergedRecipients()`
    - [ ] Verify: seed a team member with `is_recipient = 1`; confirm they receive summary; seed same email in `team_recipients`; confirm only one email sent

77. Implement Feature 3 — weekend skip in `cron/send_standups.php` ⚠️ **Path B**
    - [ ] Inside team loop, after computing `$nowLocal`, add: `$dayOfWeek = (int) $nowLocal->format('N'); if ($dayOfWeek === 6 || $dayOfWeek === 7) { continue; }`
    - [ ] Ensure check appears before any prompt or summary logic for the team
    - [ ] Verify: set system clock to a Saturday (or mock date in test); confirm no tokens created, no emails sent

78. Implement Feature 2 — org+team context on submission form
    - [ ] **`public/submit.php`** ⚠️ **Path B**: after loading `$team`, call `getOrgById($pdo, $team['org_id'])` (add function to `OrgRepository.php` if absent); render `<div class="standup-context">` with org name + team name above questions; apply to form view, already-submitted view, and error views where team data is available
    - [ ] **`src/StandupEmailer.php`** ⚠️ **Path B**: add `$org = getOrgById($pdo, $team['org_id'])` before building template vars; add `'org_name' => $org['name'] ?? ''` to vars array
    - [ ] **`templates/email/standup_prompt.php`** ⚠️ **Path B**: update `$subject` to `"[{$org_name}] {$team_name} — Daily Standup for {$send_date}"`; add org/team line to body
    - [ ] Verify: send a test prompt email; confirm org and team names appear in subject + body
    - [ ] Verify: visit a submission link in browser; confirm org + team name visible above the form

79. Commit
    - [ ] `git add src/SummaryEmailer.php src/StandupEmailer.php public/submit.php templates/email/standup_prompt.php cron/send_standups.php`
    - [ ] `git commit -m "fix(us-14): summary recipients, org/team context, weekend skip, double config load"`
