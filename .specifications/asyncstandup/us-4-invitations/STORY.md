# US-4: Invitations

**Feature**: asyncstandup-core  
**Story**: US-4

## User Story

**As a** team owner  
**I can** invite users to my team by email with a role assignment  
**So that** they receive an invitation email and join with the correct role(s)

## Acceptance Criteria

1. **Given** team owner and a valid email, **When** invitation submitted with role selection, **Then** invitation record saved; SMTP email sent with unique accept link; intended roles stored as comma-separated string
2. **Given** invitee not yet registered, **When** accept link clicked, **Then** directed to registration with email pre-filled; on completion, added to `team_members` with intended roles
3. **Given** invitee already registered, **When** accept link clicked, **Then** added to `team_members` directly with intended roles; no re-registration required
4. **Given** invitation link older than 7 days, **When** clicked, **Then** error "Invitation expired"; no join occurs
5. **Given** user already a member of the team, **When** invited again, **Then** invitation not sent; owner sees "User is already a member" message
6. **Given** pending invitation already exists for same email + team, **When** owner invites again, **Then** old invitation replaced with new one (token regenerated); new email sent
7. **Given** accept link already used (`accepted_at` is not null), **When** visited again, **Then** shows "Invitation already accepted"
8. **Given** any form, **When** submitted, **Then** CSRF token validated

## Definition of Done

- [ ] All ACs met
- [ ] Token: `bin2hex(random_bytes(32))` — 64 hex chars; UNIQUE in DB
- [ ] Invitation email sent via raw socket SMTP (reusing shared mailer function)
- [ ] `intended_roles` applied atomically on accept (INSERT into `team_members` with correct flags)
- [ ] Expiry: `created_at + 7 days` checked at accept time — no expiry column needed (derived)

## Files

| Action | File |
|---|---|
| Create | `public/invitations/send.php` — form + handler |
| Create | `public/invitations/accept.php` — accept link handler |
| Create | `src/InvitationRepository.php` |
| Create | `templates/email/invitation.php` |

## Implementation Details

### Invitation token generation

```php
$token = bin2hex(random_bytes(32));  // 64-char hex
```

### Role storage in `invitations.intended_roles`

Stored as comma-separated string: `'developer,recipient'`. Parsed on accept:

```php
$roles = explode(',', $row['intended_roles']);
$isOwner     = in_array('owner', $roles) ? 1 : 0;
$isDeveloper = in_array('developer', $roles) ? 1 : 0;
$isRecipient = in_array('recipient', $roles) ? 1 : 0;
```

### Accept flow

```
1. Load invitation by token → 404 if not found
2. Check accepted_at → show "already accepted" if set
3. Check created_at + 7 days vs now → show "expired" if past
4. Check if user_id matching email exists in users table
5a. Not registered → redirect to /register.php?email=...&invite=<token>
    On registration completion: auto-accept (add to team_members, set accepted_at)
5b. Registered → if not logged in, redirect to login with ?redirect=accept&token=<token>
    On login: auto-accept
    If already logged in: accept immediately
6. INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient, joined_at)
   ON DUPLICATE KEY UPDATE is_owner=VALUES(is_owner), ...  (handles edge case of existing membership)
7. UPDATE invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = ?
8. Redirect to dashboard with "You have joined the team!" message
```

### Email template (`templates/email/invitation.php`)

Variables available after `extract()`:
- `$team_name` — team name
- `$org_name` — organisation name
- `$inviter_name` — display name of inviting owner
- `$accept_url` — full URL with token
- `$expires_days` — `7`
- `$roles` — human-readable roles string e.g. "Developer, Recipient"

### Schema fragment

```sql
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
```
