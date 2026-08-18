# US-37: Teams Channel Summary (Incoming Webhook)

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-37-teams-channel-summary`  
**Depends on**: US-36 merged (`notification_channel`, `teams_webhook_url` on `teams`)

---

## Story

**As a** team owner with a Teams channel  
**I want** the daily standup summary posted as an Adaptive Card to my Teams channel  
**So that** the whole team sees participation and answers without leaving Teams

---

## Acceptance Criteria

### AC-1 — `src/TeamsMessageBuilder.php`: `buildSummaryCard(array $summaryData): array`

Returns an Adaptive Card JSON structure as a PHP array (caller `json_encode`s it).

Input `$summaryData` shape (matches `assembleSummaryData()` output from US-31/32):
```php
[
    'team'    => [...],      // team row including name, timezone
    'date'    => '2026-08-18',
    'members' => [
        [
            'display_name' => 'Alice',
            'submitted'    => true,
            'submitted_at' => '09:12',
            'answers'      => [
                ['question' => 'Yesterday?', 'answer' => 'Reviewed PR', 'is_blocker' => 0],
                ...
            ],
        ],
        ['display_name' => 'Bob', 'submitted' => false, 'answers' => []],
    ],
    'participation_pct' => 67,
    'avg_mood'          => 4.1,    // null if no mood question
]
```

Card structure — Adaptive Card v1.4 (Teams-compatible):
```php
[
    'type'    => 'message',
    'attachments' => [[
        'contentType' => 'application/vnd.microsoft.card.adaptive',
        'content'     => [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type'    => 'AdaptiveCard',
            'version' => '1.4',
            'body'    => [
                // Header block
                ['type'=>'TextBlock','text'=>"📋 Daily Standup Summary — {$teamName}",'weight'=>'Bolder','size'=>'Medium'],
                ['type'=>'TextBlock','text'=>"{$dateFormatted} · {$responded}/{$total} responded ({$pct}%)",'isSubtle'=>true,'spacing'=>'None'],
                // Separator
                ['type'=>'Container','separator'=>true,'items'=>[
                    // Per-member blocks (submitted then non-submitted)
                    ...
                ]],
                // Footer: mood average (if present)
            ],
        ],
    ]],
]
```

Per-member block (submitted):
```php
['type'=>'ColumnSet','columns'=>[
    ['type'=>'Column','width'=>'stretch','items'=>[
        ['type'=>'TextBlock','text'=>"✅ {$name}  ·  {$submittedAt}",'weight'=>'Bolder'],
        ...answers as TextBlocks, with blocker answer prefixed "⚠️ "...
    ]],
]],
```

Per-member block (not submitted):
```php
['type'=>'TextBlock','text'=>"❌ {$name} — No response",'isSubtle'=>true],
```

Footer (if `avg_mood` is not null):
```php
['type'=>'TextBlock','text'=>"😊 Avg mood: {$avgMood}/5",'spacing'=>'Medium'],
```

---

### AC-2 — `src/TeamsNotifier.php`: `postChannelSummary(string $webhookUrl, array $card): bool`

```php
function postChannelSummary(string $webhookUrl, array $card): bool
{
    $payload = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($webhookUrl, false, $ctx);
    if ($response === false) { return false; }

    // Teams Incoming Webhook returns HTTP 200 with body "1" on success
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) { $code = (int) $m[1]; }
        }
    }
    return $code >= 200 && $code < 300;
}
```

No external cURL required — uses PHP stream context (vanilla constraint).

---

### AC-3 — `src/SummaryEmailer.php`: branch on `notification_channel`

In the function that sends the daily summary (currently sends via `Mailer.php`), add branching:

```php
$channel = $team['notification_channel'] ?? 'email';

if (in_array($channel, ['teams', 'teams-summary'], true) && !empty($team['teams_webhook_url'])) {
    $card    = buildSummaryCard($summaryData);
    $success = postChannelSummary($team['teams_webhook_url'], $card);
    if (!$success) {
        // Fallback to email + log (US-40 will extend error tracking)
        error_log("[AsyncStandUp] Teams webhook failed for team {$team['id']} — falling back to email");
        sendSummaryEmail($team, $summaryData);  // existing function
    }
    if ($channel === 'teams') {
        return;  // pure Teams mode: no email summary
    }
    // teams-summary: webhook already sent (or fell back); no email needed
    return;
}

// Default: email
sendSummaryEmail($team, $summaryData);
```

---

### AC-4 — PHPUnit tests: 3 new tests

New test class `tests/TeamsChannelSummaryTest.php`:

| Test | What it verifies |
|---|---|
| `testBuildSummaryCardStructure` | `buildSummaryCard($data)` returns array with `attachments[0].contentType = 'application/vnd.microsoft.card.adaptive'` and body TextBlocks containing team name and participation percent |
| `testBuildSummaryCardIncludesMoodWhenPresent` | With `avg_mood => 3.5`: card body contains "3.5/5" |
| `testBuildSummaryCardOmitsMoodWhenNull` | With `avg_mood => null`: no "Avg mood" TextBlock in body |

`postChannelSummary()` is not unit-tested (requires live network) — integration test is manual.

---

## Files Changed

| File | Change |
|---|---|
| `src/TeamsMessageBuilder.php` (new) | `buildSummaryCard()` |
| `src/TeamsNotifier.php` (new) | `postChannelSummary()` |
| `src/SummaryEmailer.php` | Branch on `notification_channel` |
| `tests/TeamsChannelSummaryTest.php` (new) | 3 tests |
