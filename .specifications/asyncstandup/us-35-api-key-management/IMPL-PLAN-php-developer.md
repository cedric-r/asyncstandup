# IMPL-PLAN — US-35: API Key Management UI

**Status**: APPROVED
**Branch**: `feature/us-35-api-key-management`
**Agent**: PHP Developer (`fa2e6dbf`)
**Story**: US-35 — API Key Management UI

---

## Scope

All changes within bounds of STORY.md AC-1 through AC-6 and TASKS.md T-1 through T-10.

---

## Pre-implementation findings

| Item | Finding |
|---|---|
| `db/schema.sql` `api_keys` | Uses `label VARCHAR(100) NULL` — NOT `name`. Must rename → `name NOT NULL DEFAULT ''` |
| `tests/schema-sqlite.sql` `api_keys` | Uses `label TEXT NULL`. SQLite cannot RENAME COLUMN via ALTER; update `CREATE TABLE` definition directly |
| `src/ApiAuth.php` `authenticateApiKey()` | Query: `SELECT * FROM api_keys WHERE key_hash = ?` — missing `AND revoked_at IS NULL`. `last_used_at` update IS present (no action needed on that part) |
| `public/profile/api-keys.php` | Exists from US-33. Uses `label` column, `action=generate`, hard `DELETE`. US-35 creates a NEW page at `public/settings/api-keys.php` using `ApiKeyRepository.php`. The old profile page will be kept as-is (not in scope to delete — redirect is optional). |
| `public/settings/` directory | Does not exist — create it |
| `templates/layout.php` nav | Single profile link at line 31: `<a href="/profile.php">`. "API Keys" link to be added adjacent |
| Current test count | 105 tests, 208 assertions |

---

## Files to Change / Create

| File | Change |
|---|---|
| `db/schema.sql` | Append: `ALTER TABLE api_keys CHANGE COLUMN label name VARCHAR(100) NOT NULL DEFAULT '';` + `ALTER TABLE api_keys ADD COLUMN revoked_at DATETIME NULL;` |
| `db/schema-postgresql.sql` | Append: `ALTER TABLE api_keys RENAME COLUMN label TO name;` + `ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMP NULL;` |
| `tests/schema-sqlite.sql` | Edit `CREATE TABLE api_keys` — change `label TEXT NULL` → `name TEXT NOT NULL DEFAULT ''`; add `revoked_at TEXT NULL` |
| `src/ApiAuth.php` | Add `AND revoked_at IS NULL` to `authenticateApiKey()` SELECT; update `@return` docblock tag `label` → `name` |
| `src/ApiKeyRepository.php` (new) | `createApiKey()`, `listApiKeysForUser()` (PHP masked_key, no CONCAT/RIGHT), `revokeApiKey()` |
| `public/settings/api-keys.php` (new) | Full key management UI — GET list + create form + new-key amber block; POST create + revoke |
| `templates/layout.php` | Add "API Keys" `<a>` link adjacent to profile link |
| `tests/ApiKeyRepositoryTest.php` (new) | 4 PHPUnit tests |
| `tests/bootstrap.php` | Add `require_once` for `ApiKeyRepository.php` |

---

## Task Sequence

### T-1 — Branch (done)

`feature/us-35-api-key-management` created from `main`.

---

### T-2 — Schema updates (AC-1)

**`db/schema.sql`** — append after existing content:
```sql
-- US-35: API key management — rename label → name, add soft-delete column
ALTER TABLE api_keys CHANGE COLUMN label name VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE api_keys ADD COLUMN revoked_at DATETIME NULL;
```

**`db/schema-postgresql.sql`** — append:
```sql
-- US-35: API key management
ALTER TABLE api_keys RENAME COLUMN label TO name;
ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMP NULL;
```

**`tests/schema-sqlite.sql`** — edit `CREATE TABLE api_keys` directly:
- `label TEXT NULL` → `name TEXT NOT NULL DEFAULT ''`
- Add `revoked_at TEXT NULL` before the FOREIGN KEY line

Note: SQLite `CREATE TABLE` change invalidates any existing `public/profile/api-keys.php` test that inserts `label` — but that page has no tests, so no breakage.

---

### T-3 — `src/ApiAuth.php` — add `revoked_at IS NULL` (AC-4)

Change:
```sql
SELECT * FROM api_keys WHERE key_hash = ?
```
To:
```sql
SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL
```

Update `@return` docblock: change `label: string|null` → `name: string`.

---

### T-4 — `src/ApiKeyRepository.php` (AC-2)

Three functions:
- `createApiKey(PDO $pdo, int $userId, string $name): string` — `bin2hex(random_bytes(32))`, hash SHA-256, INSERT with `name`; return raw key
- `listApiKeysForUser(PDO $pdo, int $userId): array` — SELECT without `CONCAT`/`RIGHT` (not SQLite-compatible); compute `masked_key = 'sk-...' . substr($key_hash, -6)` in PHP; unset `key_hash` before returning
- `revokeApiKey(PDO $pdo, int $keyId, int $userId): bool` — UPDATE `revoked_at = gmdate(...)` WHERE `id = ? AND user_id = ? AND revoked_at IS NULL`; return `rowCount() > 0`

---

### T-5 + T-6 — `public/settings/api-keys.php` (AC-3)

Create `public/settings/` directory. Implement page with:
- Requires: `config.php`, `Db.php`, `Auth.php`, `Csrf.php`, `ApiKeyRepository.php`
- POST `action=create`: validate name (required, ≤100 chars) → `createApiKey()` → `setFlash('api_key_created', $rawKey)` → redirect
- POST `action=revoke`: validate CSRF → `revokeApiKey()` → flash success → redirect
- GET: `listApiKeysForUser()` → render table; if `getFlash('api_key_created')` non-empty, show green highlighted block (green, not amber — AC-3 spec uses `bg-green-50`)
- Key list table: Name | Key preview (`masked_key`) | Created (date only) | Last used (date or `—`) | Revoke form

The existing `public/profile/api-keys.php` is NOT modified — it's left in place as prior art from US-33.

---

### T-7 — Navigation (AC-5)

In `templates/layout.php`, add "API Keys" link adjacent to the `/profile.php` link (line 31 area). Both visible to authenticated users.

---

### T-8 — `tests/ApiKeyRepositoryTest.php` (AC-6)

4 tests per TASKS.md T-8:
1. `testCreateApiKeyReturnsPlainTextKey` — 64-char return; SHA-256 matches DB row
2. `testListApiKeysExcludesRevoked` — 2 keys, 1 revoked → list returns 1 (the other)
3. `testRevokeApiKeySetsRevokedAt` — `revokeApiKey()` returns `true`; `revoked_at` non-null
4. `testRevokeApiKeyFailsForWrongUser` — returns `false`; `revoked_at` still null

**`tests/bootstrap.php`** — add `require_once` for `ApiKeyRepository.php`.

---

### T-9 — Quality gate

```bash
php83/php.exe tests/phpunit.phar --configuration tests/phpunit.xml
```
Target: ≥109 tests (105 prior + 4 new), all pass.

```bash
php83/php.exe phpstan.phar analyse src/ --level=5
```
Target: 0 errors.

---

### T-10 — Commit

```bash
git add db/schema.sql db/schema-postgresql.sql tests/schema-sqlite.sql \
        src/ApiAuth.php src/ApiKeyRepository.php \
        public/settings/api-keys.php templates/layout.php \
        tests/ApiKeyRepositoryTest.php tests/bootstrap.php \
        .specifications/asyncstandup/us-35-api-key-management/
git commit -m "feat(us-35): API key management UI — create, list, revoke; revoked_at soft-delete"
```

---

## Risk Notes

1. **SQLite `label` → `name`**: `CREATE TABLE` in `tests/schema-sqlite.sql` must be updated directly (no SQLite `CHANGE COLUMN`). Old `public/profile/api-keys.php` inserts into `label` — it will break at runtime against the updated schema, but it has no PHPUnit tests so the test suite is unaffected.
2. **`listApiKeysForUser` portability**: no `CONCAT`/`RIGHT` — masked_key computed in PHP from `key_hash`; `key_hash` unset before return so callers never see the hash.
3. **`testListApiKeysExcludesRevoked` ordering**: `createApiKey()` inserts in order; `listApiKeysForUser()` orders by `created_at DESC`. The second call's key will be index 0. Test must assert `$keys[0]['name'] === 'Another key'` (the non-revoked, later-created key).
4. **`public/profile/api-keys.php` not deleted**: out of scope; will be a dead page after schema rename but has no tests. Clean-up can be a tech-debt ticket.
