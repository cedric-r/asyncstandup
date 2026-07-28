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

---

# RETRO addendum — US-13: Navigation Improvements

**Branch**: `feature/asyncstandup-navigation` → `main`
**Review cycles**: 1 (CLEAN PASS) | **Plan amendments**: 0

## What went well

- CLEAN PASS on Cycle 1 — additive-only navigation changes (require_once additions, variable assignments, include calls, link additions) produced zero regressions and required no amendments.
- Caller contract for `team-nav.php` documented in the partial itself via PHPDoc-style `@var` annotations — reviewer accepted this.
- All 7 team pages integrated in a single implementation commit with consistent variable naming and `$currentPage` explicit string constants.
- `$isOwner` derived from `isTeamOwner()` in every calling page — same function used for access control and nav rendering; no inconsistency possible.
- `teams/index.php` action cell restructured to be fully owner-gated in one block — simpler and more secure than the previous mix of gated/ungated links.

## Lessons learned (US-13)

1. **Shared template partials need a documented caller contract** — `team-nav.php` requires 6 variables set before include (`$teamId`, `$orgId`, `$teamName`, `$orgName`, `$isOwner`, `$currentPage`). Document each at the top of the partial with PHPDoc-style `@var` blocks. Without this, future developers adding the nav to a new page will silently receive null/undefined errors in PHP 8.x with no guidance.

2. **`h()` helper belongs in `src/` from Phase 0** — centralising `htmlspecialchars(ENT_QUOTES, 'UTF-8')` in a single named function would have eliminated 100+ repetitive inline calls across all 13 stories. Add `src/View.php` with `h()` to the standard scaffold in every new PHP project. Cost: 10 lines. Savings: readability + typo prevention across all output sites.

3. **Additive Path B = no characterisation required** — appending a new function, adding a `require_once`, or inserting one link do not change existing logic. Characterisation is for cases where existing code is modified. Document this reasoning explicitly in commit messages so reviewers don't flag it unnecessarily.

---

# RETRO addendum — US-14: Bug Fixes and Improvements

**Branch**: `feature/asyncstandup-fixes` → `main`
**Review cycles**: 3 | **Plan amendments**: 1 (PLAN-AMENDMENT-6)

## What went well

- All 4 fixes (Bug 1, Feature 2, Feature 3, Bug 4) implemented correctly on first commit — no functional findings from Code Reviewer or Security Auditor.
- `getMergedRecipients()` deduplication logic (case-insensitive `strtolower(trim())`) was correct on first implementation.
- Weekend skip using `format('N')` (ISO 8601, 6=Sat, 7=Sun) was correct — the brief’s note about `format('w')` ambiguity was heeded.
- `require_once` dependency fix (Option A — file scope) accepted by Code Reviewer immediately.

## What caused review cycles

### Cycle 1 + Cycle 2/3 — PLAN-AMENDMENT-6: RepositoryTest.php not in IMPL-PLAN

**What happened**: The Test Validator flagged `getMergedRecipients()` as having no tests (MAJOR). Tests were added to `tests/RepositoryTest.php` — a file created in US-9 and not listed in the US-14 IMPL-PLAN. This triggered a stop for PLAN-AMENDMENT-6.

**Root cause**: The IMPL-PLAN was written without including `tests/RepositoryTest.php` in the file list, even though:
1. US-14 adds a new PDO-injectable `src/` function (`getMergedRecipients()`)
2. The RETRO from US-9/US-10 explicitly states: “Once the PHPUnit harness exists, new `src/` functions require tests”
3. The established precedent (US-12 Cycle 1, US-10 Cycle 1) shows the Test Validator WILL require tests

**Lesson**: **Always pre-list `tests/RepositoryTest.php` (or the appropriate test file) in the IMPL-PLAN when the story adds any new `src/` function with PDO parameters.** This is now a known requirement — it is not “emerging during review”, it is predictable. Treat test file additions as mandatory deliverables, not reactive fixes.

**Prevention**: Add to IMPL-PLAN authoring checklist: “For every new function in `src/` that takes a PDO parameter, list the relevant test file in the file list and add at least 2 test cases to the test plan.”

### Code Reviewer MINOR: `require_once` inside function body

**What happened**: `require_once __DIR__ . '/../src/OrgRepository.php'` was placed inside `sendStandupPrompt()`. Functionally correct (idempotent) but hides the dependency.

**Fix**: Moved to file scope at the top of `StandupEmailer.php`. This is the correct PHP convention for module-level dependencies.

**Lesson**: **`require_once` belongs at file scope** — always declare module dependencies at the top of the file, never inside a function. In-function loading is valid PHP but misleads static analysis and developers reading the file.

## Lessons learned (US-14 summary)

1. **Pre-list test files in IMPL-PLAN** — if the story adds a new `src/` PDO function, `tests/RepositoryTest.php` goes in the file list; the Test Validator requirement is predictable, not emergent.
2. **Role flags must be wired to the actual send list at implementation time** — `is_recipient=1` on `team_members` existed since US-3 but was never connected to the summary send loop; audit role flags against their intended uses at implementation time.
3. **`require_once` always at file scope** — never inside function bodies; declare all module dependencies at the top of the file.

---

# RETRO addendum — US-16 + US-17: Account Deletion + Admin Role

**Branch**: `feature/asyncstandup-admin` → `main`
**Review cycles**: 2 | **Plan amendments**: 2 (PA-7, PA-8)

## What went well

- `deleteUserAccount()` transaction cascade correct on first implementation — all 9 steps in FK-safe order, including the non-obvious `organisations.created_by` / `teams.created_by` nullification (spec gap caught and filled before first review).
- Admin self-de-admin protection implemented correctly first attempt.
- `loginUser()` return-type change from `bool` to `string` was the right design — avoids callers having to re-query the DB for account status.
- 43 tests, 90 assertions, 0 warnings after Cycle 2 fixes.

## What caused review cycles

### PA-7: `templates/email/admin_new_registration.php` not in IMPL-PLAN

**What happened**: New requirement added during implementation (admin notification email). Template file created without first adding it to the IMPL-PLAN. PLAN-AMENDMENT raised but incorrectly self-approved — reviewer correctly flagged that all PLAN-AMENDMENT files require user approval.

**Lesson**: PLAN-AMENDMENT files always require user approval. Even when the change is small, additive, and directed by Team Lead, the amendment status must remain PENDING until the user explicitly approves.

### PA-8: `tests/schema-sqlite.sql` not in IMPL-PLAN

**What happened**: `db/schema.sql` was changed (new columns, nullable FKs) and `tests/schema-sqlite.sql` was updated to mirror it — but the test schema file was not listed in the IMPL-PLAN file list.

**Root cause**: The now-established pattern is that any story that modifies `db/schema.sql` also modifies `tests/schema-sqlite.sql`. This is predictable, not emergent.

**Lesson**: **Pre-list `tests/schema-sqlite.sql` in every IMPL-PLAN that modifies `db/schema.sql`.** The two files are coupled — schema changes always require a test schema update. Add to IMPL-PLAN authoring checklist: “If `db/schema.sql` is in the file list, add `tests/schema-sqlite.sql` too.”

### Cycle 1 MINOR: `reject` action missing `password_resets` cleanup

**What happened**: `admin/users.php` reject action did `DELETE FROM users` without first deleting `password_resets` rows. A pending user who submitted a forgot-password request before rejection would cause a FK constraint violation.

**Lesson**: Any `DELETE FROM users` outside of `deleteUserAccount()` must follow the same cleanup pattern. The `deleteUserAccount()` function documents the 9-step cascade. Any other code path that deletes a user row must either call `deleteUserAccount()` or replicate the relevant cleanup steps. Document in code comments.

### Cycle 1 MAJOR: `loginUser()` return-type change (bool → string) — no tests

**What happened**: Changing `loginUser()` from `bool` to `string` is a breaking contract change — no tests covered the 4 return values. Callers (`login.php`, pending invite flow) were updated but the test coverage was missing.

**Lesson**: **Changing a function’s return type is a breaking contract change** — always update tests in the same commit. Pre-list the test file (e.g. `tests/RepositoryTest.php`) in the IMPL-PLAN when a function signature changes.

## Lessons learned (US-16/17 summary)

1. **Pre-list `tests/schema-sqlite.sql` whenever `db/schema.sql` is listed** — the files are coupled; the omission is now a known avoidable pattern.
2. **Any `DELETE FROM users` must clean up `password_resets` first** — follow the `deleteUserAccount()` cascade; document this in new code comments.
3. **`loginUser()` return-type change requires test updates** — pre-list the test file; breaking contract changes = test updates in the same commit.
4. **`session_regenerate_id()` must be guarded by `session_status()`** — prevents PHPUnit warnings in CLI; add to project PHP coding standards.

---

# RETRO addendum — US-15: Text-Based CAPTCHA

**Branch**: `feature/asyncstandup-captcha` → `main`
**Review cycles**: 1 (CLEAN PASS) | **Plan amendments**: 0

## What went well

- CLEAN PASS on Cycle 1 — no amendments, no review findings. Pure PHP file + session-only state = no scope surprises.
- Validation order (CSRF → CAPTCHA → form logic) implemented correctly on first attempt.
- `random_int()` (not `rand()`) used as required; `unset()` in `captchaValidate()` enforces the one-attempt policy correctly.
- Missing session index returns `false` immediately (replay protection) — no DB access on any invalid path.
- Correctly pre-noted in READY FOR REVIEW that Test Validator may require tests for pure functions, and offered a PLAN-AMENDMENT path proactively.

## Lessons learned (US-15)

1. **Session-dependent functions are not blocking for tests** — `captchaGetRandomQuestion()` and `captchaValidate()` read/write `$_SESSION`, which PHPUnit doesn’t initialise. This is the same constraint as `validateCsrfToken()` (already untested for the same reason). Document explicitly in future IMPL-PLANs: “This function requires an active session — not unit-testable without session mocking; consistent with `validateCsrfToken()` precedent.”

2. **Extract a pure inner function for partial testability** — `captchaValidate()` mixes session access (impure) with answer matching (pure). Extracting `captchaCheckAnswer(int $idx, string $userInput): bool` as a pure function would make the matching logic directly testable without any session. Recommend extracting in a follow-up story.

3. **Pure PHP file + session-only state = no scope surprises** — `src/Captcha.php` adds no DB tables, no new DB queries, and no external services. When implementation exactly matches the spec (spec-driven development), amendments are not needed. Keep spec detail level high for future stories.

---

# RETRO addendum — US-18: Pending Standups on Dashboard

**Branch**: `feature/asyncstandup-dashboard-pending` → `main`
**Review cycles**: 3 | **Plan amendments**: 2 (PA-9, PA-10)

## What went well

- `getPendingTokensForUser()` query logic (used_at IS NULL, expires_at filter, is_developer JOIN) was correct on first implementation.
- 5 test cases in `DashboardRepositoryTest.php` were written correctly and all passed first run — including the ordering test (Alpha before Zebra).
- `gmdate()` used for test seed timestamps (UTC-aligned, avoiding the US-8 strtotime lesson). Also applied for the final production fix.
- PLAN-AMENDMENT-9 and PLAN-AMENDMENT-10 were raised promptly and correctly.

## What caused review cycles

### Cycle 1 MAJOR-1 + MAJOR-2: reject action debug label + missing transaction

The reject cascade in `admin/users.php` (from US-16/17 hot-fixes) had:
- A `[REJECT DEBUG]` label in the error_log call — debug artifact in production code
- No `beginTransaction()` wrapper — a multi-statement cascade without a transaction is a data integrity bug

**Lesson**: Any multi-statement cascade (UPDATE × N + DELETE × N) must always be wrapped in a transaction. The `deleteUserAccount()` function in Auth.php does this correctly — the reject action should have been modelled on it from the start.

### Cycle 2: `datetime('now')` is SQLite-only

**What happened**: `getPendingTokensForUser()` used `datetime('now')` directly in the SQL WHERE clause. This is valid in SQLite but MySQL rejects it in this context.

**Root cause**: The test suite uses SQLite in-memory. A query that passes all SQLite tests can fail silently in MySQL production when using dialect-specific datetime functions.

**Fix**: PHP-computed `$nowUtc = gmdate('Y-m-d H:i:s')` passed as a bound parameter. Plain string comparison `expires_at > ?` works in both MySQL (DATETIME column compared against datetime string) and SQLite (TEXT column compared against ISO 8601 TEXT string).

**Lesson**: **Any date/time comparison in a new SQL query must be verified against both MySQL and SQLite dialects before marking READY.** The rule: if a datetime value is needed in a WHERE clause, compute it in PHP with `gmdate()` and pass as a `?` parameter. Never use `datetime('now')`, `NOW()`, `UTC_TIMESTAMP()`, or any DB-specific datetime function in WHERE conditions — these are dialect-specific.

**Prevention checklist item**: Before READY FOR REVIEW, grep new queries for `datetime('now'|'now'`, `NOW()`, `UTC_TIMESTAMP()` in WHERE/HAVING clauses. Replace with PHP-computed bound parameters.

### PA-9: Hot-fix files in branch diff not in IMPL-PLAN

Five files from US-16/17 hot-fixes (`admin/users.php`, `Auth.php`, `View.php`, `register.php`, `layout.php`) appeared in the branch diff because the feature branch was cut from main after those hot-fixes landed. Code Reviewer correctly flagged them as unplanned. PLAN-AMENDMENT-9 documented the context.

**Lesson**: When cutting a feature branch from main, check if recent hot-fix commits are included in the diff. If they are, pre-list them in the IMPL-PLAN with a note: “Hot-fix files from [story] — included for diff accuracy; no US-18 changes in these files.”

### PA-10: User requested dashboard link on submit.php mid-Gate-D

Small UX request raised after review. Handled correctly: PLAN-AMENDMENT-10 committed with PENDING status, user approved, implemented in one line. Process worked.

**Lesson**: User requests during review are handled cleanly via the PLAN-AMENDMENT process. No need to panic or merge early.

## Lessons learned (US-18 summary)

1. **`datetime('now')` is SQLite-only** — use `gmdate('Y-m-d H:i:s')` as a bound parameter for any date comparison in production queries.
2. **Multi-statement cascades must use `beginTransaction()`** — no exceptions; every multi-step cascade without a transaction is a data integrity bug.
3. **Hot-fix files in branch diff → pre-list in IMPL-PLAN** — note context so Code Reviewer doesn't flag them as unplanned scope.
4. **Test suite uses SQLite; verify MySQL compat manually** — especially for datetime functions, MySQL-specific syntax, and column type differences.

---

# RETRO addendum — US-19: Tailwind CSS UI Redesign

**Branch**: `feature/asyncstandup-ui` → `main`
**Review cycles**: 1 (CLEAN PASS) | **Plan amendments**: 0

## What went well

- CLEAN PASS on Cycle 1 — all 26 pages restyled in a single implementation commit with no functional regressions.
- 48 tests, 98 assertions all passed — confirmed: zero HTML assertions in the test suite; cosmetic-only page rewrites have no impact on the test layer.
- Context-aware unauthenticated nav (logo + Log in + Register) correctly handled in layout.php without per-page logic.
- Tailwind Play CDN approach is right for a no-build PHP project in dev/staging.

## What required adjustment during implementation

### Additional requirement: nav on every page

The initial implementation used `$hideNav = true` on auth/token pages (login, register, submit, forgot-password, reset-password). Mid-implementation the Team Lead added that the nav must appear on ALL pages, context-aware for unauthenticated state.

**Fix**: removed the `if (!isset($hideNav) || !$hideNav)` conditional from `templates/layout.php`. Nav always renders. When `$currentUser` is null, the right side shows "Log in" + "Register" links instead of user controls. `$hideNav = true` removed from all 6 pages that set it.

**Lesson**: Nav consistency is architecture, not decoration. An opt-out pattern (`$hideNav = true` per page) is fragile — easy to forget on new pages and easy to break when refactoring. The opt-in pattern (always show nav, context-aware state) is cleaner. Design nav consistency as an architectural constraint from the start.

## Lessons learned (US-19 summary)

1. **Tailwind Play CDN is not production-grade** — for production, compile a static CSS with `npx tailwindcss`; CDN is fine for dev/staging and no-build projects.
2. **Template rewrites must audit every `htmlspecialchars()` call** — cosmetic-only rewrites are high-risk for accidentally stripping escaping; Code Reviewer spot-check is essential.
3. **Nav consistency is architecture** — opt-in (always show, context-aware) > opt-out (`$hideNav = true` per page); design correctly from the start.
4. **No tests break on cosmetic changes** — zero HTML assertions in the test suite; well-isolated tests enable fearless UI iteration.
