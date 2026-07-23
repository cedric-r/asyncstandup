# IMPL-PLAN — PHP Developer
## US-12: Standup Response Browser

**Status**: APPROVED
**Branch**: `feature/asyncstandup-response-browser`
**Agent**: PHP Developer

---

## File List (exhaustive)

| Action | File | Path B? |
|---|---|---|
| Create | `public/teams/responses.php` | No |
| Modify | `src/DashboardRepository.php` | ⚠️ Yes — additive: new function only |
| Modify | `public/teams/dashboard.php` | ⚠️ Yes — add "View Responses" link |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us12.md` | No — this file |

**No other files will be created or modified.** Reusing existing functions:
- `getDeveloperMembers(PDO, int $teamId): array` from `src/StandupEmailer.php` (is_developer=1 members)
- `getQuestions(PDO, int $teamId): array` from `src/TeamRepository.php` (position ASC order)

---

## New Function Signatures

### Added to `src/DashboardRepository.php`

```php
function getResponseData(
    PDO $pdo,
    int $teamId,
    ?string $date,
    ?int $memberId,
    string $dateFrom,
    string $dateTo
): array
```
Executes the core response query with conditional WHERE clauses.
Returns a flat row array; caller assembles into `$data[$send_date][$user_id]` structure.
`$date` and `$memberId` are mutually optional — all four view modes handled by the same query.
All parameters passed as PDO bound values — no string interpolation in SQL.

---

## TDD Paths

| File | Path | Rationale |
|---|---|---|
| `src/DashboardRepository.php` | **Path B** | Already on main; additive only — no existing function modified |
| `public/teams/dashboard.php` | **Path B** | Already on main; one link addition in owner section |
| `public/teams/responses.php` | **Path A** | New file |

No characterisation commit required — both Path B changes are purely additive (new function + new HTML anchor). No existing logic is modified.

---

## Commit Sequence

### Commit 1 — IMPL-PLAN (APPROVED)
### Commit 2 — Implementation (all 3 files)

```
feat(us-12): standup response browser — responses.php, DashboardRepository, dashboard link
```

Single implementation commit is appropriate here — the three files are tightly coupled and the changes are purely additive.

---

## Key Implementation Notes

### Reused functions
- `getDeveloperMembers($pdo, $teamId)` → already in StandupEmailer.php; bootstrap for responses.php includes StandupEmailer.php
- `getQuestions($pdo, $teamId)` → already in TeamRepository.php; included via require_once

### View modes
```
?date + ?member_id → 'single'    (one member, one day)
?date only         → 'by_date'   (all members, one day)
?member_id only    → 'by_member' (one member, 30 days)
default            → 'default'   (all members, last 7 days)
```

### Security rules applied
- `isTeamOwner()` + `forbid()` before any data load (AC-5, AC-6)
- `$teamId` and `$memberId` cast to `(int)` immediately from `$_GET`
- `$dateFilter` validated with `DateTimeImmutable::createFromFormat('Y-m-d', ...)`
- `$memberId` validated as `is_developer = 1` member of `$teamId`
- All answer text + display names through `htmlspecialchars(ENT_QUOTES, 'UTF-8')`
- No SQL string interpolation — all values bound via PDO `?` parameters

---

## Self-Check Before Signalling READY FOR REVIEW

- [ ] `isTeamOwner()` + `forbid()` is the first operation after `requireLogin()`
- [ ] `(int)` cast on all `$_GET` integer values
- [ ] `DateTimeImmutable::createFromFormat` validation on `$dateFilter`
- [ ] Member filter validated as `is_developer` team member
- [ ] All output `htmlspecialchars(ENT_QUOTES, 'UTF-8')`
- [ ] `declare(strict_types=1)` in `responses.php`
- [ ] No `var_dump`/`print_r`/`die`
- [ ] No new DB tables
- [ ] Scope ≤ 30 days; no pagination
