# US-3: Teams

**Feature**: asyncstandup-core  
**Story**: US-3

## User Story

**As a** logged-in organisation member  
**I can** create, edit, and delete teams; manage member roles, custom standup questions, and external summary recipients  
**So that** each team's daily standup workflow is fully configured

## Acceptance Criteria

1. **Given** logged-in org member, **When** team created with name, timezone, and standup time, **Then** team saved; creator added as `is_owner=1`, `is_developer=1`; 3 default questions inserted
2. **Given** team owner, **When** team settings (name, timezone, standup_time) edited, **Then** updated; next cron run uses new values
3. **Given** team owner, **When** member role flags toggled (`is_owner`, `is_developer`, `is_recipient`), **Then** `team_members` row updated
4. **Given** team owner, **When** member removed from team, **Then** `team_members` row deleted; their future tokens will not be generated (past submissions kept)
5. **Given** team owner, **When** question added, **Then** inserted at end of position order
6. **Given** team owner, **When** question text edited, **Then** `team_questions.question` updated
7. **Given** team owner, **When** question deleted, **Then** row removed; positions of remaining questions renumbered
8. **Given** team owner, **When** question reordered (up/down), **Then** `position` values swapped
9. **Given** team owner, **When** external recipient email added, **Then** inserted into `team_recipients`; duplicate email for same team rejected
10. **Given** team owner, **When** external recipient removed, **Then** deleted from `team_recipients`
11. **Given** team owner, **When** team deleted, **Then** full cascade delete: answers, submissions, tokens, summary_sent, recipients, questions, invitations, members, team record
12. **Given** non-owner, **When** any team edit URL visited, **Then** 403
13. **Given** any form, **When** submitted, **Then** CSRF token validated

## Definition of Done

- [ ] All ACs met
- [ ] Default questions inserted in a transaction with team creation
- [ ] Cascade delete handled in PHP in FK-safe order
- [ ] `standup_time` validated as HH:MM format; timezone validated against `DateTimeZone::listIdentifiers()`
- [ ] Ownership check on all mutating operations

## Files

| Action | File |
|---|---|
| Create | `public/teams/index.php` — list teams |
| Create | `public/teams/create.php` |
| Create | `public/teams/edit.php` — settings tab |
| Create | `public/teams/members.php` — member role management |
| Create | `public/teams/questions.php` — question management |
| Create | `public/teams/recipients.php` — external recipient management |
| Create | `public/teams/delete.php` |
| Create | `src/TeamRepository.php` |

## Implementation Details

### Default questions (inserted on team creation)

```php
$defaults = [
    'What did you do yesterday?',
    'What will you do today?',
    'Any blockers?',
];
// Inserted with position = 1, 2, 3 in a transaction
```

### Cascade delete order (PHP-controlled)

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

### Schema fragments

```sql
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

CREATE TABLE IF NOT EXISTS team_questions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id    INT UNSIGNED NOT NULL,
    question   VARCHAR(500) NOT NULL,
    position   INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Timezone validation

```php
$valid = in_array($timezone, DateTimeZone::listIdentifiers(), true);
```

### standup_time storage

Stored as `TIME` in MySQL (HH:MM:SS). Cron compares current UTC time against team's configured time converted to UTC for that day.
