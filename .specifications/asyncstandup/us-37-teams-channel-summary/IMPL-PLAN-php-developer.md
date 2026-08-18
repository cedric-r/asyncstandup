# IMPL-PLAN — US-37: Teams Channel Summary (Incoming Webhook)

**Status**: PENDING GATE C APPROVAL
**Branch**: `feature/us-37-teams-channel-summary`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-37 — Teams Channel Summary (Incoming Webhook)

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-4 and TASKS.md T-1 through T-8.
No schema changes. No Composer dependencies. Pure outbound HTTPS via `file_get_contents`.

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `assembleSummaryData()` output shape | Returns `{developers[], questions[], answerMap[userId][questionId]}` — NOT the `{members[], participation_pct, avg_mood}` shape expected by `buildSummaryCard()`. Transformation required inside `sendSummaryEmail()` |
| `sendSummaryEmail()` signature | `(PDO $pdo, array $config, array $team, string $sendDate, DateTimeImmutable $nowLocal): void` |
| Teams branch placement | AFTER `attemptInsertSummaryLock()` (dedup guard must fire for Teams too) and AFTER `assembleSummaryData()` call (need data to build card), BEFORE the email `foreach` loop |
| `submitted_at` availability | Not in `assembleSummaryData()` query — will pass `null`; `buildSummaryCard()` must handle `null` gracefully (omit the `· HH:MM` part if null) |
| `avg_mood` in this story | Not wired to mood tracking data in US-37 — pass `null`; US-38+ can extend. Card builder must accept `null` |
| `postChannelSummary()` network | Not unit-testable; spec explicitly excludes it from AC-4 tests |
| Current require_once in `SummaryEmailer.php` | None for Teams files — must add at top of file |

---

## Files to Create / Change

| File | Change |
|---|---|
| `src/TeamsMessageBuilder.php` (new) | `buildSummaryCard(array $summaryData): array` |
| `src/TeamsNotifier.php` (new) | `postChannelSummary(string $webhookUrl, array $card): bool` |
| `src/SummaryEmailer.php` | Add require_once + Teams branch inside `sendSummaryEmail()` |
| `tests/TeamsChannelSummaryTest.php` (new) | 3 PHPUnit tests |
| `tests/bootstrap.php` | Add require_once for `TeamsMessageBuilder.php` + `TeamsNotifier.php` |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-37-teams-channel-summary` created from `main`.

---

### T-2 — `src/TeamsMessageBuilder.php` (AC-1)

**`buildSummaryCard(array $summaryData): array`**

Input shape:
```php
['team'=>['id','name'], 'date'=>'YYYY-MM-DD', 'members'=>[
    ['display_name'=>'...', 'submitted'=>true, 'submitted_at'=>'HH:MM'|null,
     'answers'=>[['question'=>'...','answer'=>'...','is_blocker'=>0|1]]],
    ['display_name'=>'...', 'submitted'=>false, 'answers'=>[]],
], 'participation_pct'=>int, 'avg_mood'=>float|null]
```

Build:
- Header TextBlock: `"📋 Daily Standup Summary — {$teamName}"`
- Sub-header: `"{$dateFormatted} · {$responded}/{$total} responded ({$pct}%)"` — date formatted as `date_create($date)->format('l j F Y')`
- Per-member items (submitted first, then non-submitted — sort by `submitted DESC`):
  - Submitted: `ColumnSet` with `"✅ {$name}"` + optional `"  · {$submittedAt}"` (omit if null); then each answer as TextBlock `"  Q: A"` (or `"  ⚠️ Q: A"` if `is_blocker && answer !== ''`)
  - Not submitted: single TextBlock `"❌ {$name} — No response"` with `isSubtle: true`
- Footer: only if `avg_mood !== null` → TextBlock `"😊 Avg mood: {number_format($avgMood, 1)}/5"`

Outer wrapping: `['type'=>'message', 'attachments'=>[['contentType'=>'application/vnd.microsoft.card.adaptive','content'=>[...card...]]]]`

---

### T-3 — `src/TeamsNotifier.php` (AC-2)

Exact implementation from STORY.md AC-2. Key points:
- `@file_get_contents()` (suppress warnings — network errors caught via `=== false`)
- Parse HTTP status from `$http_response_header` via regex `#HTTP/\S+ (\d+)#`
- Return `$code >= 200 && $code < 300`

---

### T-4 + T-5 — `src/SummaryEmailer.php` (AC-3)

**Add at top of file** (after `<?php declare...`):
```php
require_once __DIR__ . '/TeamsMessageBuilder.php';
require_once __DIR__ . '/TeamsNotifier.php';
```

**Inside `sendSummaryEmail()`** — after `assembleSummaryData()` call and `$answerMap`/`$developers`/`$questions` are available, build the `$summaryCardData` array then branch:

```php
// Build card-compatible member list for Teams channel posting.
$membersForCard = [];
foreach ($developers as $dev) {
    $devId = (int) $dev['id'];
    if (isset($answerMap[$devId])) {
        $cardAnswers = [];
        foreach ($questions as $q) {
            $cardAnswers[] = [
                'question'   => $q['question'],
                'answer'     => $answerMap[$devId][(int) $q['id']] ?? '',
                'is_blocker' => (int) $q['is_blocker'],
            ];
        }
        $membersForCard[] = ['display_name' => $dev['display_name'] ?? $dev['email'],
                             'submitted' => true, 'submitted_at' => null, 'answers' => $cardAnswers];
    } else {
        $membersForCard[] = ['display_name' => $dev['display_name'] ?? $dev['email'],
                             'submitted' => false, 'answers' => []];
    }
}
$respondedCount = count(array_filter($membersForCard, fn($m) => $m['submitted']));
$totalCount     = count($membersForCard);
$summaryCardData = [
    'team'             => $team,
    'date'             => $sendDate,
    'members'          => $membersForCard,
    'participation_pct'=> $totalCount > 0 ? (int) round($respondedCount / $totalCount * 100) : 0,
    'avg_mood'         => null,
];

// Branch: Teams channel posting.
$channel = $team['notification_channel'] ?? 'email';
if (in_array($channel, ['teams', 'teams-summary'], true) && !empty($team['teams_webhook_url'])) {
    $card    = buildSummaryCard($summaryCardData);
    $success = postChannelSummary((string) $team['teams_webhook_url'], $card);
    if (!$success) {
        error_log("[AsyncStandUp] Teams webhook failed for team {$teamId} — falling back to email");
        // Fall through to email sending below.
    } else {
        return; // Teams posting succeeded — no email needed.
    }
}
// Continues to email sending...
```

Placement: after the existing `foreach ($developers as $dev)` block that builds `$submitterData`/`$nonSubmitters` (reuses the same loop pass but builds a parallel structure). In practice: insert the `$membersForCard` loop + branch AFTER `assembleSummaryData()` assignment, BEFORE the `$subject = ...` line.

---

### T-6 — `tests/TeamsChannelSummaryTest.php` (AC-4)

3 tests per TASKS.md T-6. No PDO needed (pure unit tests on `buildSummaryCard()`).

**`tests/bootstrap.php`**: add `require_once` for `TeamsMessageBuilder.php` + `TeamsNotifier.php`.

---

### T-7 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥115 tests (112 + 3), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors. PHPStan notes:
- `buildSummaryCard()` returns `array` — no `never`
- `postChannelSummary()` — `$http_response_header` is a PHP superglobal-like var set by `file_get_contents`; PHPStan may flag `Undefined variable $http_response_header` → use `isset()` guard (already in spec)

---

### T-8 — Commit

```bash
git add src/TeamsMessageBuilder.php src/TeamsNotifier.php src/SummaryEmailer.php \
        tests/TeamsChannelSummaryTest.php tests/bootstrap.php \
        .specifications/asyncstandup/us-37-teams-channel-summary/
git commit -m "feat(us-37): Teams channel summary — Incoming Webhook, Adaptive Card builder, channel branch in SummaryEmailer"
```

---

## Risk Notes

1. **`assembleSummaryData()` shape mismatch**: Does NOT return `{members[], participation_pct, avg_mood}`. Must transform inside `sendSummaryEmail()` before calling `buildSummaryCard()`. A parallel `$membersForCard` build loop is added — does not touch the existing `$submitterData`/`$nonSubmitters` variables used by the email path.
2. **`submitted_at` not available**: Query in `assembleSummaryData()` doesn't fetch submission timestamp. Card builder receives `null` and omits `"· HH:MM"`. Acceptable for US-37.
3. **`$http_response_header` PHPStan**: This variable is set by `file_get_contents()` as a side-effect. PHPStan may not know it. `isset($http_response_header)` guard (already in spec code) satisfies both PHPStan and runtime.
4. **Teams branch placement**: Must be AFTER `assembleSummaryData()` (need `$developers`, `$questions`, `$answerMap`) and BEFORE the email `foreach`. The dedup lock fires earlier — so Teams posting is also deduplicated correctly.
5. **`avg_mood` wired as `null`**: US-37 does not connect mood tracking. Card footer omitted when null. Future stories can pass real value.
