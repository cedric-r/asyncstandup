# US-28: Member Answer History

**Status**: APPROVED (autonomous mode)  
**Feature**: Member Answer History  
**Branch**: `feature/us-28-member-answer-history`

---

## Story

**As a** developer team member  
**I want** to view my own standup history on `responses.php`  
**So that** I can review my past answers without needing to ask the team owner

---

## Acceptance Criteria

### AC-1 — `isDeveloperMember()` added to `src/TeamRepository.php`

```php
function isDeveloperMember(PDO $pdo, int $teamId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND is_developer = 1'
    );
    $stmt->execute([$teamId, $userId]);
    return $stmt->fetchColumn() !== false;
}
```

---

### AC-2 — `responses.php` access control replaced

**Replace** the current single-line gate (line 21):
```php
if (!$teamId || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) { forbid(); }
```

**With** permission-based access:
```php
$userId      = (int) $_SESSION['user_id'];
$isOwner     = $teamId > 0 && isTeamOwner($pdo, $teamId, $userId);
$isDeveloper = $teamId > 0 && isDeveloperMember($pdo, $teamId, $userId);

if (!$isOwner && !$isDeveloper) { forbid(); }  // recipient-only or non-member → 403

$team      = getTeamById($pdo, $teamId);
if ($team === null) { forbid(); }

$canSeeAll = $isOwner || (bool) ($team['summary_to_all_developers'] ?? 0);
```

**Rule table**:

| Role | `summary_to_all_developers` | Access | `$canSeeAll` |
|---|---|---|---|
| Owner | any | ✅ all members | `true` |
| Developer | `1` | ✅ all members | `true` |
| Developer | `0` | ✅ own answers only | `false` |
| Recipient-only (not developer, not owner) | any | ❌ 403 | — |
| Non-member | any | ❌ 403 | — |

---

### AC-3 — Data filter enforced server-side when `!$canSeeAll`

When `!$canSeeAll`, the `$memberFilter` is forced to the current user's ID regardless of any `?member_id=` GET parameter:

```php
if (!$canSeeAll) {
    $memberFilter = $userId;    // force — ignore any GET param
    $rawMember    = null;       // suppress validation of the GET param
}
```

This must be applied **before** the `$rawMember` validation block so a developer cannot pass another member's ID to bypass the filter. The `getResponseData()` call and the `no_token` fill-in loop already use `$memberFilter` and `$members` correctly once these are set.

For the `no_token` fill-in loop (`default` and `by_date` views), `$members` contains all developer members. When `!$canSeeAll`, replace `$members` in the fill-in loop with a single-element array for the current user only — otherwise "No email sent" rows appear for other team members:

```php
$fillMembers = $canSeeAll ? $members : [['id' => $userId, 'display_name' => $currentUser['display_name']]];
// Use $fillMembers in the no_token fill-in loop, not $members
```

---

### AC-4 — UI: member filter dropdown and page heading conditional on `$canSeeAll`

**Page heading** — replace hardcoded `"Standup Responses"`:
```php
$pageHeading = $canSeeAll ? 'Standup Responses' : 'My Standup History';
```
Then in the template: `<h1 ...><?= htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8') ?></h1>`

**Member filter dropdown** — wrap in `<?php if ($canSeeAll): ?>` / `<?php endif; ?>`. When hidden, the `<div>` block containing the `<select name="member_id">` is not rendered. The date filter and Apply/Clear buttons remain visible to all.

**`$isOwner` variable** — currently hardcoded `$isOwner = true;` (line 91). Replace with the actual computed value from AC-2. This variable is used in `team-nav.php` to conditionally render owner-only nav tabs — non-owners must not see Dashboard, Members, Questions, Recipients, Delete tabs.

---

### AC-5 — Navigation: `public/teams/index.php`

The "Responses" link currently sits inside `<?php if ($isTOwner): ?>`. Non-owner developers have no link to the page.

**Change**: Add a per-team developer check and a Responses/My History link outside the owner block:

```php
<?php $isTDeveloper = isDeveloperMember($pdo, (int) $team['id'], (int) $_SESSION['user_id']); ?>
```

Then after the `<?php endif; ?>` that closes the owner block, add:
```php
<?php if (!$isTOwner && $isTDeveloper): ?>
  <div class="flex flex-wrap gap-1.5 mt-2">
    <?php
      $label = ((int)($team['summary_to_all_developers'] ?? 0) === 1) ? 'Responses' : 'My History';
    ?>
    <a href="/teams/responses.php?team_id=<?= (int) $team['id'] ?>"
       class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded">
      <?= $label ?>
    </a>
  </div>
<?php endif; ?>
```

Note: `isDeveloperMember()` is called once per team card in the loop — same pattern as `isTeamOwner()` which is already called per-team.

---

### AC-6 — Navigation: `public/dashboard.php`

`getTeamsForUser()` already returns `is_owner` and `is_developer` per team (from the `team_members` JOIN). `$team['is_developer']` is available in the loop.

**Change**: After the closing `<?php endif; ?>` of the `<?php if ($team['is_owner']): ?>` block (which shows Dashboard + Settings), add:

```php
<?php if ($team['is_developer']): ?>
  <a href="/teams/responses.php?team_id=<?= (int) $team['id'] ?>"
     class="text-xs bg-white hover:bg-gray-50 text-gray-700 font-medium py-1.5 px-3 rounded-md border border-gray-300">
    History
  </a>
<?php endif; ?>
```

This ensures every developer member (including owners who are also developers) sees the History link. Owners already have Dashboard — the History link is additive.

---

### AC-7 — PHPUnit tests: 4 new tests (target ≥82 total)

New test class `tests/MemberAnswerHistoryTest.php`:

| Test | What it verifies |
|---|---|
| `testIsDeveloperMemberReturnsTrueForDeveloper` | `isDeveloperMember()` returns `true` when `is_developer = 1` |
| `testIsDeveloperMemberReturnsFalseForRecipientOnly` | `isDeveloperMember()` returns `false` when member has `is_developer = 0` |
| `testNonDeveloperNonOwnerCannotAccessResponseData` | Simulate recipient-only: `$isOwner = false`, `$isDeveloper = false` → access denied (test the access control logic, not HTTP) |
| `testDeveloperWithoutSummaryToAllSeesOwnAnswersOnly` | `$canSeeAll = false` → `$memberFilter` forced to `$userId`; `getResponseData()` called with correct forced filter |

All tests use `createTestPdo()`.

---

## Files Changed

| File | Change |
|---|---|
| `src/TeamRepository.php` | Add `isDeveloperMember()` |
| `public/teams/responses.php` | Replace access gate; add `$canSeeAll`, `$fillMembers`; conditional dropdown; conditional heading; fix `$isOwner` |
| `public/teams/index.php` | Add `$isTDeveloper` per-team; add Responses/My History link outside owner block |
| `public/dashboard.php` | Add History link for developer members |
| `tests/MemberAnswerHistoryTest.php` (new) | 4 PHPUnit tests |
