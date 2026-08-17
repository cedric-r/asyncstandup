# RETRO — US-28: Member Answer History

**Story**: US-28 — Member Answer History (developer access to responses.php)
**Branch**: `feature/us-28-member-answer-history`
**Merge commit**: `0ecb372`
**Review cycles**: 2
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `src/TeamRepository.php` | Added `isDeveloperMember()`, `canAccessResponses()`, `canSeeAllMemberResponses()` |
| `public/teams/responses.php` | Replaced owner-only gate with `canAccessResponses()` + `canSeeAllMemberResponses()`; server-side `memberFilter` forced to `$userId` when `!$canSeeAll`; `$fillMembers` scoped to current user; member `<select>` conditional; heading/pageTitle per `$canSeeAll` |
| `public/teams/index.php` | `$isTDeveloper` per team; My History / Responses link for non-owner developers |
| `public/dashboard.php` | History link on team cards for `is_developer` members |
| `tests/MemberAnswerHistoryTest.php` | 5 tests calling `isDeveloperMember()`, `canAccessResponses()`, `canSeeAllMemberResponses()` |
| `tests/TeamDeletionHardeningTest.php` | Dead `$count` removed; `team_questions` assertion added to cascade test; renamed `testDeleteTeamIsTransactional` → `testDeleteTeamSucceedsAtomically` |

**Test result**: 83 tests, 166 assertions — all pass
**PHPStan**: 0 errors at level 5

---

## Cycle count

**2 cycles** — Cycle 1 approved by Code Reviewer; Test Validator flagged 3 MAJOR false positives (tests 3/4/5 used hardcoded local variables with no production function on the call path).

---

## Lessons

1. **Extract before testing** — access-control logic in a page controller is not directly testable. Extracting `canAccessResponses()` and `canSeeAllMemberResponses()` as pure functions in `src/TeamRepository.php` made them testable and removed duplication between `responses.php` and the test file.
2. **False-positive test pattern to avoid** — a test that sets `$isOwner = false; $isDeveloper = false; $canAccess = $isOwner || $isDeveloper; assertFalse($canAccess)` tests nothing — it will pass regardless of what the production code does. Tests must call the real function under test.
3. **Rename tests to match what they test** — `testDeleteTeamIsTransactional` only tested the happy path (commit); it was renamed to `testDeleteTeamSucceedsAtomically` to avoid implying rollback coverage.
4. **Dead assignment detection** — `$count = $stmt->execute(...)` where `execute()` returns bool silently produces 0 or 1, not a row count. The actual count came from the subsequent `fetchColumn()` call. Dead assignments like this should be caught in review.
