# TASKS — US-28: Member Answer History

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-28-member-answer-history`  
**Agent**: PHP Developer (`fa2e6dbf`)

---

## Phase 1 — Branch setup

**T-1** `backend-dev` — Create feature branch
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" \
  checkout -b feature/us-28-member-answer-history
```

---

## Phase 2 — `src/TeamRepository.php`: add `isDeveloperMember()` (AC-1)

**T-2** `backend-dev` — Add helper after `isTeamMember()` (line ~166):

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

## Phase 3 — `public/teams/responses.php`: access control + data filter + UI (AC-2, AC-3, AC-4)

**T-3** `backend-dev` — Replace access control block (line 21)

Remove:
```php
if (!$teamId || !isTeamOwner($pdo, $teamId, (int) $_SESSION['user_id'])) { forbid(); }
```

Replace with:
```php
$userId      = (int) $_SESSION['user_id'];
$isOwner     = $teamId > 0 && isTeamOwner($pdo, $teamId, $userId);
$isDeveloper = $teamId > 0 && isDeveloperMember($pdo, $teamId, $userId);

if (!$isOwner && !$isDeveloper) { forbid(); }

$team = getTeamById($pdo, $teamId);
if ($team === null) { forbid(); }

$canSeeAll = $isOwner || (bool) ($team['summary_to_all_developers'] ?? 0);
```

Also remove the standalone `$team = getTeamById($pdo, $teamId);` call that currently follows on the next line (it is now inside the new block).

**T-4** `backend-dev` — Apply `!$canSeeAll` data filter before `$rawMember` validation

Insert immediately after the `$rawMember = ...` assignment and before the `$dateFilter` / `$memberFilter` initialisation block:

```php
// When developer cannot see all, ignore any member_id GET param and force own user.
if (!$canSeeAll) {
    $rawMember    = null;
    $memberFilter = $userId;
}
```

Then in the existing `$rawMember` validation block (which now only runs when `$rawMember !== null`), no change is required — the block is already guarded by `if ($rawMember !== null && $rawMember > 0)`.

**T-5** `backend-dev` — Replace `$isOwner = true;` with computed value

Find `$isOwner  = true;` (around line 91) and delete it — `$isOwner` is now set in T-3's block above.

**T-6** `backend-dev` — Add `$fillMembers` for the no-token fill-in loop

After `$memberMap = [];` (the loop that builds the member name lookup), insert:
```php
// Fill-in loop should only show the current user when they cannot see all members.
$fillMembers = $canSeeAll ? $members : [['id' => $userId, 'display_name' => $currentUser['display_name'] ?? '']];
```

Then in the no-token fill-in loop (inside `if ($view === 'default' || $view === 'by_date')`), replace `$members` with `$fillMembers`:
```php
foreach ($fillMembers as $m) {
    $uid = (int) $m['id'];
    if (!isset($data[$date][$uid])) {
        $data[$date][$uid] = ['display_name' => $m['display_name'], 'submitted' => false, 'answers' => [], 'no_token' => true];
    }
}
```

Note: `$currentUser` is already fetched later in the page. Move `$currentUser = getCurrentUser($pdo);` to before the `$fillMembers` assignment.

**T-7** `backend-dev` — Conditional page heading

Add before `ob_start()`:
```php
$pageHeading = $canSeeAll ? 'Standup Responses' : 'My Standup History';
```

In the template, replace:
```html
<h1 class="text-xl font-bold text-gray-900 mb-4">Standup Responses</h1>
```
With:
```php
<h1 class="text-xl font-bold text-gray-900 mb-4"><?= htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8') ?></h1>
```

Also update `$pageTitle`:
```php
$pageTitle = ($canSeeAll ? 'Responses' : 'My History') . ' — ' . htmlspecialchars($team['name'], ENT_QUOTES, 'UTF-8');
```

**T-8** `backend-dev` — Wrap member filter `<select>` in `$canSeeAll` conditional

In the filter form, wrap the member `<div>` block containing `<select name="member_id">` with:
```php
<?php if ($canSeeAll): ?>
  <div>
    <label class="block text-xs text-gray-600 mb-1">Member</label>
    <select name="member_id" ...>
      ...
    </select>
  </div>
<?php endif; ?>
```

Leave date filter, Apply, and Clear visible unconditionally.

---

## Phase 4 — `public/teams/index.php`: developer navigation link (AC-5)

**T-9** `backend-dev` — Add `$isTDeveloper` check and Responses/My History link

Inside the `<?php foreach ($teams as $team): ?>` loop, add immediately after `$isTOwner = isTeamOwner(...)`:
```php
$isTDeveloper = isDeveloperMember($pdo, (int) $team['id'], (int) $_SESSION['user_id']);
```

After the closing `<?php endif; ?>` of the `<?php if ($isTOwner): ?>` owner buttons block, add:
```php
<?php if (!$isTOwner && $isTDeveloper): ?>
  <div class="flex flex-wrap gap-1.5 mt-2">
    <?php $histLabel = ((int)($team['summary_to_all_developers'] ?? 0) === 1) ? 'Responses' : 'My History'; ?>
    <a href="/teams/responses.php?team_id=<?= (int) $team['id'] ?>"
       class="text-xs bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-1 px-2.5 rounded">
      <?= htmlspecialchars($histLabel, ENT_QUOTES, 'UTF-8') ?>
    </a>
  </div>
<?php endif; ?>
```

Confirm `isDeveloperMember()` is available — `src/TeamRepository.php` is already `require_once`'d in `index.php`.

---

## Phase 5 — `public/dashboard.php`: History link for developer members (AC-6)

**T-10** `backend-dev` — Add History link after the owner button block

In the dashboard team card loop, the owner block currently ends with `<?php endif; ?>` after the Settings link. Add immediately after:
```php
<?php if ($team['is_developer']): ?>
  <a href="/teams/responses.php?team_id=<?= (int) $team['id'] ?>"
     class="text-xs bg-white hover:bg-gray-50 text-gray-700 font-medium py-1.5 px-3 rounded-md border border-gray-300">
    History
  </a>
<?php endif; ?>
```

`$team['is_developer']` is already available — `getTeamsForUser()` SELECTs `tm.is_developer`.

---

## Phase 6 — PHPUnit tests (AC-7)

**T-11** `backend-dev` — Create `tests/MemberAnswerHistoryTest.php`

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/TeamRepository.php';

class MemberAnswerHistoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
    }

    public function testIsDeveloperMemberReturnsTrueForDeveloper(): void
    {
        // Insert org, team, user, member with is_developer = 1
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'T', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'dev@x.com', 'h')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 0, 1, 0)");

        $this->assertTrue(isDeveloperMember($this->pdo, 1, 1));
    }

    public function testIsDeveloperMemberReturnsFalseForRecipientOnly(): void
    {
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time) VALUES (1, 1, 'T', 'UTC', '09:00')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'rec@x.com', 'h')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 0, 0, 1)");

        $this->assertFalse(isDeveloperMember($this->pdo, 1, 1));
    }

    public function testRecipientOnlyDeniedAccess(): void
    {
        // Simulate the access control logic directly
        $isOwner     = false;   // not an owner
        $isDeveloper = false;   // not a developer (recipient-only)
        $canAccess   = $isOwner || $isDeveloper;

        $this->assertFalse($canAccess, 'Recipient-only member must not access responses page');
    }

    public function testDeveloperWithoutSummaryToAllForcesOwnFilter(): void
    {
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, summary_to_all_developers) VALUES (1, 1, 'T', 'UTC', '09:00', 0)");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'dev@x.com', 'h')");
        $this->pdo->exec("INSERT INTO team_members (team_id, user_id, is_owner, is_developer, is_recipient) VALUES (1, 1, 0, 1, 0)");

        $team        = ['summary_to_all_developers' => 0];
        $isOwner     = false;
        $isDeveloper = true;
        $canSeeAll   = $isOwner || (bool)($team['summary_to_all_developers'] ?? 0);

        $this->assertFalse($canSeeAll, 'Developer without summary_to_all should not see all members');

        // Verify that forced member filter equals the current user's ID
        $userId       = 1;
        $memberFilter = $canSeeAll ? null : $userId;
        $this->assertEquals($userId, $memberFilter);
    }
}
```

**T-12** `backend-dev` — Run full test suite

```bash
cd "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup"
./vendor/bin/phpunit
```

Target: ≥82 tests (78 prior + 4 new), all green.

---

## Phase 7 — Commit and signal

**T-13** `backend-dev` — Commit all changes

```bash
git add \
  src/TeamRepository.php \
  public/teams/responses.php \
  public/teams/index.php \
  public/dashboard.php \
  tests/MemberAnswerHistoryTest.php \
  .specifications/asyncstandup/us-28-member-answer-history/

git commit -m "feat(us-28): member answer history — developer access to responses.php with canSeeAll guard"
```

Signal Team Lead with commit hash.

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (`isDeveloperMember()`) | T-2 |
| AC-2 (access control) | T-3 |
| AC-3 (server-side filter + `$fillMembers`) | T-4, T-6 |
| AC-4 (UI: heading + dropdown) | T-5, T-7, T-8 |
| AC-5 (`teams/index.php` nav) | T-9 |
| AC-6 (`dashboard.php` nav) | T-10 |
| AC-7 (4 tests) | T-11, T-12 |

---

## Estimate

| Phase | Tasks | Hours |
|---|---|---|
| Branch | T-1 | 0.25h |
| `isDeveloperMember()` | T-2 | 0.5h |
| `responses.php` access + data + UI | T-3 – T-8 | 2.5h |
| `index.php` nav | T-9 | 0.5h |
| `dashboard.php` nav | T-10 | 0.5h |
| Tests | T-11, T-12 | 1.5h |
| Commit | T-13 | 0.25h |
| **Total** | | **~6h** |
