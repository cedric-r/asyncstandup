# IMPL-PLAN — PHP Developer
## US-14: Bug Fixes and Improvements

**Status**: APPROVED
**Branch**: `feature/asyncstandup-fixes`
**Agent**: PHP Developer

---

## File List (exhaustive — 6 files)

| Action | File | Path B? | Fix |
|---|---|---|---|
| Modify | `src/SummaryEmailer.php` | ⚠️ Yes | Bug 1 — merge recipient sources + dedup |
| Modify | `src/StandupEmailer.php` | ⚠️ Yes | Feature 2 — add $org_name to template vars |
| Modify | `public/submit.php` | ⚠️ Yes | Feature 2 — load + display org+team name |
| Modify | `templates/email/standup_prompt.php` | ⚠️ Yes | Feature 2 — org+team in body |
| Modify | `cron/send_standups.php` | ⚠️ Yes | Feature 3 weekend skip + Bug 4 double load |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us14.md` | No — this file |

**Note on `src/StandupEmailer.php`**: `sendStandupPrompt()` needs `$org_name` added to its template vars array. `getOrgById()` already exists in `OrgRepository.php` (loaded by the caller chain via cron). This file was not in the initial Team Lead brief but is required — included here per the process note in the brief.

**All modifications are purely additive / targeted** — no existing logic removed except the orphan `require_once` in cron.

---

## New function in `src/SummaryEmailer.php`

```php
function getMergedRecipients(PDO $pdo, int $teamId): array
```
Unions external recipients (`team_recipients`) and member recipients (`team_members WHERE is_recipient=1 JOIN users`).
Deduplicates by `strtolower(trim($email))` — case-insensitive.
Returns `[['email' => string, 'display_name' => string|null], ...]`.

---

## Commit Sequence (single implementation commit)

```
fix(us-14): Bug 1 recipient merge, Feature 2 org/team context, Feature 3 weekend skip, Bug 4 double config load
```

All 5 files committed together — they are independent fixes but ship as one atomic delivery.

---

## Implementation Notes

- **Bug 4**: Remove `require_once __DIR__ . '/../config/config.php'` on line 22 of cron script. Keep only `$config = require ...` which captures the returned array.
- **Feature 3**: `(int) $nowLocal->format('N')` — ISO day-of-week; 6=Sat, 7=Sun; placed before any email logic in the team loop.
- **Feature 2 submit.php**: `require_once OrgRepository.php` + `require_once src/View.php`; call `getOrgById()`; display `h($org['name'])` and `h($team['name'])` above the form.
- **Feature 2 standup_prompt.php**: `$org_name` added as new template variable; displayed in body.
- **No new DB tables or schema changes**.

---

## Self-Check

- [ ] `getMergedRecipients()` deduplication uses `strtolower(trim($email))`
- [ ] Weekend check uses `format('N')` not `format('w')`
- [ ] `continue` for days 6 and 7 placed before prompt AND summary pass
- [ ] Only one `$config = require ...` in cron — orphan `require_once` removed
- [ ] `h()` used for org/team name on submit.php (requires `src/View.php`)
- [ ] `$org_name` added to `sendStandupPrompt()` template vars
- [ ] All Path B modifications are minimal and non-breaking
- [ ] 33 existing tests still pass
