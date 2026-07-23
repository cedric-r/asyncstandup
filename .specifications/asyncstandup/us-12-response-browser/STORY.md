# US-12: Standup Response Browser

**Feature**: asyncstandup-core  
**Story**: US-12  
**Branch**: `feature/asyncstandup-response-browser`

## User Story

**As a** team owner  
**I can** browse all standup responses for my team, filtered by date and/or by team member  
**So that** I can read individual answers in full

## Acceptance Criteria

1. **Given** owner visits `responses.php?team_id=X` with no date/member filter, **When** rendered, **Then** shows last 7 days (newest first): one section per day, each listing all `is_developer` members' answers per question in `position` order; members with no submission listed as "No response"
2. **Given** owner submits `?date=YYYY-MM-DD`, **When** rendered, **Then** shows only that day's responses; all `is_developer` members shown; non-submitters listed as "No response"
3. **Given** owner submits `?member_id=N`, **When** rendered, **Then** shows that member's last 30 days (newest first); each day shows full answers or "No response"; limited to days where a token was sent
4. **Given** both `?date=YYYY-MM-DD&member_id=N` submitted, **When** rendered, **Then** shows exactly that member's response for that day, or "No response" if no submission
5. **Given** non-owner visits `responses.php?team_id=X`, **When** loaded, **Then** `forbid()` called → HTTP 403; no content rendered
6. **Given** invalid `team_id` or a team the logged-in user does not own, **When** loaded, **Then** `forbid()` → HTTP 403
7. **Given** team dashboard rendered for an owner, **When** loaded, **Then** "View Responses" link to `responses.php?team_id=X` is visible; link absent for non-owners

## Definition of Done

- [ ] All ACs met
- [ ] `isTeamOwner()` check enforced before any data is loaded or rendered
- [ ] `send_date` from `standup_tokens` used as canonical display date (team timezone date)
- [ ] Questions displayed in `position ASC` order on all views
- [ ] All date math in team timezone (consistent with dashboard)
- [ ] `htmlspecialchars(ENT_QUOTES, 'UTF-8')` on all user-supplied content (answer text, display names)
- [ ] No new DB tables
- [ ] Maximum scope: last 30 days; no pagination

## Files

| Action | File | Risk |
|---|---|---|
| Create | `public/teams/responses.php` | — |
| Modify | `src/DashboardRepository.php` | ⚠️ Path B — additive: new query functions only |
| Modify | `public/teams/dashboard.php` | ⚠️ Path B — add "View Responses" link for owners |

## Implementation Details

### Query parameter handling

```php
requireLogin();
$teamId   = isset($_GET['team_id'])   ? (int)$_GET['team_id']   : 0;
$dateFilter   = $_GET['date']      ?? null;   // YYYY-MM-DD or null
$memberFilter = isset($_GET['member_id']) ? (int)$_GET['member_id'] : null;

if (!$teamId || !isTeamOwner($pdo, $teamId, $currentUser['id'])) {
    forbid();
}

$team      = getTeamById($pdo, $teamId);
$questions = getTeamQuestions($pdo, $teamId);     // ORDER BY position ASC
$members   = getTeamDevelopers($pdo, $teamId);    // is_developer = 1
```

Date filter validation: if `$dateFilter` is set, validate format with `DateTimeImmutable::createFromFormat('Y-m-d', $dateFilter)` — show error and fall back to default view on invalid format.

Member filter validation: check `$memberFilter` is a member of the team with `is_developer = 1` — show error if not.

### View routing

```php
if ($dateFilter && $memberFilter) {
    $view = 'single';      // one member, one day
} elseif ($dateFilter) {
    $view = 'by_date';     // all members, one day
} elseif ($memberFilter) {
    $view = 'by_member';   // one member, 30 days
} else {
    $view = 'default';     // all members, last 7 days
}
```

### Core data query

All views share the same underlying query; `$view` affects the WHERE clause parameters:

```sql
SELECT
    t.send_date,
    t.user_id,
    u.display_name,
    t.id         AS token_id,
    ss.id        AS submission_id,
    q.id         AS question_id,
    q.question,
    q.position,
    a.answer
FROM standup_tokens t
JOIN users u             ON u.id = t.user_id
JOIN team_members tm     ON tm.team_id = t.team_id AND tm.user_id = t.user_id
LEFT JOIN standup_submissions ss ON ss.token_id = t.id
LEFT JOIN standup_answers a      ON a.submission_id = ss.id
LEFT JOIN team_questions q       ON q.id = a.question_id
WHERE t.team_id = :teamId
  AND tm.is_developer = 1
  [AND t.send_date = :date]          -- date filter
  [AND t.user_id = :memberId]        -- member filter
  [AND t.send_date >= :dateFrom]     -- 7-day or 30-day window
ORDER BY t.send_date DESC, u.display_name ASC, q.position ASC
```

### Data assembly (PHP-side)

Build a nested structure from query rows:

```php
$data = [];
// $data[$send_date][$user_id] = [
//     'display_name' => string,
//     'submitted'    => bool,
//     'answers'      => [question_id => answer_text],
// ]
```

For members with a token but no submission (`submission_id = null`): set `submitted = false`, `answers = []`.

For members who had a token for a given day but are absent from query results (no token sent): add them as "No response" in the rendering layer by cross-referencing against `$members`.

### "No response" member insertion

For default and by_date views, after building `$data[$date]`, cross-reference against `$members`:
```php
foreach ($members as $m) {
    if (!isset($data[$date][$m['id']])) {
        $data[$date][$m['id']] = [
            'display_name' => $m['display_name'],
            'submitted'    => false,
            'answers'      => [],
            'no_token'     => true,   // no email sent that day
        ];
    }
}
```
Render `no_token = true` as "No email sent" (grey); `submitted = false` but token exists as "No response" (amber).

### Date window helpers

```php
// Default view: last 7 days in team timezone
$teamTz = new DateTimeZone($team['timezone']);
$today  = new DateTimeImmutable('today', $teamTz);
$dateFrom = $today->modify('-6 days')->format('Y-m-d');  // 7 days inclusive
$dateTo   = $today->format('Y-m-d');

// Member view: last 30 days
$dateFrom30 = $today->modify('-29 days')->format('Y-m-d');
```

### Repository functions to add to `src/DashboardRepository.php`

```php
function getResponseData(PDO $pdo, int $teamId, ?string $date, ?int $memberId, string $dateFrom, string $dateTo): array
// Executes the core query with conditional WHERE clauses; returns flat row array

function getTeamDevelopers(PDO $pdo, int $teamId): array
// SELECT u.id, u.display_name FROM team_members JOIN users WHERE is_developer = 1

function getTeamQuestions(PDO $pdo, int $teamId): array
// SELECT id, question FROM team_questions ORDER BY position ASC
```

If `getTeamDevelopers()` and `getTeamQuestions()` already exist elsewhere (e.g. `TeamRepository.php`), reuse them rather than duplicating.

### Page layout — default view (7 days)

```
[Team: Office Team] — Standup Responses
Filters: [Date: ____-__-__] [Member: <select>] [Apply]

=== 2026-07-23 ===
Alice Cooper
  Q: What did you do yesterday?  A: Finished the login flow.
  Q: What will you do today?     A: Start on the dashboard.
  Q: Any blockers?               A: None.

Bob Smith                        [No response]

=== 2026-07-22 ===
...
```

Each day section is a `<div class="response-day">` with a `<h3>` date heading. Each member's answers in a `<dl>` (definition list) or `<table>`. "No response" members shown in muted style. "No email sent" members shown in grey with label.

### Filter form

```html
<form method="GET" action="">
    <input type="hidden" name="team_id" value="<?= $teamId ?>">
    <label>Date: <input type="date" name="date" value="<?= htmlspecialchars($dateFilter ?? '') ?>"></label>
    <label>Member:
        <select name="member_id">
            <option value="">All members</option>
            <?php foreach ($members as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $memberFilter === $m['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['display_name'], ENT_QUOTES, 'UTF-8') ?>
            </option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit">Apply</button>
    <a href="?team_id=<?= $teamId ?>">Clear</a>
</form>
```

### "View Responses" link in `public/teams/dashboard.php`

Add alongside existing dashboard content (owner path only — already inside the `is_owner` conditional):

```php
// Owner-only section
echo '<a href="/teams/responses.php?team_id=' . (int)$teamId . '">View Responses</a>';
```

## Security Notes

- `isTeamOwner()` check is the first operation after `requireLogin()` — before loading any team data, questions, or member lists
- All answer text rendered through `htmlspecialchars(ENT_QUOTES, 'UTF-8')` — user-submitted standup text could contain HTML
- `$teamId` and `$memberFilter` cast to `(int)` immediately on receipt — no string interpolation into SQL
- `$dateFilter` validated via `DateTimeImmutable::createFromFormat` before use in query
- Member filter validated: `$memberFilter` must be a developer member of `$teamId` — prevents accessing responses from other teams via crafted URLs
