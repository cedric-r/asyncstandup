# US-2: Organisations

**Feature**: asyncstandup-core  
**Story**: US-2

## User Story

**As a** logged-in user  
**I can** create, edit, and delete organisations  
**So that** I can group my teams under a shared organisational context

## Acceptance Criteria

1. **Given** logged-in user, **When** organisation created with a name, **Then** saved; user automatically added to `org_members`
2. **Given** organisation member, **When** organisation list page visited, **Then** only orgs the user belongs to are shown
3. **Given** organisation creator, **When** org name edited and saved, **Then** name updated in DB
4. **Given** organisation creator, **When** org deleted and confirmed, **Then** org record, all `org_members` rows, all teams and their cascaded data (members, questions, tokens, submissions, answers, recipients, summary records) deleted; user accounts kept
5. **Given** non-member user, **When** org edit/delete URL visited directly, **Then** 403 Forbidden
6. **Given** any form, **When** submitted, **Then** CSRF token validated

## Definition of Done

- [ ] All ACs met
- [ ] Cascade delete handled in PHP (not DB cascade) — explicit DELETE in correct order to respect FK constraints
- [ ] Membership check on all edit/delete operations
- [ ] PDO parameterised queries only

## Files

| Action | File |
|---|---|
| Create | `public/orgs/index.php` — list orgs |
| Create | `public/orgs/create.php` — create form + handler |
| Create | `public/orgs/edit.php` — edit form + handler |
| Create | `public/orgs/delete.php` — confirm + handler |
| Create | `src/OrgRepository.php` |

## Implementation Details

### OrgRepository key methods

```php
function getOrgsForUser(PDO $pdo, int $userId): array
function createOrg(PDO $pdo, string $name, int $userId): int  // returns new org id
function updateOrg(PDO $pdo, int $orgId, string $name): void
function deleteOrg(PDO $pdo, int $orgId): void  // cascades in PHP
function isMember(PDO $pdo, int $orgId, int $userId): bool
```

### Cascade delete order (PHP-controlled)

```
1. standup_answers (via submission → token → team)
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

### Schema fragments

```sql
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
```
