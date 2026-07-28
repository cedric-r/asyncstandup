# US-20: Recipient Self-Service (Unsubscribe)

**Feature**: asyncstandup-core  
**Story**: US-20  
**Branch**: `feature/asyncstandup-unsubscribe`

## User Story

**As a** recipient of standup summary emails  
**I can** remove myself from a team's summary list without contacting the team owner  
**So that** I can manage my own email preferences

## Acceptance Criteria

1. **Given** an external recipient in `team_recipients`, **When** a summary email is sent, **Then** the email contains a personal "Unsubscribe from this team's summaries" link at the bottom using the recipient's unique `unsubscribe_token`
2. **Given** recipient clicks the unsubscribe link, **When** page loads, **Then** shows the team name and org name and a "Confirm unsubscribe" button — no login required
3. **Given** recipient clicks "Confirm unsubscribe", **When** POST submitted, **Then** row deleted from `team_recipients`; confirmation "You have been unsubscribed." shown; no further summary emails from that team
4. **Given** invalid or missing token in the URL, **When** page loads, **Then** "Invalid unsubscribe link." shown; no data modified
5. **Given** registered user with `is_recipient = 1` on one or more teams, **When** visiting their profile page, **Then** a "My summary subscriptions" section lists each `[Org] / [Team]` with a "Remove me" button
6. **Given** registered user clicks "Remove me" for a team, **When** POST submitted, **Then** `team_members.is_recipient = 0` for that team; team disappears from list; flash "Removed from summary list."
7. **Given** registered user appears in both `team_members (is_recipient=1)` and `team_recipients` for the same team, **When** they use the profile "Remove me" action, **Then** only `team_members.is_recipient` is set to 0; their `team_recipients` row (external address) is unaffected

## Definition of Done

- [ ] All ACs met
- [ ] `unsubscribe_token VARCHAR(64) UNIQUE NULL` added to `team_recipients`
- [ ] Token generated with `bin2hex(random_bytes(32))` when recipient added via `recipients.php`
- [ ] Lazy token generation: if a recipient row has no token at summary send time, generate + save before sending
- [ ] `unsubscribe.php`: no login required; CSRF on confirmation POST; token looked up from DB; row deleted on confirm
- [ ] Profile subscriptions section: CSRF on each "Remove me" POST; only `is_recipient` toggled; no self-add
- [ ] All DB operations parameterised PDO
- [ ] Token in URL `htmlspecialchars`'d in email template output

## Files

| Action | File | Risk |
|---|---|---|
| Create | `public/unsubscribe.php` | — |
| Modify | `db/schema.sql` | ⚠️ Path B — additive: ADD COLUMN |
| Modify | `src/SummaryEmailer.php` | ⚠️ Path B — lazy token generation + pass unsubscribe_url per recipient |
| Modify | `templates/email/standup_summary.php` | ⚠️ Path B — append unsubscribe link |
| Modify | `public/profile.php` | ⚠️ Path B — add subscriptions section |
| Modify | `public/teams/recipients.php` | ⚠️ Path B — generate token on recipient add |

## Implementation Details

---

### Schema change (`db/schema.sql`)

Add column to `team_recipients`:

```sql
ALTER TABLE team_recipients
    ADD COLUMN unsubscribe_token VARCHAR(64) NULL UNIQUE;
```

For new deployments, update `CREATE TABLE team_recipients` to include the column:
```sql
unsubscribe_token VARCHAR(64) NULL UNIQUE,
```

Existing rows: token is `NULL` until first summary send (lazy generation) or manual migration:
```sql
-- Generate tokens for existing rows (run once; not automated)
-- PHP script: foreach existing team_recipients row, UPDATE with bin2hex(random_bytes(32))
```

Document in README as a one-time migration step.

---

### Token generation on recipient add (`public/teams/recipients.php`)

When inserting a new `team_recipients` row, generate the token immediately:

```php
$token = bin2hex(random_bytes(32));
$stmt  = $pdo->prepare(
    'INSERT INTO team_recipients (team_id, email, display_name, added_by, created_at, unsubscribe_token)
     VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)'
);
$stmt->execute([$teamId, $email, $displayName, $currentUser['id'], $token]);
```

---

### Lazy token generation in `src/SummaryEmailer.php`

When iterating recipients to send summary emails, check for missing token and generate if absent:

```php
function ensureUnsubscribeToken(PDO $pdo, int $recipientId): string {
    $stmt = $pdo->prepare('SELECT unsubscribe_token FROM team_recipients WHERE id = ?');
    $stmt->execute([$recipientId]);
    $existing = $stmt->fetchColumn();

    if ($existing) return $existing;

    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE team_recipients SET unsubscribe_token = ? WHERE id = ?')
        ->execute([$token, $recipientId]);
    return $token;
}
```

Include `id` in the recipient query to enable this lookup. Updated recipient query:

```sql
SELECT id, email, display_name, unsubscribe_token FROM team_recipients WHERE team_id = ?
```

Build unsubscribe URL before sending:

```php
$unsubscribeToken = ensureUnsubscribeToken($pdo, $recipient['id']);
$unsubscribeUrl   = $config['app']['base_url'] . '/unsubscribe.php?token=' . urlencode($unsubscribeToken);
```

Pass `$unsubscribeUrl` to the summary email template.

---

### Summary email template addition (`templates/email/standup_summary.php`)

Add at the bottom of the template body, after the summary content:

```
---
To stop receiving these summaries: <?= $unsubscribe_url ?>
```

`$unsubscribe_url` is a plain-text URL in the template — no HTML encoding needed for plain-text email. The URL contains only URL-safe characters (hex token + standard URL structure).

---

### `public/unsubscribe.php`

**GET**:
```php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Csrf.php';

$token = $_GET['token'] ?? '';
if (empty($token)) { showError('Invalid unsubscribe link.'); exit; }

$stmt = $pdo->prepare(
    'SELECT tr.id, tr.email, tr.display_name, t.name AS team_name, o.name AS org_name
     FROM team_recipients tr
     JOIN teams t        ON t.id = tr.team_id
     JOIN organisations o ON o.id = t.org_id
     WHERE tr.unsubscribe_token = ?'
);
$stmt->execute([$token]);
$recipient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipient) { showError('Invalid unsubscribe link.'); exit; }

// Render confirmation page with team name, org name, and confirm form
```

**POST**:
```php
validateCsrfOrFail();

$token = $_POST['token'] ?? '';
// Re-load recipient (re-validate token on POST)
$stmt = $pdo->prepare('SELECT id FROM team_recipients WHERE unsubscribe_token = ?');
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) { showError('Invalid unsubscribe link.'); exit; }

$pdo->prepare('DELETE FROM team_recipients WHERE id = ?')->execute([$row['id']]);

// Show: "You have been unsubscribed."
```

**Page HTML (no login)** — centred card layout (Tailwind from US-19):

```html
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-8 max-w-md w-full text-center">
        <h1 class="text-xl font-semibold text-gray-900 mb-2">Unsubscribe from summaries</h1>
        <p class="text-sm text-gray-600 mb-6">
            You are unsubscribing from standup summaries for
            <strong><?= h($recipient['team_name']) ?></strong>
            at <strong><?= h($recipient['org_name']) ?></strong>.
        </p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit"
                class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-6 rounded-lg">
                Confirm unsubscribe
            </button>
        </form>
        <p class="text-xs text-gray-400 mt-4">
            This only removes you from this team's summary emails. Your account (if any) is unaffected.
        </p>
    </div>
</body>
```

---

### Profile page — "My summary subscriptions" section (`public/profile.php`)

**Query** (add to GET handler):

```sql
SELECT t.id AS team_id, t.name AS team_name, o.name AS org_name
FROM team_members tm
JOIN teams        t ON t.id = tm.team_id
JOIN organisations o ON o.id = t.org_id
WHERE tm.user_id = ? AND tm.is_recipient = 1
ORDER BY o.name, t.name
```

**Render** (below profile update form, above delete account section):

```html
<?php if (!empty($subscriptions)): ?>
<section class="mt-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-3">My summary subscriptions</h2>
    <p class="text-sm text-gray-500 mb-4">Teams whose standup summaries you receive. Owners control whether you are added.</p>
    <ul class="divide-y divide-gray-100">
        <?php foreach ($subscriptions as $sub): ?>
        <li class="flex items-center justify-between py-3">
            <span class="text-sm text-gray-900">
                <?= h($sub['org_name']) ?> / <?= h($sub['team_name']) ?>
            </span>
            <form method="POST" action="/profile.php?action=unsubscribe_team">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="team_id"   value="<?= (int)$sub['team_id'] ?>">
                <button type="submit"
                    class="text-sm text-red-600 hover:text-red-800 underline bg-transparent border-0 cursor-pointer">
                    Remove me
                </button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
```

**POST handler** (`?action=unsubscribe_team`):

```php
validateCsrfOrFail();
$teamId = (int)($_POST['team_id'] ?? 0);

// Verify user is actually a recipient member of this team (prevent IDOR)
$stmt = $pdo->prepare(
    'SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND is_recipient = 1'
);
$stmt->execute([$teamId, $currentUser['id']]);
if (!$stmt->fetch()) {
    $errors[] = 'Not subscribed to that team.';
} else {
    $pdo->prepare('UPDATE team_members SET is_recipient = 0 WHERE team_id = ? AND user_id = ?')
        ->execute([$teamId, $currentUser['id']]);
    $flash = 'Removed from summary list.';
}
// PRG redirect back to profile
header('Location: /profile.php');
exit;
```

## Security Notes

- **IDOR prevention on profile unsubscribe**: verify `is_recipient = 1` for `(team_id, user_id)` before UPDATE — prevents a crafted POST from toggling someone else's flag
- **Token re-validation on POST**: `unsubscribe.php` re-loads token on POST (not passed through form hidden field alone) — prevents token-substitution attacks; wait, token IS passed as hidden field for UX — re-validate from DB on POST to confirm it still exists
- **No auth on unsubscribe.php**: by design — external recipients have no account; CSRF token still required on confirmation POST (session started at top of page)
- **Lazy token generation**: safe because `UPDATE ... WHERE id = ?` is idempotent and the token is generated with CSPRNG
- **Token in plain-text email**: hex-only characters; safe to include in a plain-text email body URL without further encoding
