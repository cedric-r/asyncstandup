# RETRO — US-9 (PHPUnit Tests) + US-10 (Password Reset) + US-11 (Access Control)

**Branch**: `feature/asyncstandup-tests-pwreset` → `main`
**Review cycles**: 2 | **Plan amendments**: 4 (all APPROVED)
**Stories delivered**: US-9 PHPUnit PHAR test suite, US-10 Password reset + change-password, US-11 Access control

---

## What went well

- PHPUnit PHAR test harness (US-9) delivered correctly on first attempt — bootstrap, schema-sqlite.sql, `createTestPdo()` pattern, and save/restore exception handler all worked as designed.
- `isTeamDue()` and `isSummaryDue()` boundary cases (12 tests) all passed first run — timezone arithmetic was already correct from US-5/US-8.
- `applyPasswordReset()` concurrent-use race was caught by Security Auditor L-2 and fixed cleanly with the `WHERE used_at IS NULL` + `rowCount()` guard — a real (if unlikely) security fix with no test complexity.
- The `$now` extraction pattern (`?DateTimeImmutable $now = null`) applied consistently across both `acceptInvitationForUser()` (US-9) and `createPasswordResetToken()` (QC-2) — established as the testability pattern for time-dependent functions.
- No email enumeration in `forgot-password.php` was implemented correctly on the first commit — same flash for known/unknown email.

---

## What caused plan amendments

### PLAN-AMENDMENT-1 — SQLite compatibility of production SQL (2 files)

**What happened**: `src/SubmissionRepository.php` uses `SET used_at = UTC_TIMESTAMP()` and `src/OrgRepository.php` uses multi-table `DELETE a FROM ... JOIN`. Both are MySQL-only. SQLite rejects them at prepare time, not just at execute time — so even code paths that would never be hit in tests fail.

**Root cause**: The IMPL-PLAN's SQLite compatibility strategy assumed the production code could be tested as-is. The assumption was not verified against the actual production SQL before writing the plan.

**Fix**: `UTC_TIMESTAMP()` replaced with PHP-computed timestamps passed as `?` parameters. Multi-table DELETE replaced with subquery form: `DELETE FROM standup_answers WHERE submission_id IN (SELECT ...)`. Both forms produce identical results; subquery form is standard SQL.

**Lesson**: **Before writing a test IMPL-PLAN for a MySQL codebase, audit all SQL in files under test for MySQL-specific functions and syntax.** Common MySQL-only patterns: `UTC_TIMESTAMP()`, multi-table DELETE with JOIN, `ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`, `LIMIT` in UPDATE/DELETE. Any of these will cause SQLite test failures.

**Prevention**: Add a pre-implementation checklist item: "For each production file listed in the test file list, grep for: `UTC_TIMESTAMP`, `DELETE.*FROM.*JOIN`, `ON DUPLICATE KEY`. Verify each is either replaced or test-wrapped."

---

### PLAN-AMENDMENT-2 — US-10 extension + US-11 (scope expansion, pre-approved)

New requirements added during implementation (change-password on profile, dashboard 403 for non-owners, org/team edit 403 for non-creators). These were genuine scope additions, not oversights. No lesson; handled correctly via amendment process.

---

### PLAN-AMENDMENT-3 — `.gitignore` not in IMPL-PLAN file list

**What happened**: The IMPL-PLAN PHPUnit PHAR Strategy section explicitly stated "Add `tests/phpunit.phar` to `.gitignore`" but `.gitignore` was not in the exhaustive file list table. The modification was implied by approved text but absent from the list.

**Root cause**: Same as US-5 lesson — narrative mentions ≠ file list entries. The file list table is the authoritative contract; the narrative is explanatory prose.

**Lesson**: **When the IMPL-PLAN narrative describes a file modification ("add X to Y"), Y must appear in the file list table.** Final audit before committing any IMPL-PLAN: for every file mentioned anywhere in the document, confirm it appears in the file list.

---

### PLAN-AMENDMENT-4 — `orgs/delete.php` missing from PLAN-AMENDMENT-2

**What happened**: PLAN-AMENDMENT-2 (US-11 Access Control) listed `public/orgs/edit.php` for `isOrgCreator()` enforcement, but did not list `public/orgs/delete.php`. The Security Auditor correctly flagged that delete is a more destructive operation and needed the same guard. Code Reviewer then flagged the scope violation.

**Root cause**: When adding access control to one page in a CRUD resource group, the companion pages were not systematically checked.

**Lesson**: **When adding access control to any page in a CRUD resource group, always check ALL pages in the group (index/create/edit/delete/show).** The pattern: if `orgs/edit.php` needs `isOrgCreator()`, then `orgs/delete.php` needs it too — and it almost certainly needs it more (delete is irreversible). Always audit the full group when patching any one member.

**Prevention**: Add to the access control checklist: "For each page receiving a new guard, list all pages in the same resource directory and verify each has the appropriate guard."

---

## Technical patterns established in this branch

### Auth.php exception handler save/restore

Auth.php installs a `set_exception_handler()` at file scope (for production error suppression). Including it in PHPUnit breaks PHPUnit's own exception handling. Pattern:

```php
$prevHandler = set_exception_handler(null);
require_once __DIR__ . '/../src/Auth.php';
set_exception_handler($prevHandler);
unset($prevHandler);
```

Use this pattern in any test file that needs Auth.php functions.

### `$now` extraction for time-dependent functions

Functions that compute "now" internally are not directly testable for time-sensitive paths. Pattern:

```php
function myFunction(PDO $pdo, ..., ?DateTimeImmutable $now = null): ...
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    // use $now instead of new DateTimeImmutable('now')
}
```

Applied to: `acceptInvitationForUser()`, `createPasswordResetToken()`. Apply to any future function with time-based logic.

### Cross-database SQL defaults

| MySQL-only | Cross-database replacement |
|---|---|
| `SET col = UTC_TIMESTAMP()` | PHP: `$ts = (new DateTimeImmutable('now', UTC))->format('Y-m-d H:i:s'); SET col = ?` |
| `DELETE a FROM t a JOIN t2 ON ...` | `DELETE FROM t WHERE id IN (SELECT t.id FROM t JOIN t2 ON ...)` |
| `ON DUPLICATE KEY UPDATE` | `try { INSERT } catch (PDOException 23000) { UPDATE }` |

---

## Lessons learned (summary)

1. **Audit production SQL for MySQL-only syntax before writing test IMPL-PLAN** — `UTC_TIMESTAMP()`, multi-table DELETE JOIN, `ON DUPLICATE KEY` all fail on SQLite at parse time.
2. **Narrative mentions ≠ file list entries** — every file mentioned anywhere in IMPL-PLAN text must be in the file list table (US-5 lesson repeated).
3. **Access control: audit the full CRUD resource group, not just the page you're editing** — if edit needs a guard, delete needs it more.
4. **After test harness exists, new PDO functions require tests** — "no tests" applies only to the pre-harness baseline; once PHPUnit is available, new testable functions must be covered.
5. **Auth.php exception handler save/restore** — standard pattern for including Auth.php in test files without breaking PHPUnit's exception handling.

---

# RETRO addendum — US-12: Standup Response Browser

**Branch**: `feature/asyncstandup-response-browser` → `main`
**Review cycles**: 2 | **Plan amendments**: 1 (PLAN-AMENDMENT-5)

## What went well

- Core implementation (`getResponseData()`, 4 view modes, filter validation) was correct on first commit — all AC verified by Security Auditor and Code Reviewer.
- `isTeamOwner()` + `forbid()` enforced as the first check after `requireLogin()` — no auth-bypass finding.
- Purely additive Path B changes (new function appended to DashboardRepository, one link added to dashboard.php) were handled correctly without characterisation commits — appropriate per the "no existing logic touched" rule.
- No new DB tables; max 30-day scope maintained throughout.

## What caused review cycles

### Cycle 1 — Double-escaping of display names in `$viewLabels`

**What happened**: Display names were `htmlspecialchars()`'d when building the `$viewLabels` string, then escaped again when the string was echoed through `htmlspecialchars()` at render time. `O'Brien` → `O&amp;#039;Brien` (visually wrong; not a security issue).

**Root cause**: Intermediate string assembly mixed escaped and raw values — the pre-escaping was defensive but applied at the wrong layer.

**Fix**: Store raw display name in `$viewLabels`; the single `htmlspecialchars()` at `echo` time is the correct and only escape site.

**Lesson**: **Escape raw values at the single render site — never pre-escape values stored in intermediate variables.** If a variable will later be passed to `htmlspecialchars()`, it must be stored as raw text. Pre-escaping + re-escaping at render produces double-encoding artifacts. The rule: one escape, at output.

### Cycle 1 — Test Validator MAJOR: `getResponseData()` has no tests

**What happened**: `getResponseData()` is a PDO-injectable `src/` function with no test coverage. Once the PHPUnit harness exists (US-9), all new `src/` functions are testable and must be tested — regardless of whether they are read-only queries.

**Fix**: 6 integration tests in `tests/DashboardRepositoryTest.php` covering all 4 view modes + LEFT JOIN null path + answer row expansion.

**Lesson**: **Once the PHPUnit harness exists, new `src/` functions require tests — including read-only query functions.** The absence of a test is a MAJOR finding when the infrastructure to write it already exists. Add a checklist item to the IMPL-PLAN self-check: "For every new function in `src/`, there is at least one test case in the test suite."

### Cycle 1 — PLAN-AMENDMENT-5 incorrectly self-approved

**What happened**: `tests/DashboardRepositoryTest.php` is not in the IMPL-PLAN file list. The RETRO described it as "Autonomous mode auto-approved" — but all PLAN-AMENDMENT files require user approval regardless of mode.

**Lesson**: **PLAN-AMENDMENT files always require user approval — Autonomous mode auto-approves Gates B and C only, not plan amendments.** Never claim a PLAN-AMENDMENT is auto-approved; always pause and wait for user confirmation.

## Lessons learned (US-12 summary)

1. **New `src/` functions → tests mandatory** — read-only PDO functions are no exception once the harness exists; add to IMPL-PLAN self-check.
2. **Double-escaping trap** — store raw values in intermediate variables; escape once at the render site; never pre-escape and re-escape.
3. **Additive Path B waiver is correct** — appending a new function and adding one link require no characterisation; document this decision in the commit message.
4. **PLAN-AMENDMENT = user approval always** — Autonomous mode does not bypass amendment approval; treat amendments as Gate D-equivalent stops.
