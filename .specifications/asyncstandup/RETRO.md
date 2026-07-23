# RETRO — AsyncStandUp (US-1 through US-8)

**Branch**: `feature/asyncstandup-core` → `main`
**Review cycles**: 2 | **Plan amendments**: 0
**Stories delivered**: US-1 Registration, US-2 Organisations, US-3 Teams, US-4 Invitations, US-5 Standup Emails, US-6 Submission, US-7 Dashboard, US-8 Summary Email

---

## What went well

- All 8 stories delivered in a single feature branch with zero plan amendments.
- CSRF, PRG pattern, PDO parameterised queries, and cascade-delete order were applied consistently across all 47 files without reviewer findings in those areas.
- `session_regenerate_id(true)` on login/registration — correct from the start.
- `isTeamDue()` timezone arithmetic (UTC comparison via `DateTimeImmutable`) was correct on first implementation — no timezone-related bugs like the US-8 `strtotime()` issue in site-monitor.
- `attemptInsertSummaryLock()` using `INSERT IGNORE` as the dedup guard (committed before sending) was a correct race-safe implementation from the start.
- `saveSubmission()` transaction (submission + answers + mark token used) correctly atomic.
- No PLAN-AMENDMENT was required — the exhaustive file list discipline held.

---

## What caused review cycles

### Cycle 1 — MAJOR: Email header injection in `sendMail()`

**What happened**: `$toName` (from `display_name`, user-controlled) and `$subject` (includes team name, owner-controlled) were written into raw SMTP headers via `fwrite()` without CR/LF stripping. A crafted display name containing `\r\n` can inject arbitrary headers (Bcc, Cc, X-headers).

**Root cause**: Wrote `fwrite($socket, "To: {$toName} <{$to}>\r\n")` trusting that upstream validation (registration form `maxlength`, display name input) would prevent CR/LF. This assumption is wrong for any function that writes to a raw protocol socket.

**Fix**: `str_replace(["\r", "\n"], ' ', ...)` applied to `$toName`, `$fromName`, `$to`, `$subject` at the top of `sendMail()` before any `fwrite`. Also applied to `SummaryEmailer.php` where `display_name` from the DB is passed as `$toName`.

**Lesson**: **Any user-controlled or DB-sourced string written into a raw SMTP/HTTP header must be CR/LF stripped at the call site.** Do not rely on upstream validation or DB constraints — apply `str_replace(["\r","\n"], ' ', ...)` defensively to every header value in `sendMail()`, regardless of source. This is a fixed-cost one-liner that prevents a critical injection class.

**Prevention**: Add a pre-commit review checklist item: "For every `fwrite()` call that builds an email header from a variable, is CR/LF stripped immediately before this write?"

---

### Cycle 1 — MAJOR: Invitation accept flow broken for existing logged-out users (AC-3)

**What happened**: `accept.php` correctly identified an existing user not logged in and redirected them to `login.php`. However, `login.php` always redirected to dashboard after successful authentication — it never called `acceptInvitationForUser()`. Users ended up on the dashboard without having joined the team. AC-3 violated.

**Root cause**: The multi-step flow (click link → login → auto-join) was not wired end-to-end. The redirect from `accept.php` to `login.php` passed the token in a `?redirect=accept&token=...` query string, but `login.php` never read that parameter.

**Fix**: Session-based intent persistence. `accept.php` stores `$_SESSION['pending_invite_token'] = $token` before redirecting. `login.php` reads and clears the key after `loginUser()` succeeds, then calls `acceptInvitationForUser()` and shows a "You have joined the team" flash.

**Lesson**: **Multi-step user journeys that cross a form boundary must persist intent in the session.** A redirect with query-string parameters is lost when the user submits the login form (POST to the same URL, which then redirects to dashboard). The session is the only safe carrier for cross-request intent in a stateless HTTP + PRG flow. Pattern: `$_SESSION['pending_{action}'] = $data` before redirect; read + clear on the next authenticated request.

**Prevention**: For every AC that describes a multi-step flow crossing an authentication boundary, explicitly diagram each hop and verify that the intent (token, destination, action) survives the session before coding.

---

### Cycle 1 — MINOR: Session hardening incomplete

**What happened**: `startSession()` set `cookie_httponly` and `cookie_samesite` but not `cookie_secure` or `use_strict_mode`. Both Code Reviewer and Security Auditor flagged it independently — a sign that this is a well-known checklist item.

**Fix**: Added both `ini_set('session.cookie_secure', '1')` and `ini_set('session.use_strict_mode', '1')`.

**Lesson**: **Session hardening is a four-setting checklist — set all four together or none:**
1. `session.cookie_httponly = 1` — prevents JS access to session cookie
2. `session.cookie_samesite = Lax` — CSRF mitigation
3. `session.cookie_secure = 1` — HTTPS-only transmission
4. `session.use_strict_mode = 1` — rejects unrecognised session IDs (session fixation defence)

If `cookie_secure = 1` is not appropriate (e.g. local dev over HTTP), document the exception and gate it on a config flag — but the production default must be `1`.

---

### Cycle 1 — MINOR: `display_errors` not suppressed / no global exception handler

**What happened**: No `ini_set('display_errors', '0')` was set. On a server with `display_errors=On` (common on shared hosting), an uncaught PDO exception would expose the DSN hostname, credentials fragment, and internal path in the browser response.

**Fix**: Added at file scope in `src/Auth.php`:
```php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
set_exception_handler(static function (Throwable $e): void {
    http_response_code(500);
    echo '<p>An unexpected error occurred.</p>';
    error_log((string) $e);
    exit;
});
```

Placed in `Auth.php` (which every public page `require_once`s) to achieve global coverage without a new file — avoiding a PLAN-AMENDMENT.

**Lesson**: **`display_errors=0` + `set_exception_handler()` must be in every project from the first commit.** A production application that exposes stack traces via uncaught exceptions has a critical information-disclosure vulnerability. The correct pattern is to suppress display, log the full trace, and show a generic message. This belongs in the scaffold (Phase 0) alongside the schema and config.

---

## Lessons learned (summary)

1. **CR/LF strip all SMTP header values in `sendMail()`** — apply `str_replace(["\r","\n"], ' ', ...)` to every header field sourced from a variable, regardless of upstream validation.
2. **Multi-step flows across auth boundaries need session-based intent** — `$_SESSION['pending_*'] = $data` before redirect; read + clear on next authenticated request.
3. **Session hardening = 4 settings, not 2** — `httponly`, `samesite`, `secure`, `use_strict_mode` — all four in one block, always.
4. **`display_errors=0` + exception handler belongs in Phase 0** — not in a Cycle 1 fix. Add to every project scaffold alongside schema and config.
5. **No-test projects still benefit from testability review** — `isTeamDue()` and `saveSubmission()` are highest-priority candidates for future unit tests; isolating pure functions from side-effecting ones (timezone arithmetic, DB transactions) is architecturally correct even without a test suite.
