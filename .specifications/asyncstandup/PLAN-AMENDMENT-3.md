# PLAN-AMENDMENT-3 — US-9: PHPUnit PHAR gitignore entry

**Status**: APPROVED
**Branch**: feature/asyncstandup-tests-pwreset
**Commit**: a2b2196

## Unplanned file modification

| Action | File | Note |
|---|---|---|
| Modify | `.gitignore` | Added `tests/phpunit.phar` entry |

## Rationale

The IMPL-PLAN PHPUnit PHAR Strategy section explicitly states
"Add tests/phpunit.phar to .gitignore" but the file list table
does not include `.gitignore`. The modification is implied by
the approved plan text and is a single-line addition with no
behaviour impact on production code.
