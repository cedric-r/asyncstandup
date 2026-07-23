# US-5: Daily Standup Emails

**Feature**: asyncstandup-core  
**Story**: US-5

## User Story

**As a** team member with `is_developer = true`  
**I can** receive a daily standup prompt email at my team's configured time  
**So that** I am reminded to submit my standup for the day with my team's custom questions

## Acceptance Criteria

1. **Given** a team's `standup_time` (in team timezone) falls within the current minute, **When** cron runs, **Then** one email sent per `is_developer` member with a unique submission link
2. **Given** email already sent to a member for today's `send_date` (token exists for that team+user+date), **When** cron fires again in the same minute, **Then** no duplicate sent
3. **Given** no token yet for a member today, **When** cron sends email, **Then** a `standup_tokens` row is created: `send_date` = today in team timezone, `expires_at` = `sent_at + 48 hours`
4. **Given** team has custom questions, **When** prompt email rendered, **Then** question texts included in email body as a preview list
5. **Given** SMTP failure for one member's email, **When** cron runs, **Then** failure appended to `logs/standup-errors.log`; next member's email still attempted
6. **Given** team has no `is_developer` members, **When** cron runs at that team's time, **Then** no emails sent; no error logged

## Definition of Done

- [ ] All ACs met
- [ ] Cron script is CLI-safe (check `php_sapi_name() === 'cli'` at top; exit if called over HTTP)
- [ ] Timezone arithmetic: convert `standup_time` + team timezone to UTC for comparison with current UTC time
- [ ] Token: `bin2hex(random_bytes(32))`, stored in `standup_tokens`
- [ ] SMTP: raw socket implementation in `src/Mailer.php` — reused across all email stories
- [ ] Error log: append-only file at `logs/standup-errors.log`

## Files

| Action | File |
|---|---|
| Create | `cron/send_standups.php` — main cron entry point |
| Create | `src/Mailer.php` — raw socket SMTP sender |
| Create | `src/StandupEmailer.php` — logic: which teams are due, which members need tokens |
| Create | `templates/email/standup_prompt.php` |

## Implementation Details

### Cron timing logic

```php
// Current UTC time
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// For each team:
$teamTz   = new DateTimeZone($team['timezone']);
$nowLocal = $nowUtc->setTimezone($teamTz);

// Team's standup_time as a DateTime today in team timezone
$scheduledLocal = DateTimeImmutable::createFromFormat(
    'Y-m-d H:i',
    $nowLocal->format('Y-m-d') . ' ' . substr($team['standup_time'], 0, 5),  // HH:MM
    $teamTz
);

// Match if within the current minute window
$diff = abs($nowUtc->getTimestamp() - $scheduledLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp());
if ($diff < 60) {
    // Team is due — process
}
```

### Dedup check

```sql
SELECT id FROM standup_tokens
WHERE team_id = ? AND user_id = ? AND send_date = ?
LIMIT 1
```

`send_date` = today's date in the **team's timezone** (not UTC): `$nowLocal->format('Y-m-d')`.

### Token creation

```php
$token     = bin2hex(random_bytes(32));
$sentAt    = $nowUtc->format('Y-m-d H:i:s');
$expiresAt = $nowUtc->modify('+48 hours')->format('Y-m-d H:i:s');
$sendDate  = $nowLocal->format('Y-m-d');
```

INSERT into `standup_tokens`. If INSERT fails due to UNIQUE collision (race condition), skip silently.

### Mailer (`src/Mailer.php`)

Raw socket SMTP implementation:

```php
function sendMail(array $config, string $to, string $toName, string $subject, string $body): void {
    $socket = stream_socket_client("tcp://{$config['smtp']['host']}:{$config['smtp']['port']}", $errno, $errstr, 10);
    if (!$socket) throw new RuntimeException("SMTP connect failed: $errstr");
    // Read greeting → EHLO → MAIL FROM → RCPT TO → DATA → body → QUIT
    smtpCommand($socket, "EHLO asyncstandup");
    smtpCommand($socket, "MAIL FROM:<{$config['smtp']['from']}>");
    smtpCommand($socket, "RCPT TO:<{$to}>");
    smtpCommand($socket, "DATA");
    fwrite($socket, "From: {$config['smtp']['from_name']} <{$config['smtp']['from']}>\r\n");
    fwrite($socket, "To: {$toName} <{$to}>\r\n");
    fwrite($socket, "Subject: {$subject}\r\n");
    fwrite($socket, "Date: " . date('r') . "\r\n\r\n");
    fwrite($socket, $body . "\r\n.\r\n");
    smtpCommand($socket, "QUIT");
    fclose($socket);
}
```

`smtpCommand()`: write command + `\r\n`, read response, assert 2xx/3xx response code.

### Email template (`templates/email/standup_prompt.php`)

Variables: `$user_name`, `$team_name`, `$standup_url`, `$send_date`, `$team_timezone`, `$questions` (array of question strings).

### Schema fragments

```sql
CREATE TABLE IF NOT EXISTS standup_tokens (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    send_date  DATE NOT NULL,
    sent_at    DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    UNIQUE KEY uq_token_team_user_date (team_id, user_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

UNIQUE on `(team_id, user_id, send_date)` enforces one token per member per day.

## Error Logging

```php
function logError(string $message): void {
    $line = date('Y-m-d H:i:s') . ' [ERROR] ' . $message . PHP_EOL;
    file_put_contents(__DIR__ . '/../logs/standup-errors.log', $line, FILE_APPEND | LOCK_EX);
}
```

`logs/` directory: create with `0750` permissions; add `logs/*.log` to `.gitignore`.
