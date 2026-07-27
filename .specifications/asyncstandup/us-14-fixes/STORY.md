# US-14: Bug Fixes and Improvements

**Feature**: asyncstandup-core  
**Story**: US-14  
**Branch**: `feature/asyncstandup-fixes`

## User Story

**As a** developer and team member  
**I can** rely on summary emails reaching all intended recipients, see team context on my standup form, and know that weekend days are automatically skipped  
**So that** the system behaves correctly and predictably in daily use

## Acceptance Criteria

1. **Given** a team member with `is_recipient = 1`, **When** the daily summary fires, **Then** they receive the summary email at their registered email address
2. **Given** a user appears in both `team_members` (`is_recipient = 1`) and `team_recipients` with the same email, **When** summary fires, **Then** only one email is sent to that address (case-insensitive deduplication)
3. **Given** user visits a standup submission link, **When** the page loads, **Then** the org name and team name are displayed prominently at the top of the form (before the questions)
4. **Given** standup prompt email received, **When** user reads it, **Then** org name and team name are clearly stated in the email subject and/or body
5. **Given** today is Saturday or Sunday (in the team's timezone), **When** cron runs at any time, **Then** no prompt emails and no summary emails are sent for that team
6. **Given** today is Monday through Friday (in the team's timezone), **When** cron runs at the configured standup time, **Then** emails send as normal (no regression)
7. **Given** `cron/send_standups.php` is executed, **When** it loads config, **Then** config is loaded exactly once (no double `require`/`require_once` for the same file)

## Definition of Done

- [ ] All ACs met
- [ ] Bug 1: `SummaryEmailer.php` merges `team_recipients` and `is_recipient` members; dedup is case-insensitive (lowercase both sides before comparison)
- [ ] Feature 2: `submit.php` displays org name + team name; `standup_prompt.php` template includes org name + team name
- [ ] Feature 3: weekend check uses `DateTimeImmutable::format('N')` in team timezone; check fires before any email logic for that team
- [ ] Bug 4: orphan `require_once` removed from `cron/send_standups.php`; single config load confirmed
- [ ] No new DB tables or schema changes
- [ ] All Path B — modifications are minimal, targeted, non-breaking

## Files

| Action | File | Risk | Fix |
|---|---|---|---|
| Modify | `src/SummaryEmailer.php` | ⚠️ Path B | Bug 1 — merge recipient sources + dedup |
| Modify | `public/submit.php` | ⚠️ Path B | Feature 2 — load + display org+team name |
| Modify | `templates/email/standup_prompt.php` | ⚠️ Path B | Feature 2 — org+team in subject/body |
| Modify | `cron/send_standups.php` | ⚠️ Path B | Feature 3 — weekend skip; Bug 4 — double load |

## Implementation Details

---

### Bug 1: Summary email recipient merge

In `SummaryEmailer.php`, the function that retrieves recipients currently queries only `team_recipients`. Replace with a merged approach:

**Step 1 — existing query (external recipients, unchanged):**
```sql
SELECT email, display_name FROM team_recipients WHERE team_id = ?
```

**Step 2 — new query (member recipients):**
```sql
SELECT u.email, u.display_name
FROM team_members tm
JOIN users u ON u.id = tm.user_id
WHERE tm.team_id = ? AND tm.is_recipient = 1
```

**Step 3 — merge and deduplicate in PHP:**
```php
function getMergedRecipients(PDO $pdo, int $teamId): array {
    $external = queryExternalRecipients($pdo, $teamId);  // existing
    $members  = queryMemberRecipients($pdo, $teamId);    // new

    $seen   = [];
    $merged = [];

    foreach (array_merge($external, $members) as $r) {
        $key = strtolower(trim($r['email']));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $merged[]   = $r;
        }
    }

    return $merged;
}
```

Replace the existing recipient query call with `getMergedRecipients()`. The send loop is unchanged — iterates the merged array.

---

### Feature 2: Org + team context on submission form

**`public/submit.php` — data loading addition:**

After loading token and team data, add:
```php
$org = getOrgById($pdo, $team['org_id']);
// $org['name'] now available for display
```

`getOrgById()` defined in `OrgRepository.php` (added in US-13 or add here if absent):
```php
function getOrgById(PDO $pdo, int $orgId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM organisations WHERE id = ?');
    $stmt->execute([$orgId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
```

**`public/submit.php` — rendering addition:**

At the top of the form (before questions), add:
```html
<div class="standup-context">
    <p class="standup-org"><?= h($org['name']) ?></p>
    <h2 class="standup-team"><?= h($team['name']) ?> — Daily Standup</h2>
</div>
```

Also display on the already-submitted and error pages (expired/invalid) where the team is loaded — pass org name through to those views too.

**`templates/email/standup_prompt.php` — template additions:**

The template already receives `$team_name`. Add `$org_name` as a new variable (passed by `StandupEmailer.php`).

Subject line update:
```php
$subject = "[{$org_name}] {$team_name} — Daily Standup for {$send_date}";
```

Body: add org and team name to the opening line:
```
Daily standup for: {$org_name} / {$team_name}
Date: {$send_date} ({$team_timezone})
```

**`src/StandupEmailer.php` — pass org name to template:**

When building template variables for `standup_prompt.php`, add:
```php
$org = getOrgById($pdo, $team['org_id']);
$vars = [
    'user_name'     => $member['display_name'],
    'team_name'     => $team['name'],
    'org_name'      => $org['name'] ?? '',   // new
    'standup_url'   => ...,
    'send_date'     => ...,
    'team_timezone' => $team['timezone'],
    'questions'     => ...,
];
```

---

### Feature 3: Weekend skip

**`cron/send_standups.php` — add inside the team loop, before any email logic:**

```php
foreach ($teams as $team) {
    $teamTz    = new DateTimeZone($team['timezone']);
    $nowLocal  = $nowUtc->setTimezone($teamTz);
    $dayOfWeek = (int) $nowLocal->format('N');  // 1=Mon … 7=Sun (ISO 8601)

    if ($dayOfWeek === 6 || $dayOfWeek === 7) {
        continue;  // Skip Saturday (6) and Sunday (7) in team's local time
    }

    // ... existing prompt pass logic ...
    // ... existing summary pass logic ...
}
```

The check uses the team's own timezone — a team in Auckland can be a different day-of-week to the server's local time. Using `DateTimeImmutable::format('N')` on `$nowLocal` (already computed) is correct.

---

### Bug 4: Double config load

In `cron/send_standups.php`, locate the pattern:

```php
require_once __DIR__ . '/../config/config.php';  // ← orphan; remove this line
$config = require __DIR__ . '/../config/config.php';
```

Remove the `require_once` line. The `$config = require ...` line is the canonical load — it assigns the returned array to `$config`. The `require_once` beforehand either silently returns nothing (on second load) or loads the file without capturing the return value, making `$config` potentially undefined.

After fix: exactly one `$config = require __DIR__ . '/../config/config.php';` line at the top of the cron script.

---

## Technical Notes

- **Dedup case-insensitivity**: `strtolower(trim($email))` — handles `User@Example.com` and `user@example.com` as the same recipient
- **`$org_name` template variable**: must be added to `StandupEmailer.php`'s template vars array and documented in `templates/email/standup_prompt.php` header comment; no change to `standup_summary.php` (summary already has team context)
- **Weekend check applies to both passes**: the `continue` at the top of the team loop skips both the prompt pass AND the summary pass for that team — a single check covers both
- **`format('N')`**: PHP ISO day-of-week (1 = Monday … 7 = Sunday) — use 6 and 7 for Sat/Sun; do not use `'w'` (0 = Sunday in that format)
- **No new queries on the happy path**: Bug 4 fix is a one-line deletion; Feature 3 adds one `format('N')` call and a `continue` per loop iteration — negligible overhead
