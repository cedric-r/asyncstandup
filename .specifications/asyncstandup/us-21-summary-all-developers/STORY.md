# US-21: "Send Summary to All Developers" Team Setting

**Feature**: asyncstandup-core  
**Story**: US-21  
**Branch**: `feature/asyncstandup-summary-all`

## User Story

**As a** team owner  
**I can** toggle a setting on my team so that all developer members automatically receive the daily standup summary email  
**So that** I don't have to add each developer individually as a recipient

## Acceptance Criteria

1. **Given** team owner visits team settings (`teams/edit.php`), **When** "Send summary to all developer members" checkbox is checked and form saved, **Then** `teams.summary_to_all_developers = 1` stored; checkbox reflects saved state on reload
2. **Given** `summary_to_all_developers = 1` for a team, **When** the daily summary fires, **Then** all team members with `is_developer = 1` receive the summary email (in addition to any explicit `team_recipients`)
3. **Given** `summary_to_all_developers = 0` (default), **When** the daily summary fires, **Then** behaviour unchanged — only `team_recipients` rows + `is_recipient = 1` members receive it
4. **Given** a developer member is also present in `team_recipients` with the same email, **When** `summary_to_all_developers = 1` and summary fires, **Then** they receive exactly one email (case-insensitive dedup)
5. **Given** an external `team_recipients` row has the same email as a developer member, **When** `summary_to_all_developers = 1`, **Then** email sent once; dedup applied before send loop

## Definition of Done

- [ ] All ACs met
- [ ] `teams.summary_to_all_developers TINYINT(1) NOT NULL DEFAULT 0` added to schema
- [ ] Migration note for existing deployments (ALTER TABLE + default keeps existing teams unaffected)
- [ ] `getMergedRecipients()` extended to accept team flags and conditionally include all-developer emails
- [ ] Developer emails included WITHOUT unsubscribe link (only explicit `team_recipients` get unsubscribe URL)
- [ ] Checkbox in `teams/edit.php` correctly reflects DB state on GET and saves on POST
- [ ] `TeamRepository.php` update function includes `summary_to_all_developers` field
- [ ] `tests/schema-sqlite.sql` updated with new column
- [ ] New test cases in `tests/RepositoryTest.php` covering `getMergedRecipients()` with flag on/off

## Files

| Action | File | Risk |
|---|---|---|
| Modify | `db/schema.sql` | ⚠️ Path B — ADD COLUMN |
| Modify | `tests/schema-sqlite.sql` | ⚠️ Path B — add column to SQLite schema |
| Modify | `src/SummaryEmailer.php` | ⚠️ Path B — extend `getMergedRecipients()` |
| Modify | `public/teams/edit.php` | ⚠️ Path B — add checkbox |
| Modify | `src/TeamRepository.php` | ⚠️ Path B — include field in UPDATE |
| Modify | `tests/RepositoryTest.php` | ⚠️ Path B — add test cases |

## Implementation Details

---

### Schema change (`db/schema.sql`)

```sql
ALTER TABLE teams
    ADD COLUMN summary_to_all_developers TINYINT(1) NOT NULL DEFAULT 0;
```

For new deployments, add to `CREATE TABLE teams`:
```sql
summary_to_all_developers TINYINT(1) NOT NULL DEFAULT 0,
```

For `tests/schema-sqlite.sql`, add:
```sql
summary_to_all_developers INTEGER NOT NULL DEFAULT 0,
```

Migration for existing deployments: `DEFAULT 0` means no existing team's behaviour changes — no `UPDATE` required.

---

### `getMergedRecipients()` extension in `src/SummaryEmailer.php`

Update function signature to accept team data:

```php
function getMergedRecipients(PDO $pdo, array $team): array {
    $teamId = (int) $team['id'];

    // Source 1: explicit external recipients (existing behaviour)
    $external = queryExternalRecipients($pdo, $teamId);

    // Source 2: is_recipient=1 members (existing behaviour)
    $memberRecipients = queryMemberRecipients($pdo, $teamId);

    // Source 3: all developers (new — only when flag set)
    $developerMembers = [];
    if (!empty($team['summary_to_all_developers'])) {
        $developerMembers = queryDeveloperMembers($pdo, $teamId);
    }

    // Merge and dedup by lowercase email
    $seen   = [];
    $merged = [];
    foreach (array_merge($external, $memberRecipients, $developerMembers) as $r) {
        $key = strtolower(trim($r['email']));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $merged[]   = $r;
        }
    }

    return $merged;
}
```

**New helper — `queryDeveloperMembers(PDO $pdo, int $teamId): array`**:

```php
function queryDeveloperMembers(PDO $pdo, int $teamId): array {
    $stmt = $pdo->prepare("
        SELECT u.email, u.display_name, NULL AS unsubscribe_token
        FROM team_members tm
        JOIN users u ON u.id = tm.user_id
        WHERE tm.team_id = ? AND tm.is_developer = 1
    ");
    $stmt->execute([$teamId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

`unsubscribe_token = NULL` is explicit — the send loop in `SummaryEmailer.php` skips building the unsubscribe URL when `unsubscribe_token` is null.

**Update call site** in `sendSummary()`:

```php
// Pass full $team array (not just $teamId)
$recipients = getMergedRecipients($pdo, $team);
```

Verify `$team` already includes `summary_to_all_developers` in the query that loads teams for the cron loop. If not, add the column to that SELECT.

---

### Unsubscribe link handling

In the send loop, the existing pattern:

```php
$unsubscribeToken = ensureUnsubscribeToken($pdo, $recipient['id']);
$unsubscribeUrl   = $config['app']['base_url'] . '/unsubscribe.php?token=' . urlencode($unsubscribeToken);
```

...assumes `$recipient` has an `id` (from `team_recipients`). Developer-only recipients (from `queryDeveloperMembers`) have no `team_recipients` row and no `id`.

**Adjustment**: check `unsubscribe_token` presence before building URL:

```php
if (!empty($recipient['unsubscribe_token'])) {
    $unsubscribeUrl = $config['app']['base_url'] . '/unsubscribe.php?token=' . urlencode($recipient['unsubscribe_token']);
} elseif (isset($recipient['id'])) {
    // External recipient but token not yet generated — lazy generation
    $unsubscribeToken = ensureUnsubscribeToken($pdo, $recipient['id']);
    $unsubscribeUrl   = $config['app']['base_url'] . '/unsubscribe.php?token=' . urlencode($unsubscribeToken);
} else {
    // Developer member added via summary_to_all_developers — no unsubscribe link
    $unsubscribeUrl = '';
}
```

Pass `$unsubscribeUrl` to template. In `standup_summary.php`, render unsubscribe line only when non-empty:

```php
<?php if (!empty($unsubscribe_url)): ?>
---
To stop receiving these summaries: <?= $unsubscribe_url ?>
<?php endif; ?>
```

---

### `public/teams/edit.php` — checkbox addition

In the team settings form (GET handler — load `$team` array including `summary_to_all_developers`):

```html
<div class="mt-4">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" name="summary_to_all_developers" value="1"
               <?= !empty($team['summary_to_all_developers']) ? 'checked' : '' ?>
               class="h-4 w-4 text-indigo-600 border-gray-300 rounded">
        <span class="text-sm text-gray-700">
            Send daily summary to all developer members automatically
        </span>
    </label>
    <p class="text-xs text-gray-500 mt-1 ml-6">
        When enabled, developers receive summaries without needing to be added as recipients individually.
        Developers can opt out via their profile page.
    </p>
</div>
```

In POST handler, read checkbox value:

```php
$summaryToAllDevelopers = isset($_POST['summary_to_all_developers']) ? 1 : 0;
```

Pass to `updateTeam()`.

---

### `src/TeamRepository.php` — include in UPDATE

In `updateTeam(PDO $pdo, int $teamId, ..., int $summaryToAllDevelopers): void`:

```sql
UPDATE teams
SET name = ?, timezone = ?, standup_time = ?, summary_to_all_developers = ?
WHERE id = ?
```

If the current signature does not include this field, add it. Pass `$summaryToAllDevelopers` as the 4th bound parameter.

---

### New test cases (`tests/RepositoryTest.php`)

Seed: team with `summary_to_all_developers = 0`; 1 developer member; 1 external `team_recipients` row.

```
Test: flag=0 → getMergedRecipients returns only external + is_recipient members (not developer)
Test: flag=1 → developer member email included in merged list
Test: flag=1, developer email = external recipient email → deduplicated to 1 entry
Test: flag=1, developer email = is_recipient member email → deduplicated to 1 entry
```

## Implementation Notes

- **Existing call site**: `getMergedRecipients($pdo, $teamId)` currently takes an int; update to pass full `$team` array — verify all callers updated
- **Cron query**: the cron loop's `getDueTeams()` query must `SELECT` `summary_to_all_developers` alongside other team fields — add if absent
- **No unsubscribe for developer-auto recipients**: these users can opt out via `profile.php` → "My summary subscriptions" (US-20 Sub-feature B) or ask the owner to uncheck the setting; this is consistent with the US-20 design note
- **DEFAULT 0**: no existing team is affected by this migration — zero data risk
