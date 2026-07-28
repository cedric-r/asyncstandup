# US-18: Pending Standups on the Dashboard Landing Page

**Feature**: asyncstandup-core  
**Story**: US-18  
**Branch**: `feature/asyncstandup-dashboard-pending`

## User Story

**As a** logged-in team member  
**I can** see any unanswered standup prompts for today directly on the dashboard landing page  
**So that** I can submit my standup without needing to find the email

## Acceptance Criteria

1. **Given** logged-in developer has at least one pending token for today, **When** `public/dashboard.php` loads, **Then** a "Pending standups" section appears at the top (above the team list) listing each team with a "Submit standup for [Team Name]" link → `submit.php?token=<token>`
2. **Given** developer has already submitted for a team today (`used_at` is set), **When** dashboard loads, **Then** that team does NOT appear in the pending section
3. **Given** a token is expired (`expires_at < UTC now`), **When** dashboard loads, **Then** that team does NOT appear in the pending section
4. **Given** no valid pending tokens exist for the user today, **When** dashboard loads, **Then** no pending section is rendered (page identical to current layout)
5. **Given** user is a recipient or owner but NOT a developer (`is_developer = 0`) on a team, **When** dashboard loads, **Then** that team does not appear in the pending section

## Definition of Done

- [ ] All ACs met
- [ ] `getPendingTokensForUser(PDO $pdo, int $userId): array` added to `src/DashboardRepository.php`
- [ ] Query uses `datetime()` wrappers for SQLite compatibility
- [ ] Section rendered only when result array is non-empty
- [ ] Token value in link HTML-escaped (`htmlspecialchars`) — tokens are hex strings but good practice
- [ ] No new DB tables; no schema changes
- [ ] Pre-listed for testing: `tests/DashboardRepositoryTest.php` (integration test with in-memory SQLite)

## Files

| Action | File | Risk |
|---|---|---|
| Modify | `public/dashboard.php` | ⚠️ Path B — additive section prepended to existing content |
| Modify | `src/DashboardRepository.php` | ⚠️ Path B — new query function only |

## Implementation Details

### `getPendingTokensForUser(PDO $pdo, int $userId): array`

```php
function getPendingTokensForUser(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT st.token, st.send_date, t.name AS team_name, t.timezone
        FROM standup_tokens st
        JOIN teams t        ON t.id = st.team_id
        JOIN team_members tm ON tm.team_id = st.team_id AND tm.user_id = st.user_id
        WHERE st.user_id = ?
          AND st.used_at IS NULL
          AND datetime(st.expires_at) > datetime('now')
          AND tm.is_developer = 1
        ORDER BY t.name ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

`datetime()` wrappers follow the pattern established in US-6 and US-8 for cross-DB compatibility. For MySQL deployments, `datetime(column)` is a no-op pass-through; for SQLite it ensures correct ISO string comparison.

### `send_date` filter note

The query does NOT filter by `send_date = today` in SQL — it relies on `used_at IS NULL` and `expires_at > now` to surface only actionable tokens. A token issued yesterday (48h window) that was never submitted would still appear, which is intentional: the user can still submit it via the link. If the product decision changes to today-only, add:

```sql
AND date(st.send_date) = date('now')  -- or pass today's date from PHP
```

For this story, the expiry-based filter is sufficient and correct per the US-6 48h token lifetime spec.

### `public/dashboard.php` — pending section

Add immediately after `requireLogin()` and loading `$currentUser`, before the team list query:

```php
require_once __DIR__ . '/../src/DashboardRepository.php';
$pendingTokens = getPendingTokensForUser($pdo, $currentUser['id']);
```

Render above the team list:

```php
<?php if (!empty($pendingTokens)): ?>
<section class="pending-standups">
    <h2>Pending standups</h2>
    <ul>
        <?php foreach ($pendingTokens as $pt): ?>
        <li>
            <a href="/submit.php?token=<?= htmlspecialchars($pt['token'], ENT_QUOTES, 'UTF-8') ?>">
                Submit standup for <?= htmlspecialchars($pt['team_name'], ENT_QUOTES, 'UTF-8') ?>
            </a>
            <small>(<?= htmlspecialchars($pt['send_date'], ENT_QUOTES, 'UTF-8') ?>)</small>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
```

The `send_date` shown as a `<small>` label helps the user identify which day's standup is pending (relevant if a 48h-old token appears).

### CSS (additive to `public/assets/style.css`)

```css
.pending-standups {
    background: #fff8e1;
    border-left: 4px solid #f9a825;
    padding: 12px 16px;
    margin-bottom: 24px;
    border-radius: 4px;
}
.pending-standups h2 { margin-top: 0; font-size: 1.1em; color: #e65100; }
.pending-standups ul { margin: 8px 0 0; padding-left: 20px; }
.pending-standups li { margin-bottom: 6px; }
```

Amber/yellow background distinguishes the section as an action prompt without being alarmist.

### Test hook

`getPendingTokensForUser()` is a pure Dapper-style function (PDO-injectable) — suitable for integration testing with SQLite `:memory:`:

```php
// tests/DashboardRepositoryTest.php (to be created in IMPL-PLAN)
// Seed: user, team, team_member (is_developer=1), standup_token (used_at=NULL, valid expires_at)
// Assert: getPendingTokensForUser() returns 1 row with correct token
// Seed: token with used_at set → assert: 0 rows
// Seed: token with expires_at in past → assert: 0 rows
// Seed: team_member with is_developer=0 → assert: 0 rows
```

## Implementation Notes

- **No `send_date` filtering in SQL**: the 48h expiry window intentionally allows late submissions from the previous day to surface; this matches the existing token lifecycle from US-6
- **Ordering**: `ORDER BY t.name ASC` — alphabetical; consistent and deterministic for users in many teams
- **No empty-state message**: per requirements, when `$pendingTokens` is empty, the section is not rendered at all — no "No pending standups" text
- **Hex token safety**: `htmlspecialchars()` on the token is defensive hygiene; hex strings contain only `[0-9a-f]` and cannot contain HTML-special characters, but the pattern is consistent with all other user-data output in the codebase
