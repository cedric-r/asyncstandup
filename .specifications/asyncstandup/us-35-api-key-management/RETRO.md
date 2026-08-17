# RETRO — US-35: API Key Management UI

**Story**: US-35 — API Key Management UI
**Branch**: `feature/us-35-api-key-management`
**Merge commit**: see main after hotfix
**Review cycles**: 1 (+ 1 hotfix)
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `tests/schema-sqlite.sql` | `label TEXT NULL` → `name TEXT NOT NULL DEFAULT ''`; added `revoked_at TEXT NULL` (direct CREATE TABLE rewrite — SQLite has no CHANGE COLUMN) |
| `db/schema.sql` | Appended `ALTER TABLE api_keys CHANGE COLUMN label name` + `ADD COLUMN revoked_at DATETIME NULL` |
| `db/schema-postgresql.sql` | Appended `RENAME COLUMN label TO name` + `ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMP NULL` |
| `src/ApiAuth.php` | Added `AND revoked_at IS NULL` to `authenticateApiKey()` SELECT; docblock updated |
| `src/ApiKeyRepository.php` (new) | `createApiKey()`, `listApiKeysForUser()` (PHP masked_key — no CONCAT/RIGHT), `revokeApiKey()` soft-delete scoped to user_id |
| `public/settings/api-keys.php` (new) | PRG UI — POST create (name validation) + revoke; GET list + one-time green key block |
| `templates/layout.php` | "API Keys" nav link added adjacent to profile link |
| `tests/ApiKeyRepositoryTest.php` (new) | 4 tests — create 64-char, list excludes revoked, revokeApiKey sets revoked_at, wrong user returns false |

**Test result**: 109 tests, 216 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle + 1 hotfix**

---

## Hotfix (post-merge SUGGESTION from Code Reviewer)

**`public/profile/api-keys.php`** replaced with 301 redirect to `/settings/api-keys.php`. The US-33 page used `label` column which was renamed to `name` in this story. Since the old page had no PHPUnit tests, the suite remained green throughout — but the page would crash at runtime on any request. The 4-line redirect prevents a broken user experience and resolves the tech-debt risk flagged in the IMPL-PLAN.

---

## Notes

1. **`label` → `name` schema rename** — `testListApiKeysExcludesRevoked` ordering confirmed: `listApiKeysForUser()` orders by `created_at DESC`; the second-created key (non-revoked) lands at index 0. Test asserts `$keys[0]['name'] === 'Another key'` — passes correctly.
2. **`listApiKeysForUser()` portability** — no `CONCAT`/`RIGHT` in SQL (not available in SQLite). `masked_key = 'sk-...' . substr($key_hash, -6)` computed in PHP; `key_hash` unset before return.
3. **`getFlash()` signature** — `getFlash(): ?array` returns `['type', 'text']`. Used `setFlash('api_key_created', $rawKey)` → on GET: `$flash['type'] === 'api_key_created'` to extract the raw key. Spec's pseudo-code `getFlash('api_key_created')` adapted to the actual function signature.
4. **`public/profile/api-keys.php` not deleted** — replaced with 301 redirect rather than deletion to preserve any existing bookmarks/links.
