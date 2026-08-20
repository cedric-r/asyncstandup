# US-41: API Key Expiry Date

**Status**: APPROVED (autonomous mode)
**Branch**: `feature/us-41-api-key-expiry`
**Dependency**: US-35 must be merged (api_keys table with `name` and `revoked_at` columns)

---

## Story

**As a** team owner  
**I want** to set an optional expiry date when creating an API key  
**So that** I can enforce time-limited access for integrations without manually revoking keys

---

## Acceptance Criteria

### AC-1 — Schema: add `expires_at` to `api_keys`

Add a nullable `expires_at` column to `api_keys`:

```sql
-- db/schema.sql (append)
ALTER TABLE api_keys ADD COLUMN expires_at DATETIME NULL;
```

SQLite (`tests/schema-sqlite.sql`): add `expires_at TEXT NULL` to the `CREATE TABLE api_keys` definition.

---

### AC-2 — `src/ApiAuth.php`: reject expired keys

Update `authenticateApiKey()` to also reject keys past their expiry date:

```sql
WHERE key_hash = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
```

Update the `@return` docblock to include `expires_at: string|null` in the returned array shape.

---

### AC-3 — `src/ApiKeyRepository.php`: expiry support

1. **`createApiKey()`** — accept optional `?string $expiresAt = null` (UTC datetime string `Y-m-d H:i:s` or null). Insert `expires_at` into the `INSERT` statement.

2. **`listApiKeysForUser()`** — include `expires_at` in the `SELECT`. Add a derived boolean field `is_expired` (true if `expires_at` is not null and is in the past) to the returned array shape. Keep returning only non-revoked keys; expired-but-not-revoked keys remain visible so the user can see them in the UI.

3. **`getApiKey()`** (new helper) — fetch a single api_key row by `id` and `user_id` (for UI display after creation).

Return type update for `listApiKeysForUser`:
```php
// Add to each returned row:
'expires_at'  => string|null,   // raw UTC datetime or null
'is_expired'  => bool,          // true if expires_at is in the past
```

---

### AC-4 — UI: API key creation form

File: `public/settings.php` (or wherever the API key management UI lives — locate by searching for the key creation form).

Add an optional **"Expiry date"** date input (`<input type="date">`) to the API key creation form. The field must be clearly labelled as optional. On submit, convert the date to a UTC datetime (`Y-m-d 23:59:59`) before passing to `createApiKey()`. If left blank, pass `null`.

---

### AC-5 — UI: API key list display

In the API key list table, add an **"Expires"** column showing:
- `—` if no expiry set
- The expiry date formatted as `d M Y` if set and not yet expired
- `Expired {date}` (visually distinct — e.g. red text or strikethrough) if the key is expired

---

### AC-6 — Tests: update/add PHPUnit tests

File: `tests/ApiKeyRepositoryTest.php` (create if absent).

Cover:
1. `createApiKey()` with no expiry — `expires_at` is null in DB
2. `createApiKey()` with expiry — `expires_at` stored correctly
3. `listApiKeysForUser()` — `is_expired` is false for a future expiry
4. `listApiKeysForUser()` — `is_expired` is true for a past expiry
5. `authenticateApiKey()` — returns null for an expired key (integration test using SQLite in-memory DB)
6. `authenticateApiKey()` — returns the row for a valid non-expired key

---

## Out of Scope

- No automatic revocation of expired keys (they stay visible in the list)
- No email/notification when a key is about to expire
- No UI to edit the expiry date after creation (revoke and recreate)
