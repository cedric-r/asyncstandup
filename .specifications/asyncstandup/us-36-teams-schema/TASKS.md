# TASKS — US-36: Teams Schema & Per-Team Mode Selector

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-36-teams-schema`  
**Agent**: PHP Developer (`fa2e6dbf`)

---

## Phase 1 — Branch + schema (AC-1, AC-2)

**T-1** `backend-dev` — Create branch
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout main && git pull && git checkout -b feature/us-36-teams-schema
```

**T-2** `backend-dev` — Append to `db/schema.sql`
```sql
-- US-36: MS Teams integration — teams columns
ALTER TABLE teams ADD COLUMN notification_channel   VARCHAR(10)  NOT NULL DEFAULT 'email';
ALTER TABLE teams ADD COLUMN teams_webhook_url      VARCHAR(500) NULL;
ALTER TABLE teams ADD COLUMN teams_channel_name     VARCHAR(100) NULL;
ALTER TABLE teams ADD COLUMN teams_conversation_ref TEXT         NULL;

-- US-36: MS Teams integration — users columns
ALTER TABLE users ADD COLUMN teams_aad_id           VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN teams_conversation_ref TEXT         NULL;
```

**T-3** `backend-dev` — Append to `db/schema-postgresql.sql`
```sql
-- US-36
ALTER TABLE teams ADD COLUMN IF NOT EXISTS notification_channel   VARCHAR(10)  NOT NULL DEFAULT 'email';
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_webhook_url      VARCHAR(500) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_channel_name     VARCHAR(100) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_conversation_ref TEXT         NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS teams_aad_id           VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS teams_conversation_ref TEXT         NULL;
```

**T-4** `backend-dev` — Update `tests/schema-sqlite.sql`

In `CREATE TABLE teams (...)`, add before the closing `)`:
```sql
notification_channel   TEXT    NOT NULL DEFAULT 'email',
teams_webhook_url      TEXT    NULL,
teams_channel_name     TEXT    NULL,
teams_conversation_ref TEXT    NULL,
```

In `CREATE TABLE users (...)`, add before the closing `)`:
```sql
teams_aad_id           TEXT NULL,
teams_conversation_ref TEXT NULL,
```

---

## Phase 2 — `src/TeamRepository.php`: extend `updateTeam()` (AC-3)

**T-5** `backend-dev` — Inspect current `updateTeam()` signature and SQL

```bash
grep -n "function updateTeam" "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup/src/TeamRepository.php"
```

**T-6** `backend-dev` — Add Teams fields to `updateTeam()`

Add three new parameters (with defaults to avoid breaking existing callers):
```php
function updateTeam(
    PDO    $pdo,
    int    $teamId,
    string $name,
    string $timezone,
    string $standupTime,
    int    $summaryToAll,
    string $frequency,
    ?int   $frequencyDay,
    string $notificationChannel = 'email',      // NEW
    ?string $teamsWebhookUrl    = null,          // NEW
    ?string $teamsChannelName   = null           // NEW
): array|false {
    // Validate notification_channel
    $validChannels = ['email', 'teams', 'teams-summary'];
    if (!in_array($notificationChannel, $validChannels, true)) {
        return false;  // caller checks for false
    }
    // Validate webhook URL when required
    if (in_array($notificationChannel, ['teams', 'teams-summary'], true)) {
        $url = trim((string) $teamsWebhookUrl);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
            return false;
        }
        $teamsWebhookUrl = $url;
    } else {
        $teamsWebhookUrl = null;
    }
    $teamsChannelName = $teamsChannelName !== null ? mb_substr(trim($teamsChannelName), 0, 100) : null;

    $pdo->prepare('
        UPDATE teams SET
            name = ?, timezone = ?, standup_time = ?, summary_to_all_developers = ?,
            frequency = ?, frequency_day = ?,
            notification_channel = ?, teams_webhook_url = ?, teams_channel_name = ?
        WHERE id = ?
    ')->execute([
        $name, $timezone, $standupTime, $summaryToAll,
        $frequency, $frequencyDay,
        $notificationChannel, $teamsWebhookUrl, $teamsChannelName,
        $teamId,
    ]);
    return getTeamById($pdo, $teamId);
}
```

---

## Phase 3 — `public/teams/edit.php` UI (AC-4)

**T-7** `backend-dev` — Read current `public/teams/edit.php` to understand existing form structure

```bash
cat "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup/public/teams/edit.php"
```

**T-8** `backend-dev` — Add notification channel radio group + Teams fields to edit form

Add after the frequency section, before the submit button:

```html
<fieldset class="mt-6">
  <legend class="text-sm font-medium text-gray-700 mb-2">Notification Channel</legend>
  <div class="space-y-2">
    <?php foreach (['email' => 'Email (default)', 'teams' => 'Teams DM + Channel Summary', 'teams-summary' => 'Teams Channel Summary only (no bot)'] as $val => $label): ?>
    <label class="flex items-center gap-2 text-sm">
      <input type="radio" name="notification_channel" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"
        <?= ($team['notification_channel'] ?? 'email') === $val ? 'checked' : '' ?>>
      <?= htmlspecialchars($label, ENT_QUOTES) ?>
    </label>
    <?php endforeach; ?>
  </div>
</fieldset>

<div id="teams-fields" class="mt-4 space-y-4" style="display:none">
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Incoming Webhook URL <span class="text-red-500">*</span></label>
    <input type="url" name="teams_webhook_url" maxlength="500"
           value="<?= htmlspecialchars($team['teams_webhook_url'] ?? '', ENT_QUOTES) ?>"
           class="w-full border rounded px-3 py-2 text-sm font-mono"
           placeholder="https://xxxxxxx.webhook.office.com/webhookb2/...">
    <p class="text-xs text-gray-500 mt-1">Found in Teams → channel → Connectors → Incoming Webhook</p>
  </div>
  <div>
    <label class="block text-sm font-medium text-gray-700 mb-1">Channel name <span class="text-gray-400">(display only)</span></label>
    <input type="text" name="teams_channel_name" maxlength="100"
           value="<?= htmlspecialchars($team['teams_channel_name'] ?? '', ENT_QUOTES) ?>"
           class="w-full border rounded px-3 py-2 text-sm"
           placeholder="e.g. #standup-alerts">
  </div>
</div>

<script>
(function () {
  var fields = document.getElementById('teams-fields');
  function update() {
    var sel = document.querySelector('input[name="notification_channel"]:checked');
    fields.style.display = (sel && (sel.value === 'teams' || sel.value === 'teams-summary')) ? '' : 'none';
  }
  document.querySelectorAll('input[name="notification_channel"]').forEach(function (r) {
    r.addEventListener('change', update);
  });
  update();
}());
</script>
```

**T-9** `backend-dev` — Update POST handler in `edit.php` to pass new params to `updateTeam()`

```php
$channel     = $_POST['notification_channel'] ?? 'email';
$webhookUrl  = trim($_POST['teams_webhook_url'] ?? '');
$channelName = trim($_POST['teams_channel_name'] ?? '');

$result = updateTeam($pdo, $teamId, $name, $tz, $standupTime, $summaryToAll, $frequency, $freqDay,
                     $channel, $webhookUrl ?: null, $channelName ?: null);

if ($result === false) {
    $errors[] = 'Invalid notification channel or missing/invalid webhook URL for Teams mode.';
}
```

---

## Phase 4 — Tests (AC-5)

**T-10** `backend-dev` — Create `tests/TeamsSchemaTest.php` (3 tests)

```php
class TeamsSchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        $this->pdo->exec("INSERT INTO organisations (id, name) VALUES (1, 'Org')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'o@x.com', 'h')");
        $this->pdo->exec("INSERT INTO teams (id, org_id, name, timezone, standup_time, created_by)
                          VALUES (1, 1, 'T', 'UTC', '09:00', 1)");
    }

    public function testDefaultNotificationChannelIsEmail(): void
    {
        $team = $this->pdo->query("SELECT notification_channel FROM teams WHERE id = 1")->fetch();
        $this->assertEquals('email', $team['notification_channel']);
    }

    public function testTeamStoresWebhookUrl(): void
    {
        $result = updateTeam($this->pdo, 1, 'T', 'UTC', '09:00', 0, 'daily', null,
            'teams-summary', 'https://outlook.webhook.office.com/xxx', '#standup');
        $this->assertNotFalse($result);
        $row = $this->pdo->query("SELECT notification_channel, teams_webhook_url FROM teams WHERE id = 1")->fetch();
        $this->assertEquals('teams-summary', $row['notification_channel']);
        $this->assertEquals('https://outlook.webhook.office.com/xxx', $row['teams_webhook_url']);
    }

    public function testUpdateTeamRejectsInvalidChannel(): void
    {
        $result = updateTeam($this->pdo, 1, 'T', 'UTC', '09:00', 0, 'daily', null, 'slack');
        $this->assertFalse($result);
        $row = $this->pdo->query("SELECT notification_channel FROM teams WHERE id = 1")->fetch();
        $this->assertEquals('email', $row['notification_channel']); // unchanged
    }
}
```

**T-11** `backend-dev` — Run full test suite; target ≥112 tests (109 prior + 3 new)

---

## Phase 5 — Commit and signal

**T-12** `backend-dev` — Commit
```bash
git add \
  db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
  src/TeamRepository.php public/teams/edit.php \
  tests/TeamsSchemaTest.php \
  .specifications/asyncstandup/us-36-teams-schema/
git commit -m "feat(us-36): Teams schema — notification_channel, webhook_url, aad_id, mode selector UI"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (teams columns) | T-2, T-3, T-4 |
| AC-2 (users columns) | T-2, T-3, T-4 |
| AC-3 (updateTeam) | T-5, T-6 |
| AC-4 (edit.php UI) | T-7, T-8, T-9 |
| AC-5 (3 tests) | T-10, T-11 |

**Estimate**: ~4h
