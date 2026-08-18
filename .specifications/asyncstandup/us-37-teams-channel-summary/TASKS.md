# TASKS — US-37: Teams Channel Summary (Incoming Webhook)

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-37-teams-channel-summary`  
**Agent**: PHP Developer (`fa2e6dbf`)  
**Dependency**: US-36 merged (needs `notification_channel`, `teams_webhook_url` columns)

---

## Phase 1 — Branch

**T-1** `backend-dev` — Create branch from main (after US-36 merges)
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout main && git pull && git checkout -b feature/us-37-teams-channel-summary
```

---

## Phase 2 — `src/TeamsMessageBuilder.php` (AC-1)

**T-2** `backend-dev` — Create `src/TeamsMessageBuilder.php`

Implement `buildSummaryCard(array $summaryData): array`.

Key implementation details:
- `$teamName = $summaryData['team']['name']`
- `$date = $summaryData['date']` — format as `"Monday 18 August 2026"` using PHP `date_create($date)->format('l j F Y')`
- `$members = $summaryData['members']`
- `$responded = count(array_filter($members, fn($m) => $m['submitted']))`
- `$total = count($members)`
- `$pct = $summaryData['participation_pct']`
- Sort members: submitted first, then non-submitted (or use order from `$summaryData` which already sorts)

Per-member submitted block: iterate `$member['answers']`; for each, add a `TextBlock` with `"  {$q}: {$a}"` format; prefix answer text with `"⚠️ "` if `$answer['is_blocker']` and answer is non-empty.

Per-member not-submitted: single `TextBlock` with `isSubtle: true`.

Mood footer: only add if `$summaryData['avg_mood'] !== null`; format as `number_format((float)$summaryData['avg_mood'], 1)`.

Full return structure — outermost key must be `type: 'message'` with `attachments` array (Teams Incoming Webhook wrapping format). See STORY.md AC-1 for full PHP array structure.

---

## Phase 3 — `src/TeamsNotifier.php` (AC-2)

**T-3** `backend-dev` — Create `src/TeamsNotifier.php`

Full implementation from STORY.md AC-2 using `file_get_contents` + `stream_context_create`. No cURL.

HTTP status code parsing: parse from `$http_response_header` after `file_get_contents` call.

---

## Phase 4 — `src/SummaryEmailer.php` branching (AC-3)

**T-4** `backend-dev` — Locate the summary-dispatch function

```bash
grep -n "function.*summary\|sendSummary\|SummaryEmailer" "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup/src/SummaryEmailer.php" | head -20
```

**T-5** `backend-dev` — Add `notification_channel` branch at the top of the send function

Add `require_once` for `TeamsMessageBuilder.php` and `TeamsNotifier.php` at the top of `SummaryEmailer.php`.

Insert branching logic from STORY.md AC-3 at the entry of the function that dispatches the summary (before any email-sending code). The `sendSummaryEmail()` call must remain reachable for the `email` path and fallback.

Edge cases:
- `teams_webhook_url` is empty/null for a teams-mode team → fall back to email + `error_log`
- `postChannelSummary()` returns `false` → fall back to email + `error_log`

---

## Phase 5 — Tests (AC-4)

**T-6** `backend-dev` — Create `tests/TeamsChannelSummaryTest.php` (3 tests)

```php
class TeamsChannelSummaryTest extends TestCase
{
    private array $summaryData;

    protected function setUp(): void
    {
        $this->summaryData = [
            'team'             => ['id' => 1, 'name' => 'Engineering'],
            'date'             => '2026-08-18',
            'members'          => [
                ['display_name' => 'Alice', 'submitted' => true, 'submitted_at' => '09:12',
                 'answers' => [['question' => 'Yesterday?', 'answer' => 'Done PR', 'is_blocker' => 0]]],
                ['display_name' => 'Bob', 'submitted' => false, 'answers' => []],
            ],
            'participation_pct' => 50,
            'avg_mood'          => 3.5,
        ];
    }

    public function testBuildSummaryCardStructure(): void
    {
        $card = buildSummaryCard($this->summaryData);
        $this->assertEquals('message', $card['type']);
        $this->assertEquals('application/vnd.microsoft.card.adaptive',
                            $card['attachments'][0]['contentType']);
        $body = json_encode($card);
        $this->assertStringContainsString('Engineering', $body);
        $this->assertStringContainsString('50%', $body);
    }

    public function testBuildSummaryCardIncludesMoodWhenPresent(): void
    {
        $card = buildSummaryCard($this->summaryData);
        $body = json_encode($card);
        $this->assertStringContainsString('3.5/5', $body);
    }

    public function testBuildSummaryCardOmitsMoodWhenNull(): void
    {
        $this->summaryData['avg_mood'] = null;
        $card = buildSummaryCard($this->summaryData);
        $body = json_encode($card);
        $this->assertStringNotContainsString('Avg mood', $body);
    }
}
```

**T-7** `backend-dev` — Run full test suite; target ≥115 tests (112 prior + 3 new)

---

## Phase 6 — Commit and signal

**T-8** `backend-dev` — Commit
```bash
git add \
  src/TeamsMessageBuilder.php src/TeamsNotifier.php src/SummaryEmailer.php \
  tests/TeamsChannelSummaryTest.php \
  .specifications/asyncstandup/us-37-teams-channel-summary/
git commit -m "feat(us-37): Teams channel summary — Incoming Webhook, Adaptive Card builder, channel branch in SummaryEmailer"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (TeamsMessageBuilder) | T-2 |
| AC-2 (TeamsNotifier) | T-3 |
| AC-3 (SummaryEmailer branch) | T-4, T-5 |
| AC-4 (3 tests) | T-6, T-7 |

**Estimate**: ~6h
