# IMPL-PLAN — PHP Developer
## US-20: Recipient Self-Service (Unsubscribe)

**Status**: APPROVED
**Branch**: `feature/asyncstandup-unsubscribe`
**Agent**: PHP Developer

---

## File List (exhaustive — 9 files)

| Action | File | Path B? |
|---|---|---|
| Create | `public/unsubscribe.php` | No |
| Modify | `db/schema.sql` | ⚠️ Yes — additive: ADD COLUMN |
| Modify | `tests/schema-sqlite.sql` | ⚠️ Yes — mirror db/schema.sql change (US-17 lesson) |
| Modify | `src/SummaryEmailer.php` | ⚠️ Yes — `ensureUnsubscribeToken()` + unsubscribe_url |
| Modify | `templates/email/standup_summary.php` | ⚠️ Yes — append unsubscribe link |
| Modify | `public/profile.php` | ⚠️ Yes — subscriptions section |
| Modify | `public/teams/recipients.php` | ⚠️ Yes — generate token on add |
| Modify | `tests/RepositoryTest.php` | ⚠️ Yes — pre-listed: tests for `ensureUnsubscribeToken()` |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us20.md` | No — this file |

**`tests/schema-sqlite.sql` is pre-listed** per US-17 RETRO lesson: whenever `db/schema.sql` is modified, the test schema must follow.

---

## New Function in `src/SummaryEmailer.php`

```php
function ensureUnsubscribeToken(PDO $pdo, int $recipientId): string
```
Checks `team_recipients.unsubscribe_token` for a given row. If NULL, generates `bin2hex(random_bytes(32))`, UPDATEs the row, and returns the token. If already set, returns it. Pure lazy-generation — idempotent.

---

## Schema Change

`db/schema.sql` and `tests/schema-sqlite.sql`:
- `team_recipients` table: add `unsubscribe_token VARCHAR(64) NULL UNIQUE`
- ALTER TABLE for existing deployments: `ALTER TABLE team_recipients ADD COLUMN unsubscribe_token VARCHAR(64) NULL UNIQUE;`

---

## Test Plan — 3 cases in `tests/RepositoryTest.php`

| # | Method | Setup | Expected |
|---|---|---|---|
| 1 | `testEnsureUnsubscribeTokenGeneratesWhenNull` | Seed recipient with no token | Returns 64-char hex string; DB row updated |
| 2 | `testEnsureUnsubscribeTokenReturnExistingToken` | Seed recipient with token = 'abc123...' | Returns 'abc123...' unchanged; no DB update |
| 3 | `testEnsureUnsubscribeTokenIsIdempotent` | Call twice on same row with no token | Both calls return same token |

---

## `unsubscribe.php` flow

- `startSession()` at top; NO `requireLogin()`
- `require_once OrgRepository.php` for org name lookup
- GET: `SELECT tr.*, t.name AS team_name, o.name AS org_name FROM team_recipients tr JOIN teams t ON t.id=tr.team_id JOIN organisations o ON o.id=t.org_id WHERE tr.unsubscribe_token = ?` → 404 on not found → confirm form
- POST: CSRF validate → re-fetch by token → DELETE → show "You have been unsubscribed."
- Tailwind layout (CDN from layout.php); `require_once View.php` for `h()`
- `htmlspecialchars()` on token in hidden field

---

## `profile.php` subscriptions section flow

New POST action `'unsubscribe_team'`:
1. CSRF validate
2. `SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND is_recipient = 1` → error if not found (IDOR guard)
3. `UPDATE team_members SET is_recipient = 0 WHERE team_id = ? AND user_id = ?`
4. `setFlash('success', 'Removed from summary list.')` → PRG redirect

Subscriptions query for GET render:
```sql
SELECT t.id AS team_id, t.name AS team_name, o.name AS org_name
FROM team_members tm JOIN teams t ON t.id=tm.team_id JOIN organisations o ON o.id=t.org_id
WHERE tm.user_id = ? AND tm.is_recipient = 1
ORDER BY o.name, t.name
```

---

## Self-Check

- [ ] `bin2hex(random_bytes(32))` for token generation (not `rand()`)
- [ ] `ensureUnsubscribeToken()` called before sending to each recipient in `sendSummaryEmail()`
- [ ] `unsubscribe.php` starts session but does NOT call `requireLogin()`
- [ ] CSRF on `unsubscribe.php` POST and profile `unsubscribe_team` POST
- [ ] Token re-fetched from DB on `unsubscribe.php` POST (not only from hidden field)
- [ ] IDOR guard on profile `unsubscribe_team`: verify `is_recipient = 1` for `(team_id, user_id)` before UPDATE
- [ ] `htmlspecialchars()` on token in email template and unsubscribe form hidden field
- [ ] `tests/schema-sqlite.sql` updated (mirrors `db/schema.sql`)
- [ ] 3 test cases for `ensureUnsubscribeToken()` in `RepositoryTest.php`
- [ ] 48 existing tests still pass
