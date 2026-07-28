# IMPL-PLAN — PHP Developer
## US-21: "Send Summary to All Developers" Team Setting

**Status**: APPROVED
**Branch**: `feature/asyncstandup-summary-all`
**Agent**: PHP Developer

---

## File List (exhaustive — 8 files)

| Action | File | Path B? |
|---|---|---|
| Modify | `db/schema.sql` | ⚠️ Yes — ADD COLUMN |
| Modify | `tests/schema-sqlite.sql` | ⚠️ Yes — mirror schema change |
| Modify | `src/SummaryEmailer.php` | ⚠️ Yes — signature change + developer source |
| Modify | `src/TeamRepository.php` | ⚠️ Yes — updateTeam() includes new field |
| Modify | `public/teams/edit.php` | ⚠️ Yes — checkbox UI |
| Modify | `cron/send_standups.php` | ⚠️ Yes — verify SELECT * covers new column |
| Modify | `tests/RepositoryTest.php` | ⚠️ Yes — 4 new test cases |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us21.md` | No |

**`cron/send_standups.php`**: `getAllTeams()` uses `SELECT *` — automatically covers new column. Only verify no hardcoded column list exists.

---

## `getMergedRecipients()` — Signature Change

```
Old: getMergedRecipients(PDO $pdo, int $teamId): array
New: getMergedRecipients(PDO $pdo, array $team): array
```

Call site in `sendSummaryEmail()` updated from `$teamId` to pass `$team` array. Returns merged, case-insensitively deduplicated recipient list from 3 sources (when flag=1) or 2 sources (when flag=0).

**Source priority for dedup**: external `team_recipients` rows (with unsubscribe token) take precedence over `is_developer`-only entries when same email.

**New helper**: `queryDeveloperMembers(PDO, int $teamId): array` — returns `[['email', 'display_name', 'unsubscribe_token' => null]]`; no `id` field (no team_recipients row).

---

## `updateTeam()` — Signature Change

```
Old: updateTeam(PDO $pdo, int $teamId, string $name, string $timezone, string $standupTime): void
New: updateTeam(PDO $pdo, int $teamId, string $name, string $timezone, string $standupTime, int $summaryToAllDevelopers = 0): void
```

Default value `= 0` keeps existing callers (cron indirect via createTeam, etc.) unaffected.

---

## Unsubscribe URL Logic in `sendSummaryEmail()`

```
if !empty($recipient['unsubscribe_token']):
    use token directly → build unsubscribeUrl
elseif isset($recipient['id']):
    ensureUnsubscribeToken() → build unsubscribeUrl
else:
    unsubscribeUrl = null (developer-auto recipient; no external row)
```

---

## Test Plan — 4 cases in `tests/RepositoryTest.php`

Seed: team with `summary_to_all_developers` flag, 1 developer member, 1 external recipient row.

| # | Method | Setup | Expected |
|---|---|---|---|
| 1 | `testGetMergedRecipients_FlagOff_ExcludesDeveloper` | flag=0 | Developer NOT in results |
| 2 | `testGetMergedRecipients_FlagOn_IncludesDeveloper` | flag=1 | Developer email IN results |
| 3 | `testGetMergedRecipients_FlagOn_DeduplicatesDeveloperWithExternalRecipient` | flag=1, same email in team_recipients + developer | 1 entry |
| 4 | `testGetMergedRecipients_FlagOn_DeduplicatesDeveloperWithIsRecipientMember` | flag=1, developer also is_recipient=1 | 1 entry |

---

## Self-Check

- [ ] `DEFAULT 0` on new column — no existing team behaviour changes
- [ ] `getMergedRecipients()` call site in `sendSummaryEmail()` updated to pass `$team` array
- [ ] `updateTeam()` SQL includes `summary_to_all_developers = ?`; default param keeps old callers safe
- [ ] Checkbox reflects DB state on GET; POST reads `isset($_POST['...']) ? 1 : 0`
- [ ] Developer recipients have `unsubscribe_token = null`; unsubscribe URL not generated for them
- [ ] `tests/schema-sqlite.sql` updated
- [ ] 4 tests for `getMergedRecipients()` flag on/off + dedup
- [ ] 51 existing tests still pass
