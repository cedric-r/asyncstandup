# TASKS — US-35: API Key Management UI

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-35-api-key-management`  
**Agent**: PHP Developer (`fa2e6dbf`)  
**Dependency**: US-33 must be merged first — adds columns to `api_keys` table

---

## Phase 1 — Branch + schema (AC-1)

**T-1** `backend-dev` — Create branch (from main, after US-33 is merged)
```bash
git -C "C:/Users/cedric.raguenaud/Downloads/ai/asyncstandup" checkout -b feature/us-35-api-key-management
```

**T-2** `backend-dev` — Confirm US-33 schema; add `revoked_at` and fix `label` → `name`

First, inspect the `api_keys` definition in `db/schema.sql` (after US-33 merged):
```bash
grep -A 12 "CREATE TABLE.*api_keys" db/schema.sql
```

If `label` column exists: append rename migration + `revoked_at`:
```sql
-- US-35: API key management
ALTER TABLE api_keys CHANGE COLUMN label name VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE api_keys ADD COLUMN revoked_at DATETIME NULL;
```

If `name` already used (US-33 implemented it as `name`): only append:
```sql
ALTER TABLE api_keys ADD COLUMN revoked_at DATETIME NULL;
```

`db/schema-postgresql.sql`:
```sql
ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMP NULL;
-- If renaming: ALTER TABLE api_keys RENAME COLUMN label TO name;
```

`tests/schema-sqlite.sql` — update `CREATE TABLE api_keys` directly:
- Rename `label` → `name` (if present)
- Add `revoked_at TEXT NULL`

**T-3** `backend-dev` — Update `authenticateApiKey()` in `src/ApiAuth.php` to skip revoked keys

Change:
```sql
SELECT * FROM api_keys WHERE key_hash = ?
```
To:
```sql
SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL
```

Confirm `last_used_at` update is present. If missing (US-33 omitted it), add:
```php
$pdo->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')
    ->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
```

---

## Phase 2 — `src/ApiKeyRepository.php` (AC-2)

**T-4** `backend-dev` — Create `src/ApiKeyRepository.php`

Three functions: `createApiKey()`, `listApiKeysForUser()`, `revokeApiKey()` — full implementations from STORY.md AC-2.

**Portability note for `listApiKeysForUser()`**: do not use `CONCAT()` or `RIGHT()` in SQL — not supported in SQLite. Instead:

```php
function listApiKeysForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, key_hash, created_at, last_used_at
         FROM api_keys
         WHERE user_id = ? AND revoked_at IS NULL
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    return array_map(function (array $row): array {
        $row['masked_key'] = 'sk-...' . substr($row['key_hash'], -6);
        unset($row['key_hash']);  // never expose hash to caller
        return $row;
    }, $rows);
}
```

---

## Phase 3 — `public/settings/api-keys.php` page (AC-3)

**T-5** `backend-dev` — Create `public/settings/` directory if it does not exist

Check whether a `public/settings/` directory or `public/profile/` directory already exists. Create the appropriate parent directory.

**T-6** `backend-dev` — Create `public/settings/api-keys.php`

```php
<?php
declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/ApiKeyRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$userId = (int) $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Key name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Key name must be 100 characters or fewer.';
        } else {
            $rawKey = createApiKey($pdo, $userId, $name);
            setFlash('api_key_created', $rawKey);
            header('Location: /settings/api-keys.php');
            exit;
        }
    } elseif ($action === 'revoke') {
        $keyId = (int) ($_POST['key_id'] ?? 0);
        revokeApiKey($pdo, $keyId, $userId);
        setFlash('success', 'API key revoked.');
        header('Location: /settings/api-keys.php');
        exit;
    }
}

$keys        = listApiKeysForUser($pdo, $userId);
$newKey      = getFlash('api_key_created');   // shown once
$flash       = getFlash();
$csrfToken   = generateCsrfToken();
$currentUser = getCurrentUser($pdo);

ob_start();
?>
<!-- [HTML from STORY.md AC-3 — key list table + create form + new-key flash block] -->
<?php
$content   = ob_get_clean();
$pageTitle = 'API Keys';
include __DIR__ . '/../../templates/layout.php';
```

Full HTML: implement key list table, create form, and new-key highlighted block exactly as specified in STORY.md AC-3.

---

## Phase 4 — Navigation (AC-5)

**T-7** `backend-dev` — Add "API Keys" link to user navigation

Inspect `templates/layout.php` (or nav partial) for where user-specific nav links appear. Add:
```html
<a href="/settings/api-keys.php" class="...">API Keys</a>
```

Place next to existing links such as "Profile" or "Settings". Visible to all authenticated users.

---

## Phase 5 — Tests (AC-6)

**T-8** `backend-dev` — Create `tests/ApiKeyRepositoryTest.php` (4 tests)

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/ApiKeyRepository.php';

class ApiKeyRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = createTestPdo();
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (1, 'u@x.com', 'h')");
        $this->pdo->exec("INSERT INTO users (id, email, password_hash) VALUES (2, 'u2@x.com', 'h2')");
    }

    public function testCreateApiKeyReturnsPlainTextKey(): void
    {
        $rawKey = createApiKey($this->pdo, 1, 'Test key');
        $this->assertEquals(64, strlen($rawKey));

        $hash = hash('sha256', $rawKey);
        $row  = $this->pdo->query("SELECT key_hash FROM api_keys WHERE user_id = 1")->fetch();
        $this->assertEquals($hash, $row['key_hash']);
    }

    public function testListApiKeysExcludesRevoked(): void
    {
        createApiKey($this->pdo, 1, 'Active key');
        $keyId = (int) $this->pdo->lastInsertId();
        createApiKey($this->pdo, 1, 'Another key');

        revokeApiKey($this->pdo, $keyId, 1);

        $keys = listApiKeysForUser($this->pdo, 1);
        $this->assertCount(1, $keys);
        $this->assertEquals('Another key', $keys[0]['name']);
    }

    public function testRevokeApiKeySetsRevokedAt(): void
    {
        createApiKey($this->pdo, 1, 'My key');
        $keyId = (int) $this->pdo->lastInsertId();

        $result = revokeApiKey($this->pdo, $keyId, 1);
        $this->assertTrue($result);

        $row = $this->pdo->query("SELECT revoked_at FROM api_keys WHERE id = $keyId")->fetch();
        $this->assertNotNull($row['revoked_at']);
    }

    public function testRevokeApiKeyFailsForWrongUser(): void
    {
        createApiKey($this->pdo, 1, 'User 1 key');
        $keyId = (int) $this->pdo->lastInsertId();

        $result = revokeApiKey($this->pdo, $keyId, 2);  // wrong user
        $this->assertFalse($result);

        $row = $this->pdo->query("SELECT revoked_at FROM api_keys WHERE id = $keyId")->fetch();
        $this->assertNull($row['revoked_at']);
    }
}
```

**T-9** `backend-dev` — Run full test suite; target ≥107 tests (103 prior + 4 new)

---

## Phase 6 — Commit and signal

**T-10** `backend-dev` — Commit
```bash
git add \
  db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
  src/ApiAuth.php src/ApiKeyRepository.php \
  public/settings/api-keys.php \
  templates/layout.php \
  tests/ApiKeyRepositoryTest.php \
  .specifications/asyncstandup/us-35-api-key-management/
git commit -m "feat(us-35): API key management UI — create, list, revoke; revoked_at soft-delete"
```

---

## AC ↔ Task Coverage

| AC | Tasks |
|---|---|
| AC-1 (schema `revoked_at` + rename) | T-2 |
| AC-2 (ApiKeyRepository) | T-4 |
| AC-3 (api-keys.php page) | T-5, T-6 |
| AC-4 (`last_used_at` + revoked filter in ApiAuth) | T-3 |
| AC-5 (nav link) | T-7 |
| AC-6 (4 tests) | T-8, T-9 |

**Estimate**: ~6h total
