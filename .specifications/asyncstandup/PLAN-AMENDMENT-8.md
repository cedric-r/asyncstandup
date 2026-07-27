# PLAN-AMENDMENT-8 — US-16/17: tests/schema-sqlite.sql update

**Status**: APPROVED
**Branch**: feature/asyncstandup-admin

## Unplanned file modification

| Action | File | Note |
|---|---|---|
| Modify | `tests/schema-sqlite.sql` | Mirror db/schema.sql changes for US-16/17: is_admin, account_status, nullable created_by FKs |

## Rationale

Test schema updated to match production schema changes from US-16 (nullable user FKs)
and US-17 (is_admin, account_status columns). Required for existing tests to pass.
Plan-writing oversight — file not listed in IMPL-PLAN or PLAN-AMENDMENT-7.
