# US-8: Summary Email

**Feature**: asyncstandup-core  
**Story**: US-8

## User Story

**As a** team recipient (external or internal)  
**I can** receive a daily standup summary email 1 hour after the team's standup time  
**So that** I stay informed of the team's progress without needing to log in

## Acceptance Criteria

1. **Given** 1 hour after team standup time (in team timezone), **When** cron fires, **Then** one summary email sent to each email address in `team_recipients` for that team
2. **Given** summary already sent today for this team (row in `summary_sent`), **When** cron fires again, **Then** no duplicate sent
3. **Given** summary rendered, **When** sent, **Then** includes: each `is_developer` member's display name + answers grouped by question (in question position order); members with no submission listed as "No response"
4. **Given** SMTP failure for one recipient, **When** cron runs, **Then** failure logged to `logs/standup-errors.log`; remaining recipients still attempted
5. **Given** zero submissions for the day, **When** summary sent, **Then** all developers listed as "No response"; summary email still sent
6. **Given** team has no recipients in `team_recipients`, **When** cron fires, **Then** no email sent; no error logged; `summary_sent` row still inserted to prevent wasted queries on next cron run

## Definition of Done

- [ ] All ACs met
- [ ] Summary time = `standup_time + 1 hour` computed in team timezone
- [ ] `summary_sent` row inserted before sending (prevents double-send even if process crashes mid-send)
- [ ] `INSERT IGNORE INTO summary_sent` used as the dedup guard (UNIQUE on `team_id, send_date`)
- [ ] Summary data assembled in PHP (not a single mega-query); team's developer list + their answers loaded separately
- [ ] Reuses `src/Mailer.php` from US-5

## Files

| Action | File |
|---|---|
| Modify | `cron/send_standups.php` — add summary send pass after prompt pass |
| Create | `src/SummaryEmailer.php` — logic for summary timing + assembly |
| Create | `templates/email/standup_summary.php` |

## Implementation Details

### Summary timing check

```php
// In cron, after standup prompt pass:
$summaryScheduledLocal = $scheduledLocal->modify('+1 hour');
$summaryDiff = abs($nowUtc->getTimestamp()
    - $summaryScheduledLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp());

if ($summaryDiff < 60) {
    sendSummary($pdo, $config, $team, $nowLocal);
}
```

`$scheduledLocal` is already computed in the standup prompt pass — reused here.

### Dedup guard

```php
// Attempt INSERT IGNORE; if 0 rows affected, already sent today
$stmt = $pdo->prepare(
    'INSERT IGNORE INTO summary_sent (team_id, send_date, sent_at) VALUES (?, ?, UTC_TIMESTAMP())'
);
$stmt->execute([$team['id'], $sendDate]);
if ($stmt->rowCount() === 0) return; // already sent
```

`send_date` = today in team timezone: `$nowLocal->format('Y-m-d')`.

### Summary data assembly

```php
// 1. Load all is_developer members for the team
$developers = query('SELECT u.id, u.display_name FROM team_members tm
    JOIN users u ON u.id = tm.user_id
    WHERE tm.team_id = ? AND tm.is_developer = 1', [$teamId]);

// 2. Load questions in order
$questions = query('SELECT id, question FROM team_questions WHERE team_id = ? ORDER BY position', [$teamId]);

// 3. Load submissions for today's send_date
$submissions = query('
    SELECT ss.user_id, a.question_id, a.answer
    FROM standup_tokens t
    JOIN standup_submissions ss ON ss.token_id = t.id
    JOIN standup_answers a     ON a.submission_id = ss.id
    WHERE t.team_id = ? AND t.send_date = ?
', [$teamId, $sendDate]);

// 4. Build lookup: $answerMap[$user_id][$question_id] = $answer
// 5. For each developer:
//    - if in $answerMap → include their answers per question
//    - else → "No response"
```

### Email template (`templates/email/standup_summary.php`)

Variables (available after `extract()`):
- `$team_name` — string
- `$send_date` — date string (Y-m-d)
- `$questions` — array of `['id' => int, 'question' => string]`
- `$submissions` — array of `['display_name' => string, 'answers' => [question_id => answer_text]]`
- `$non_submitters` — array of display name strings

Template renders plain-text summary grouped per developer.

### Schema fragment

```sql
CREATE TABLE IF NOT EXISTS summary_sent (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id   INT UNSIGNED NOT NULL,
    send_date DATE NOT NULL,
    sent_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    UNIQUE KEY uq_summary_team_date (team_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Cron Architecture Note

`cron/send_standups.php` runs two passes per minute:

1. **Prompt pass**: for each team where `standup_time` matches now → send developer prompt emails
2. **Summary pass**: for each team where `standup_time + 1 hour` matches now → send summary emails

Both passes iterate all teams in a single DB query; timezone comparison done in PHP to avoid DB-side timezone functions.
