# US-35: API Key Management UI

**Status**: APPROVED (autonomous mode)  
**Branch**: `feature/us-35-api-key-management`  
**Dependency**: US-33 must be merged first — this story adds columns to the `api_keys` table defined in US-33

---

## Story

**As a** team owner  
**I want** a web UI to create, name, and revoke API keys  
**So that** I can manage programmatic access to my standup data without touching the database

---

## Acceptance Criteria

### AC-1 — Schema: extend `api_keys` with `name`, `revoked_at`

US-33 created `api_keys (id, user_id, key_hash, label, created_at, last_used_at)`. This story:

1. Renames `label` → `name` for clarity (or adds `name` if `label` was not implemented yet — confirm by reading `db/schema.sql` after US-33 merges)
2. Adds `revoked_at DATETIME NULL`

```sql
-- db/schema.sql (append — run after US-33 migration)
ALTER TABLE api_keys ADD COLUMN revoked_at DATETIME NULL;
-- If label was used (not name), also:
ALTER TABLE api_keys CHANGE COLUMN label name VARCHAR(100) NOT NULL DEFAULT '';
```

PostgreSQL: `ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMP NULL;`

SQLite (`tests/schema-sqlite.sql`): add `revoked_at TEXT NULL` to `CREATE TABLE api_keys`. SQLite does not support `ALTER TABLE ... CHANGE COLUMN` — update the `CREATE TABLE` definition directly to use `name` instead of `label`.

**`ApiAuth.php` must be updated** to skip revoked keys:
```php
// In authenticateApiKey(), add to WHERE:
WHERE key_hash = ? AND revoked_at IS NULL
```

---

### AC-2 — `src/ApiKeyRepository.php` (new)

```php
<?php
declare(strict_types=1);

/**
 * Generate a new API key, store its hash, and return the plain-text key (shown once).
 */
function createApiKey(PDO $pdo, int $userId, string $name): string
{
    $rawKey  = bin2hex(random_bytes(32));  // 64-char hex
    $keyHash = hash('sha256', $rawKey);

    $pdo->prepare(
        'INSERT INTO api_keys (user_id, key_hash, name, created_at) VALUES (?, ?, ?, ?)'
    )->execute([$userId, $keyHash, trim($name), gmdate('Y-m-d H:i:s')]);

    return $rawKey;  // caller is responsible for showing this once
}

/**
 * Return all non-revoked API keys for a user.
 */
function listApiKeysForUser(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, created_at, last_used_at,
                CONCAT("sk-...", RIGHT(key_hash, 6)) AS masked_key
         FROM api_keys
         WHERE user_id = ? AND revoked_at IS NULL
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Soft-delete an API key. Scoped to user_id to prevent cross-user revocation.
 */
function revokeApiKey(PDO $pdo, int $keyId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE api_keys SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL'
    );
    $stmt->execute([gmdate('Y-m-d H:i:s'), $keyId, $userId]);
    return $stmt->rowCount() > 0;
}
```

Note: SQLite does not support `CONCAT()` or `RIGHT()` — for SQLite compatibility, either:
- Load the full `key_hash` and compute the masked preview in PHP: `'sk-...' . substr($row['key_hash'], -6)`
- Or use a raw query without the computed column and add PHP post-processing

Recommend the PHP post-processing approach to stay portable.

---

### AC-3 — `public/settings/api-keys.php` page

**GET** — list existing keys; show flash message; render "Create" form.  
**POST `action=create`** — validate `name` (required, max 100 chars); call `createApiKey()`; store raw key in flash as `api_key_created`; redirect back.  
**POST `action=revoke`** — validate CSRF; call `revokeApiKey($keyId, $userId)`; redirect with flash.

```php
// After successful create:
setFlash('api_key_created', $rawKey);
header('Location: /settings/api-keys.php');
exit;
```

In the GET render — if `getFlash('api_key_created')` is non-empty, display the key in a highlighted block:
```html
<div class="bg-green-50 border border-green-300 rounded-lg p-4 mb-6">
  <p class="font-semibold text-green-800 mb-1">New API key created — copy it now, it will not be shown again.</p>
  <code class="block text-sm font-mono bg-white border border-green-200 rounded p-2 text-green-900 select-all">
    sk-<?= htmlspecialchars($newKey, ENT_QUOTES, 'UTF-8') ?>
  </code>
</div>
```

Key list table:
```html
<table class="w-full text-sm">
  <thead><tr>
    <th class="text-left font-medium text-gray-600 pb-2">Name</th>
    <th>Key preview</th>
    <th>Created</th>
    <th>Last used</th>
    <th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($keys as $key): ?>
    <tr>
      <td><?= htmlspecialchars($key['name'], ENT_QUOTES) ?></td>
      <td class="font-mono text-gray-500"><?= htmlspecialchars($key['masked_key'], ENT_QUOTES) ?></td>
      <td><?= htmlspecialchars(substr($key['created_at'], 0, 10), ENT_QUOTES) ?></td>
      <td><?= $key['last_used_at'] ? htmlspecialchars(substr($key['last_used_at'], 0, 10), ENT_QUOTES) : '—' ?></td>
      <td>
        <form method="POST" action="/settings/api-keys.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="key_id" value="<?= (int) $key['id'] ?>">
          <button type="submit" class="text-xs text-red-600 hover:text-red-800">Revoke</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
```

---

### AC-4 — `last_used_at` updated on every authenticated request

In `src/ApiAuth.php`, `authenticateApiKey()` already has a `last_used_at` update (added in US-33). Confirm it is:
```php
$pdo->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')
    ->execute([gmdate('Y-m-d H:i:s'), $row['id']]);
```
If not present (US-33 omitted it), add it here.

---

### AC-5 — Navigation: link to API Keys in owner settings

Add "API Keys" link in the navigation bar or settings section accessible to all authenticated users (not only owners — any user can manage their own keys; the page is already scoped by `user_id` in the repository functions).

---

### AC-6 — PHPUnit tests: 4 new tests

New test class `tests/ApiKeyRepositoryTest.php`:

| Test | What it verifies |
|---|---|
| `testCreateApiKeyReturnsPlainTextKey` | `createApiKey()` returns 64-char hex string; `api_keys` table has 1 row with matching SHA-256 hash |
| `testListApiKeysExcludesRevoked` | Insert 2 keys; revoke 1; `listApiKeysForUser()` returns only 1 |
| `testRevokeApiKeySetsRevokedAt` | After `revokeApiKey()`, row has non-null `revoked_at` |
| `testRevokeApiKeyFailsForWrongUser` | `revokeApiKey($keyId, $wrongUserId)` returns `false`; row unchanged |

---

## Files Changed

| File | Change |
|---|---|
| `db/schema.sql` | Append `revoked_at` + `name` migration |
| `db/schema-postgresql.sql` | Add column migrations |
| `tests/schema-sqlite.sql` | Update `CREATE TABLE api_keys` — add `revoked_at`, rename `label` → `name` |
| `src/ApiAuth.php` | Add `AND revoked_at IS NULL` to auth query; confirm `last_used_at` update |
| `src/ApiKeyRepository.php` (new) | `createApiKey()`, `listApiKeysForUser()`, `revokeApiKey()` |
| `public/settings/api-keys.php` (new) | Key management UI |
| `templates/layout.php` or nav partial | Add API Keys link |
| `tests/ApiKeyRepositoryTest.php` (new) | 4 PHPUnit tests |
