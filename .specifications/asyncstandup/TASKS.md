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
Phase 9 (polish)
```

## Technical Notes

- **CSRF**: all POST forms — hidden `csrf_token` field; `hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])` — return 403 on mismatch
- **PDO**: `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION`; no string interpolation in queries
- **Timezone arithmetic**: always use `DateTimeImmutable`; store/read all MySQL datetimes as UTC; convert for display and scheduling in team timezone
- **Template rendering**: `ob_start()` + `extract($vars, EXTR_SKIP)` + `include` + `ob_get_clean()` — do not use `$$var` patterns
- **SMTP socket**: timeout 10s; log to `logs/standup-errors.log` on any `stream_socket_client` failure or unexpected SMTP response
- **No autoloading**: all `src/*.php` files `require_once`'d explicitly at top of each page script
- **PRG pattern**: all POST handlers redirect after write (prevents form resubmission on refresh)
