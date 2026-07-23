# AsyncStandUp

**Feature**: asyncstandup-core | **Branch**: feature/asyncstandup-core

## Problem

Distributed development teams have no lightweight, async-native way to run daily standups. Calendar-based standups force synchronous attendance; Slack bots require third-party dependencies. Teams need a self-hosted, email-driven standup system.

## Business Value

AsyncStandUp lets distributed teams replace synchronous standup meetings with a structured daily email-and-submit loop. Team owners get a clear participation dashboard; recipients (including non-registered stakeholders) receive a daily summary. Zero tooling dependencies beyond a PHP server and MySQL.

## Solution

A vanilla PHP 8.1 web application: daily SMTP emails sent per team schedule via raw socket, unique submission links per member per day, per-team configurable questions, summary email 1 hour after standup time, MySQL persistence, team participation dashboard.

---

## Approved Decisions

| Decision | Value |
|---|---|
| PHP version | 8.1 |
| SMTP implementation | Raw socket via `stream_socket_client` (EHLO / MAIL FROM / DATA sequence) |
| Member dashboard scope | Members see own history only; owners see full team |
| Org deletion | Removes membership records only; user accounts kept |
| Multiple owners | Allowed — a team can have more than one owner |

---

## In-Scope Features

| Area | Features |
|---|---|
| **User accounts** | Registration (email + password), login, logout, profile (display name, timezone) |
| **Organisations** | Create, edit, delete; user membership in one or more orgs |
| **Teams** | Create, edit, delete per org; name, timezone, standup time; member management; role assignment |
| **Roles** | Stackable: `is_owner`, `is_developer`, `is_recipient` (three boolean columns in `team_members`) |
| **External recipients** | Non-registered email addresses added to `team_recipients`; receive summary email only |
| **Per-team questions** | Owners add/edit/delete/reorder questions; defaults pre-populated on team creation |
| **Invitations** | Invite by email with role assignment; SMTP invite email; 7-day expiry token |
| **Standup emails** | Daily prompt to `is_developer` members at team standup time (team timezone) |
| **Standup submission** | Form renders team's custom questions; answers saved per question; 48h token expiry |
| **Summary email** | 1 hour after standup time, cron sends summary to all `team_recipients` (external + flagged members) |
| **Dashboard** | Owners: full team × day grid + participation %; members: own history only |
| **Config** | MySQL, SMTP, app URL in `config/config.php`; email templates as PHP files |

## Out of Scope

- OAuth / SSO / social login
- Password reset (initial version)
- In-app standup editing after submission
- Org-level dashboards (team-level only)
- File uploads or attachments
- Rich text / markdown in standup submissions
- Real-time notifications / WebSocket
- Multi-language / i18n

---

## User Stories

### US-1: Registration & Profile
**As a** new user  
**I can** register with email and password, then update my display name and timezone  
**So that** my standup submissions and email scheduling use my correct identity and local time

**Acceptance Criteria**:
- **Given** valid email + password, **When** registration submitted, **Then** account created; user logged in; redirected to dashboard
- **Given** email already registered, **When** registration attempted, **Then** error shown; no duplicate account
- **Given** logged-in user, **When** profile page visited, **Then** display name and timezone can be updated and saved

### US-2: Organisations
**As a** logged-in user  
**I can** create, edit, and delete organisations by name  
**So that** I can group my teams under a shared organisational context

**Acceptance Criteria**:
- **Given** logged-in user, **When** organisation created, **Then** user is automatically member
- **Given** organisation owner, **When** org name edited, **Then** name updated
- **Given** organisation owner, **When** org deleted, **Then** all teams and membership records deleted; user accounts kept

### US-3: Teams
**As a** logged-in organisation member  
**I can** create, edit, and delete teams; manage members, roles, questions, and summary recipients  
**So that** I can fully configure each team's daily standup workflow

**Acceptance Criteria**:
- **Given** logged-in user with org membership, **When** team created, **Then** user is `is_owner=true`; default 3 questions pre-populated; name, timezone, and standup time set
- **Given** team owner, **When** team settings edited, **Then** name, timezone, and standup time updated; next scheduled email uses new values
- **Given** team owner, **When** member role changed, **Then** `is_owner`, `is_developer`, `is_recipient` flags updated; at least one combination must remain set
- **Given** team owner, **When** question added/edited/deleted/reordered, **Then** change reflected immediately on next submission form
- **Given** team owner, **When** external recipient email added to summary list, **Then** saved to `team_recipients`; receives next summary email
- **Given** team owner, **When** team deleted, **Then** all members, invitations, tokens, submissions, answers, questions, recipients, and summary records deleted

### US-4: Invitations
**As a** team owner  
**I can** invite users to my team by email with a role assignment  
**So that** they receive an invitation email and join with the correct role(s)

**Acceptance Criteria**:
- **Given** team owner and valid email, **When** invitation sent with selected roles, **Then** SMTP email delivered with accept link; invitation record saved with intended roles
- **Given** recipient clicks accept link and is not registered, **When** visited, **Then** directed to registration with email pre-filled; on completion, joined with assigned roles
- **Given** recipient clicks accept link and is already registered, **When** visited, **Then** added to team directly with assigned roles
- **Given** invitation link older than 7 days, **When** clicked, **Then** error "Invitation expired"
- **Given** user already a team member, **When** invited again, **Then** invitation not sent; owner sees info message

### US-5: Daily Standup Emails
**As a** team member with `is_developer = true`  
**I can** receive a daily standup prompt email at my team's configured time  
**So that** I am reminded to submit my standup for the day

**Acceptance Criteria**:
- **Given** team standup time reached in team timezone, **When** cron fires, **Then** one email per `is_developer` member sent with unique submission link; email renders team's custom questions as a preview
- **Given** member already received standup email for today (same `send_date` in team timezone), **When** cron runs again, **Then** no duplicate sent
- **Given** SMTP failure for one member, **When** cron runs, **Then** failure logged to file; other members' emails still attempt

### US-6: Standup Submission
**As a** team member  
**I can** click my unique link and submit answers to my team's standup questions  
**So that** my team can see my daily update

**Acceptance Criteria**:
- **Given** valid unused token, **When** link visited, **Then** form shown with team's custom questions in position order
- **Given** form completed, **When** submitted, **Then** submission saved; one `standup_answers` row per question; token marked used; confirmation page shown
- **Given** token already used, **When** link visited, **Then** shows "Standup already submitted" with submitted answers
- **Given** token older than 48 hours, **When** visited, **Then** shows "Link expired"
- **Given** missing or tampered token, **When** visited, **Then** shows "Invalid link"

### US-7: Dashboard
**As a** team owner or member  
**I can** view a participation dashboard for my team  
**So that** I can see standup submission status and trends

**Acceptance Criteria**:
- **Given** logged-in team owner, **When** team dashboard viewed, **Then** table of members × last 7 days with ✓/✗/N/A per cell; 7-day and 30-day submission % per member
- **Given** logged-in team member (non-owner), **When** dashboard viewed, **Then** sees own submission history only
- **Given** a day with no standup emails sent, **When** displayed, **Then** cells show N/A

### US-8: Summary Email
**As a** team recipient (external or internal)  
**I can** receive a daily standup summary email 1 hour after the team's standup time  
**So that** I stay informed of the team's progress without logging in

**Acceptance Criteria**:
- **Given** 1 hour after team standup time in team timezone, **When** cron fires, **Then** one summary email sent to each `team_recipients` entry (email column)
- **Given** summary email already sent today for this team (row in `summary_sent`), **When** cron runs again, **Then** no duplicate sent
- **Given** summary rendered, **When** sent, **Then** includes: each `is_developer` member's name + answers grouped by question; non-submitters listed as "No response"
- **Given** SMTP failure for one recipient, **When** cron runs, **Then** failure logged; other recipients still attempted
- **Given** zero submissions for the day, **When** summary sent, **Then** all developers listed as "No response"; summary still sent

---

## Data Model

### Tables

```
users
  id            INT PK AUTO_INCREMENT
  email         VARCHAR(255) UNIQUE NOT NULL
  password_hash VARCHAR(255) NOT NULL
  display_name  VARCHAR(100)
  timezone      VARCHAR(50) DEFAULT 'UTC'
  created_at    DATETIME

organisations
  id            INT PK AUTO_INCREMENT
  name          VARCHAR(255) NOT NULL
  created_by    INT FK → users.id
  created_at    DATETIME

org_members
  org_id        INT FK → organisations.id
  user_id       INT FK → users.id
  PRIMARY KEY (org_id, user_id)

teams
  id            INT PK AUTO_INCREMENT
  org_id        INT FK → organisations.id
  name          VARCHAR(255) NOT NULL
  timezone      VARCHAR(50) NOT NULL
  standup_time  TIME NOT NULL             -- HH:MM in team timezone; stored as UTC equivalent in cron
  created_by    INT FK → users.id
  created_at    DATETIME

team_members
  team_id       INT FK → teams.id
  user_id       INT FK → users.id
  is_owner      TINYINT(1) DEFAULT 0
  is_developer  TINYINT(1) DEFAULT 0
  is_recipient  TINYINT(1) DEFAULT 0
  joined_at     DATETIME
  PRIMARY KEY (team_id, user_id)

team_recipients              -- external (non-registered) summary recipients; registered members also here if is_recipient
  id            INT PK AUTO_INCREMENT
  team_id       INT FK → teams.id
  email         VARCHAR(255) NOT NULL
  display_name  VARCHAR(100)
  added_by      INT FK → users.id
  created_at    DATETIME
  UNIQUE KEY (team_id, email)

team_questions
  id            INT PK AUTO_INCREMENT
  team_id       INT FK → teams.id
  question      VARCHAR(500) NOT NULL
  position      INT NOT NULL
  created_at    DATETIME

invitations
  id            INT PK AUTO_INCREMENT
  team_id       INT FK → teams.id
  invited_email VARCHAR(255) NOT NULL
  token         VARCHAR(64) UNIQUE NOT NULL
  invited_by    INT FK → users.id
  intended_roles VARCHAR(50)             -- e.g. 'developer,recipient' — applied on accept
  created_at    DATETIME
  accepted_at   DATETIME NULL

standup_tokens
  id            INT PK AUTO_INCREMENT
  team_id       INT FK → teams.id
  user_id       INT FK → users.id
  token         VARCHAR(64) UNIQUE NOT NULL
  send_date     DATE NOT NULL            -- calendar date in team timezone
  sent_at       DATETIME NOT NULL
  expires_at    DATETIME NOT NULL        -- sent_at + 48 hours
  used_at       DATETIME NULL

standup_submissions
  id            INT PK AUTO_INCREMENT
  token_id      INT FK → standup_tokens.id  UNIQUE
  user_id       INT FK → users.id
  team_id       INT FK → teams.id
  submitted_at  DATETIME NOT NULL

standup_answers
  id            INT PK AUTO_INCREMENT
  submission_id INT FK → standup_submissions.id
  question_id   INT FK → team_questions.id
  answer        TEXT

summary_sent                 -- dedup guard for summary emails
  id            INT PK AUTO_INCREMENT
  team_id       INT FK → teams.id
  send_date     DATE NOT NULL
  sent_at       DATETIME NOT NULL
  UNIQUE KEY (team_id, send_date)
```

### Key Relationships

- User ↔ Team: many-to-many via `team_members` with three role booleans
- External recipients: `team_recipients` (email-only; no FK to users)
- Registered recipients: added to `team_recipients` with their email (duplicate emails resolved by UNIQUE key on `team_id, email`)
- `standup_submissions` → `standup_answers`: one submission → N answers (one per question)
- `summary_sent`: UNIQUE on `(team_id, send_date)` enforces one summary per team per day

### Role Design Rationale

Three boolean columns (`is_owner`, `is_developer`, `is_recipient`) chosen over `SET` or a junction table:
- Simple to query with `WHERE is_developer = 1`
- Easy to update individual flags without string parsing
- Portable across MySQL versions without SET type quirks
- Readable in admin queries

---

## Email Template Strategy

Templates stored as plain PHP files in `templates/email/`. Loaded via `ob_start()` + `extract($vars)` + `include` + `ob_get_clean()`. Plain-text only (no HTML).

| Template file | Trigger | Variables |
|---|---|---|
| `invitation.php` | Team owner invites a user | `$team_name`, `$org_name`, `$inviter_name`, `$accept_url`, `$expires_days`, `$roles` |
| `standup_prompt.php` | Daily cron — developer prompt | `$user_name`, `$team_name`, `$standup_url`, `$send_date`, `$team_timezone`, `$questions` (array of strings) |
| `standup_summary.php` | Daily cron — 1h after standup | `$team_name`, `$send_date`, `$submissions` (array: `[name, answers[]]`), `$non_submitters` (array of names) |

Each template file defines `$subject` (string) and outputs the body via echo/?>text<?php pattern. The mailer reads `$subject` after include.

---

## Standup Link Security

| Property | Value |
|---|---|
| Generation | `bin2hex(random_bytes(32))` — 64-char hex, cryptographically random |
| Storage | `standup_tokens.token` VARCHAR(64), UNIQUE index |
| Expiry | 48 hours from `sent_at` (stored in `expires_at`) |
| Single-use | `used_at` set on first submission; subsequent visits show submitted content |
| Link format | `{base_url}/submit.php?token=<hex>` |

**Invitation tokens**: same generation; 7-day expiry derived at validation time from `created_at`; marked used via `accepted_at`.

---

## Config File

`config/config.php` — not committed with real credentials; `config/config.example.php` committed as reference:

```php
<?php
return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'asyncstandup',
        'user'     => 'root',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],
    'smtp' => [
        'host'     => 'smtp.example.com',
        'port'     => 25,
        'from'     => 'standup@example.com',
        'from_name'=> 'AsyncStandUp',
    ],
    'app' => [
        'base_url' => 'https://app.example.com',
        'app_name' => 'AsyncStandUp',
        'logo_url' => '/assets/logo.png',
    ],
];
```

---

## Infrastructure Constraints

| Concern | Decision |
|---|---|
| Language | Vanilla PHP 8.1 — no framework, no Composer |
| Database | MySQL — PDO with parameterised queries |
| Email | Raw socket `stream_socket_client` — EHLO / MAIL FROM / RCPT TO / DATA sequence |
| Session | PHP native sessions (`session_start()`) |
| Password hashing | `password_hash()` / `password_verify()` (bcrypt) |
| Cron | System cron calls `cron/send_standups.php` every minute; internally checks which teams are due |
| Timezone handling | PHP `DateTimeZone`; MySQL stores all datetimes as UTC |
| Default questions | Pre-inserted on team creation: "What did you do yesterday?", "What will you do today?", "Any blockers?" |
