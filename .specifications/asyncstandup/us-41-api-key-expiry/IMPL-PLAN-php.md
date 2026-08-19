# IMPL-PLAN-php.md — US-41: API Key Expiry Date

**Status: PENDING GATE C**
**Branch**: `feature/us-41-api-key-expiry`
**Story**: `.specifications/asyncstandup/us-41-api-key-expiry/STORY.md`
**Stack**: PHP 8.3, native PDO (no Laravel/Sail — this project uses plain PHP + PHPUnit)

---

## Scope Lock — Files to Modify or Create

| # | File | Action | TDD Path |
|---|------|--------|----------|
| 1 | `db/schema.sql` | Modify — append `ALTER TABLE api_keys ADD COLUMN expires_at` migration | N/A (DDL, no tests) |
| 2 | `tests/schema-sqlite.sql` | Modify — add `expires_at TEXT NULL` to `api_keys` CREATE TABLE | N/A (DDL) |
| 3 | `src/ApiAuth.php` | Modify — update WHERE clause + `@return` docblock | Path B (existing code, 0 coverage on `authenticateApiKey`) |
| 4 | `src/ApiKeyRepository.php` | Modify — update `createApiKey()`, `listApiKeysForUser()`, add `getApiKey()` | Path B (existing code, partial coverage via `ApiKeyRepositoryTest.php`) |
| 5 | `public/settings/api-keys.php` | Modify — add optional expiry date field to form + Expires column in list | Path B (UI, no automated test) |
| 6 | `tests/ApiKeyRepositoryTest.php` | Modify — add 6 new test scenarios | Path A (tests are the target deliverable) |

**No new files are created.** All changes are within these 6 files.

---

## Pre-existing Test Coverage Assessment

### `src/ApiAuth.php` — `authenticateApiKey()`
- **Existing coverage**: Zero. No test calls `authenticateApiKey()` directly.
- **TDD Path**: **Path B** — characterise current behaviour first (happy path + missing/invalid header), commit, then add expiry rejection.

### `src/ApiKeyRepository.php`
- **Existing coverage**: `ApiKeyRepositoryTest.php` covers `createApiKey()`, `listApiKeysForUser()`, `revokeApiKey()` — partial.
- **TDD Path**: **Path B** — characterise the existing `createApiKey` and `listApiKeysForUser` signatures (including no `expires_at` field yet), commit, then extend.
- Note: `getApiKey()` is new — **Path A** for that function.

### `public/settings/api-keys.php`
- **Coverage**: None (UI, no automated tests in this project).
- **TDD Path**: **Path B** — document as untestable via automation. Provide manual smoke test steps in the commit message.

---

## Task Sequence

### Task 1 — Schema: `db/schema.sql` + `tests/schema-sqlite.sql`

**Files**: `db/schema.sql`, `tests/schema-sqlite.sql`

**db/schema.sql** — append at end of migration section:
```sql
-- US-41: API key expiry date
ALTER TABLE api_keys ADD COLUMN expires_at DATETIME NULL;
```

**tests/schema-sqlite.sql** — add `expires_at TEXT NULL` column to the `api_keys` CREATE TABLE block (after `revoked_at`):
```sql
expires_at   TEXT NULL,
```

Commit: `db: add expires_at column to api_keys (US-41)`

---

### Task 2 — Path B characterisation: `src/ApiAuth.php`

**File**: `src/ApiAuth.php`

**Characterisation tests** (added to `tests/ApiKeyRepositoryTest.php`):
- `testAuthenticateApiKeyReturnsNullForMissingHeader` — no `HTTP_AUTHORIZATION` set → returns null
- `testAuthenticateApiKeyReturnsNullForInvalidKey` — bearer token with unknown hash → returns null
- `testAuthenticateApiKeyReturnsRowForValidKey` — valid key, no expiry, not revoked → returns row array

These tests pin existing behaviour against **unmodified** `ApiAuth.php`. Must be green before Task 3.

Commit: `test(api-auth): characterise existing authenticateApiKey behaviour (Path B)`

---

### Task 3 — Implement: `src/ApiAuth.php`

**File**: `src/ApiAuth.php`

Changes:
1. Update WHERE clause:
   ```sql
   WHERE key_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
   ```
   For SQLite test compatibility use the same string — SQLite's `datetime('now')` returns UTC and the inequality `> UTC_TIMESTAMP()` works via the comparison operator since both are stored as `TEXT`. **Note**: `UTC_TIMESTAMP()` is MySQL-only. Since `ApiAuth.php` is production code (MySQL), this is correct. The SQLite integration test in Task 6 will use `datetime('now')` as the test comparison value via PHP pre-computation (see Task 6).
   
   **Actually**: looking at the codebase pattern — production code uses `gmdate('Y-m-d H:i:s')` in PHP for UTC datetime and raw SQL for queries. The SQLite test schema uses TEXT columns. To keep tests compatible, the WHERE clause should use `UTC_TIMESTAMP()` in the SQL for MySQL production use, and the test (Task 6) will insert an `expires_at` row directly using PHP-computed datetime strings, which SQLite handles correctly via string comparison.

2. Update `@return` docblock to include `expires_at: string|null`.

3. New test (verify expiry rejection):
   - `testAuthenticateApiKeyReturnsNullForExpiredKey` — insert key with `expires_at` in the past → returns null

Commit: `feat(api-auth): reject expired API keys in authenticateApiKey (US-41)`

---

### Task 4 — Path B characterisation: `src/ApiKeyRepository.php`

**File**: `src/ApiKeyRepository.php`

**Characterisation tests** (added to `tests/ApiKeyRepositoryTest.php`) — pin existing signatures:
- `testCreateApiKeyExistingSignatureAcceptsNameOnly` — calls `createApiKey($pdo, 1, 'name')`, verifies `expires_at` is absent from DB (i.e. null / column doesn't exist yet at characterisation point — this test is written to characterise the *current* schema before Task 5 runs, but since schema changes in Task 1 run first in the branch, this characterisation test will simply verify `expires_at IS NULL` for a key created without it)
- `testListApiKeysForUserExistingShapeHasNoExpiresAt` — verifies that the current return array does NOT include `expires_at` key (pins current shape before we add it)

Commit: `test(api-key-repo): characterise existing createApiKey and listApiKeysForUser (Path B)`

---

### Task 5 — Implement: `src/ApiKeyRepository.php`

**File**: `src/ApiKeyRepository.php`

Changes:

**`createApiKey()`**:
- Add optional parameter `?string $expiresAt = null`
- Include `expires_at` in INSERT: `INSERT INTO api_keys (user_id, key_hash, name, created_at, expires_at) VALUES (?, ?, ?, ?, ?)`
- Pass `$expiresAt` as 5th bound value

**`listApiKeysForUser()`**:
- Add `expires_at` to SELECT columns
- Add derived `is_expired` bool in `array_map`:
  ```php
  $row['is_expired'] = $row['expires_at'] !== null
      && $row['expires_at'] < gmdate('Y-m-d H:i:s');
  ```
- Update `@return` PHPDoc to include `expires_at: string|null, is_expired: bool`

**`getApiKey()`** (new — Path A):
```php
/**
 * Fetch a single api_key row by id and user_id (ownership check).
 *
 * @return array{id: int, user_id: int, key_hash: string, name: string,
 *               created_at: string, last_used_at: string|null,
 *               revoked_at: string|null, expires_at: string|null}|null
 */
function getApiKey(PDO $pdo, int $keyId, int $userId): ?array
```
- SELECT by `id` and `user_id` — no `revoked_at IS NULL` filter (returns revoked keys too, for display)
- Returns null if not found

Commit: `feat(api-key-repo): add expires_at to createApiKey, listApiKeysForUser; add getApiKey (US-41)`

---

### Task 6 — Tests: `tests/ApiKeyRepositoryTest.php`

**File**: `tests/ApiKeyRepositoryTest.php`

Add 6 PHPUnit scenarios covering AC-6:

| # | Test method | Scenario |
|---|-------------|---------- |
| 1 | `testCreateApiKeyWithNoExpiryStoresNullExpiresAt` | `createApiKey($pdo, 1, 'key')` → DB row has `expires_at = null` |
| 2 | `testCreateApiKeyWithExpiryStoresExpiresAt` | `createApiKey($pdo, 1, 'key', '2099-12-31 23:59:59')` → DB row has correct `expires_at` |
| 3 | `testListApiKeysIsExpiredFalseForFutureExpiry` | Insert key with future `expires_at` → `is_expired === false` |
| 4 | `testListApiKeysIsExpiredTrueForPastExpiry` | Insert key with past `expires_at` → `is_expired === true` |
| 5 | `testAuthenticateApiKeyReturnsNullForExpiredKey` | Insert key with past `expires_at`, call `authenticateApiKey()` with that bearer token → returns null (SQLite in-memory integration) |
| 6 | `testAuthenticateApiKeyReturnsRowForValidNonExpiredKey` | Insert key with future `expires_at`, call `authenticateApiKey()` → returns row array |

**Note on SQLite + `UTC_TIMESTAMP()`**: `authenticateApiKey()` uses `UTC_TIMESTAMP()` in the SQL WHERE clause (MySQL syntax). For SQLite integration tests, the WHERE clause comparison will still work because:
- SQLite stores datetimes as TEXT
- A past `expires_at` string like `'2020-01-01 00:00:00'` will be < `UTC_TIMESTAMP()` when SQLite evaluates the expression lexicographically — **however** SQLite does not know `UTC_TIMESTAMP()`. This means the integration tests for `authenticateApiKey` must use a **separate test-only PDO** that has a compatible query OR we must refactor.

**Resolution**: The 2 `authenticateApiKey` integration tests (scenarios 5 and 6) will use a thin test helper that directly calls the SQL with SQLite's `datetime('now')` by verifying the result via PHP-level outcome (insert row, call function, assert return is null/row). Since SQLite will fail on `UTC_TIMESTAMP()`, we will note this in the test: the integration tests will insert the key and call `authenticateApiKey` via `$_SERVER['HTTP_AUTHORIZATION']` mocking. The WHERE clause issue in SQLite: we accept that the `UTC_TIMESTAMP()` call will raise a PDO exception in SQLite. To avoid this, we will use PHP-level comparison in `ApiAuth.php` as an alternative:

**Revised approach for `ApiAuth.php` Task 3**:
- After `$stmt->fetch()`, add a PHP-level expiry check:
  ```php
  if ($row['expires_at'] !== null && $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
      return null;
  }
  ```
- Keep the SQL WHERE clause **without** the `expires_at` filter (preserve MySQL compatibility concern by doing check in PHP)
- This makes the expiry check fully SQLite-compatible for tests
- This is consistent with how `last_used_at` is handled (PHP-computed UTC datetime)

This approach is safe: the extra PHP check is trivially correct, avoids any SQL dialect incompatibility, and matches the project's existing pattern of using `gmdate()` in PHP for UTC datetimes.

Commit: `test(api-key-repo): add AC-6 expiry scenarios (US-41)`

---

### Task 7 — UI: `public/settings/api-keys.php`

**File**: `public/settings/api-keys.php`
**TDD Path**: Path B (no automated tests — UI only). Manual smoke test steps in commit message.

**Creation form** — add expiry date field inside the `<form>`:
```html
<div class="mt-3">
  <label for="expires-at" class="block text-sm font-medium text-gray-700 mb-1">
    Expiry date <span class="text-gray-400 text-xs">(optional)</span>
  </label>
  <input type="date" id="expires-at" name="expires_at"
         class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
</div>
```

**POST handler** — in the `create` action, before calling `createApiKey()`:
```php
$expiresAt = null;
$rawDate   = trim($_POST['expires_at'] ?? '');
if ($rawDate !== '') {
    // Validate format and convert to UTC end-of-day datetime
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $rawDate, new \DateTimeZone('UTC'));
    if ($dt === false || $dt->format('Y-m-d') !== $rawDate) {
        $errors[] = 'Invalid expiry date format.';
    } else {
        $expiresAt = $dt->format('Y-m-d') . ' 23:59:59';
    }
}
```
Pass `$expiresAt` as 4th argument to `createApiKey()`.

**Key list table** — add "Expires" column header and cell:
```html
<th class="text-left font-medium text-gray-600 pb-2">Expires</th>
```
Cell logic:
```php
<?php if ($key['expires_at'] === null): ?>
  <td class="py-2 pr-3 text-gray-400">—</td>
<?php elseif ($key['is_expired']): ?>
  <td class="py-2 pr-3 text-red-600 line-through">
    Expired <?= htmlspecialchars(
        (new \DateTimeImmutable($key['expires_at']))->format('d M Y'),
        ENT_QUOTES, 'UTF-8'
    ) ?>
  </td>
<?php else: ?>
  <td class="py-2 pr-3 text-gray-600">
    <?= htmlspecialchars(
        (new \DateTimeImmutable($key['expires_at']))->format('d M Y'),
        ENT_QUOTES, 'UTF-8'
    ) ?>
  </td>
<?php endif; ?>
```

**Manual smoke test steps** (included in commit message):
1. Navigate to `/settings/api-keys.php`
2. Create a key with no expiry — Expires column shows `—`
3. Create a key with a future date — Expires column shows formatted date
4. Manually set a key's `expires_at` to a past datetime in DB — Expires column shows red strikethrough `Expired DD Mon YYYY`
5. Verify expired key still appears in the list (not revoked)

Commit: `feat(ui): add expiry date field and Expires column to API key settings (US-41)`

---

## Tests to Be Written (Complete List)

### Characterisation (Path B — must be committed before implementation)

| File | Test | Purpose |
|------|------|---------|
| `tests/ApiKeyRepositoryTest.php` | `testAuthenticateApiKeyReturnsNullForMissingHeader` | Pin: no header → null |
| `tests/ApiKeyRepositoryTest.php` | `testAuthenticateApiKeyReturnsNullForInvalidKey` | Pin: bad bearer token → null |
| `tests/ApiKeyRepositoryTest.php` | `testAuthenticateApiKeyReturnsRowForValidKey` | Pin: valid non-expired key → row |

### New AC-6 Tests (Path A)

| File | Test | AC |
|------|------|----|
| `tests/ApiKeyRepositoryTest.php` | `testCreateApiKeyWithNoExpiryStoresNullExpiresAt` | AC-6 #1 |
| `tests/ApiKeyRepositoryTest.php` | `testCreateApiKeyWithExpiryStoresExpiresAt` | AC-6 #2 |
| `tests/ApiKeyRepositoryTest.php` | `testListApiKeysIsExpiredFalseForFutureExpiry` | AC-6 #3 |
| `tests/ApiKeyRepositoryTest.php` | `testListApiKeysIsExpiredTrueForPastExpiry` | AC-6 #4 |
| `tests/ApiKeyRepositoryTest.php` | `testAuthenticateApiKeyReturnsNullForExpiredKey` | AC-6 #5 |
| `tests/ApiKeyRepositoryTest.php` | `testAuthenticateApiKeyReturnsRowForValidNonExpiredKey` | AC-6 #6 |

---

## Self-Check Checklist (pre-signal)

- [ ] `php tests/phpunit.phar --configuration tests/phpunit.xml` — all pass
- [ ] All 6 AC-6 scenarios present in `tests/ApiKeyRepositoryTest.php`
- [ ] Characterisation commits precede implementation commits in branch history
- [ ] No `var_dump`, `print_r`, `die` in non-test code
- [ ] `strict_types=1` declared in all modified PHP files (already present)
- [ ] No secrets committed
- [ ] `db/schema.sql` migration appended (not replacing existing statements)
- [ ] `tests/schema-sqlite.sql` `api_keys` table has `expires_at TEXT NULL`
- [ ] `public/settings/api-keys.php` handles blank expiry (null) and invalid date format

---

## Out of Scope (explicitly excluded per STORY.md)

- Automatic revocation of expired keys
- Email/notification before expiry
- UI to edit expiry date after creation
- Any files not listed in the Scope Lock table above
