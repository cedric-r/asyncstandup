# PLAN-AMENDMENT-1 — US-9: PHPUnit PHAR Test Suite

**Status**: APPROVED
**Branch**: `feature/asyncstandup-tests-pwreset`
**Raised by**: PHP Developer (discovered during test implementation)

---

## Unplanned file modifications requiring approval

### 1. `src/SubmissionRepository.php`

**Reason for omission from IMPL-PLAN**: Not foreseen — the IMPL-PLAN assumed production SQL would be SQLite-compatible for testing.

**Justification**: `saveSubmission()` contains `SET used_at = UTC_TIMESTAMP()` in a MySQL-specific SQL expression. SQLite raises "no such function: UTC_TIMESTAMP" at execute time, which aborts the transaction and prevents `testSaveSubmissionHappyPath` (AC-4) from passing.

**Proposed fix** (1-line change): Replace `UTC_TIMESTAMP()` SQL expression with a PHP-computed timestamp passed as a parameter:
```php
// Before:
'UPDATE standup_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?'
// After:
$ts = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
'UPDATE standup_tokens SET used_at = ? WHERE id = ?'  // add $ts to execute()
```

**Risk**: Minimal — behaviour is identical (UTC timestamp set at the moment of execution). Eliminates MySQL-only function; works on both MySQL and SQLite.

---

### 2. `src/OrgRepository.php`

**Reason for omission from IMPL-PLAN**: Not foreseen — same MySQL-compatibility assumption.

**Justification**: `deleteOrg()` uses MySQL multi-table `DELETE a FROM standup_answers a JOIN ...` syntax. SQLite does not support `DELETE table_alias FROM ... JOIN`. `testDeleteOrgFullCascadeNoFkViolation` (AC-10) cannot pass.

**Proposed fix**: Replace each multi-table DELETE with a single-table DELETE using a subquery, which is valid on both MySQL and SQLite:
```sql
-- Before (MySQL-only):
DELETE a FROM standup_answers a
JOIN standup_submissions ss ON ss.id = a.submission_id
JOIN standup_tokens t ON t.id = ss.token_id
WHERE t.team_id IN (...)

-- After (cross-database):
DELETE FROM standup_answers
WHERE submission_id IN (
    SELECT ss.id FROM standup_submissions ss
    JOIN standup_tokens t ON t.id = ss.token_id
    WHERE t.team_id IN (...)
)
```
Same fix applies to the `standup_submissions` multi-table DELETE in `deleteOrg()`.

**Risk**: Minimal — same rows deleted; subquery approach is standard SQL supported by both MySQL and SQLite.

---

### 3. Test fix: `tests/RepositoryTest.php` — `assembleSummaryData()` assertions

**Not a new file** — test already in the IMPL-PLAN. No amendment required for this.

**Discovery**: `assembleSummaryData()` returns `['developers', 'questions', 'answerMap']`, not `['submitterData', 'nonSubmitters']` as the test assumed. `submitterData` and `nonSubmitters` are assembled inside `sendSummaryEmail()`. Test assertions will be corrected to match the actual return structure.

---

## Impact on implementation

- `src/SubmissionRepository.php` and `src/OrgRepository.php`: functional refactor — identical behaviour, cross-database SQL syntax.
- `InvitationRepository.php` (`markAccepted()`): same `UTC_TIMESTAMP()` issue; this file IS in the IMPL-PLAN — fix included in Commit 4.
- No new tables, no API changes, no behaviour changes.
- All existing call sites (public pages) unaffected.
