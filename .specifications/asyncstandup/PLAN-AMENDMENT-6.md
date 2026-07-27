# PLAN-AMENDMENT-6 — US-14: RepositoryTest.php test additions

**Status**: APPROVED
**Branch**: feature/asyncstandup-fixes
**Commit**: 6a19b5a

## Unplanned file modification

| Action | File | Note |
|---|---|---|
| Modify | `tests/RepositoryTest.php` | 4 tests for getMergedRecipients() |

## Rationale

Test Validator MAJOR finding (Cycle 1): getMergedRecipients() is a PDO-injectable
src/ function with deduplication logic requiring tests. RepositoryTest.php was created
in US-9 — not listed in the US-14 IMPL-PLAN because the requirement emerged during
review. 4 cases added: external-only, member-only, case-insensitive dedup, both-no-overlap.
