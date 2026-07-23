# US-7: Dashboard

**Feature**: asyncstandup-core  
**Story**: US-7

## User Story

**As a** team owner or member  
**I can** view a participation dashboard for my team  
**So that** I can see standup submission status and track participation trends

## Acceptance Criteria

1. **Given** logged-in team owner, **When** team dashboard viewed, **Then** table shows all members × last 7 calendar days (in team timezone) with ✓ (submitted), ✗ (not submitted), or N/A (no token sent that day) per cell
2. **Given** logged-in team owner, **When** dashboard viewed, **Then** each member row shows 7-day participation % and 30-day participation % (of days a token was sent)
3. **Given** logged-in team member (non-owner), **When** dashboard URL for a team visited, **Then** sees only own row (own 7-day history); other members' rows hidden
4. **Given** logged-in user who is neither owner nor member of the requested team, **When** dashboard URL visited, **Then** 403 Forbidden
5. **Given** unauthenticated user, **When** dashboard visited, **Then** redirected to login
6. **Given** a day where no token was sent for a member (e.g. team did not exist yet), **When** displayed, **Then** cell shows N/A (grey)

## Definition of Done

- [ ] All ACs met
- [ ] Date range calculated in team timezone (not server timezone)
- [ ] "Days sent" per member derived from `standup_tokens` rows (not calendar days) — participation % = submitted / sent
- [ ] Query uses `standup_tokens LEFT JOIN standup_submissions` — avoids counting unsent days as missed
- [ ] `requireLogin()` enforced; team membership check before rendering
- [ ] `htmlspecialchars` on all member names and team names

## Files

| Action | File |
|---|---|
| Create | `public/dashboard.php` — main landing page (list of teams) |
| Create | `public/teams/dashboard.php` — per-team dashboard |
| Create | `src/DashboardRepository.php` |

## Implementation Details

### Date range (7 days in team timezone)

```php
$teamTz = new DateTimeZone($team['timezone']);
$today  = new DateTimeImmutable('today', $teamTz);
$days   = [];
for ($i = 6; $i >= 0; $i--) {
    $days[] = $today->modify("-{$i} days")->format('Y-m-d');
}
// $days = ['2024-01-09', '2024-01-10', ..., '2024-01-15']
```

### Data query (owner view)

```sql
SELECT
    u.id AS user_id,
    u.display_name,
    t.send_date,
    t.id AS token_id,
    s.id AS submission_id
FROM team_members tm
JOIN users u ON u.id = tm.user_id
LEFT JOIN standup_tokens t ON t.user_id = tm.user_id
    AND t.team_id = :team_id
    AND t.send_date BETWEEN :date_from AND :date_to
LEFT JOIN standup_submissions s ON s.token_id = t.id
WHERE tm.team_id = :team_id
  AND tm.is_developer = 1
ORDER BY u.display_name, t.send_date
```

Assemble into grid: `$grid[$user_id][$date] = 'submitted'|'sent_not_submitted'|'not_sent'`.

### Cell rendering

| State | Display | CSS class |
|---|---|---|
| `submitted` | ✓ | `cell-submitted` (green) |
| `sent_not_submitted` | ✗ | `cell-missed` (red) |
| `not_sent` | N/A | `cell-na` (grey) |

### Participation % query (30-day, per member)

```sql
SELECT
    t.user_id,
    COUNT(t.id)           AS sent_count,
    COUNT(s.id)           AS submitted_count
FROM standup_tokens t
LEFT JOIN standup_submissions s ON s.token_id = t.id
WHERE t.team_id = :team_id
  AND t.send_date BETWEEN :date_30_ago AND :today
GROUP BY t.user_id
```

`pct = submitted_count / sent_count * 100` (handle divide-by-zero: show "—" if sent_count = 0).

### Member (non-owner) view

Same query but filtered: `AND t.user_id = :current_user_id` + restrict rendered rows to current user only.

### Main dashboard page (`public/dashboard.php`)

Lists all teams the logged-in user belongs to (any role). Links to per-team dashboard. Shows user's display name and a "My Organisations" section linking to org management.
