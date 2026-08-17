# RETRO — US-32: Mood / Sentiment Tracking

**Story**: US-32 — Mood / Sentiment Tracking
**Branch**: `feature/us-31-32-question-metadata` (shared with US-31)
**Merge commit**: `a16483e`
**Review cycles**: 1
**Date**: 2026-08-17

---

## What was built

| File | Change |
|---|---|
| `tests/schema-sqlite.sql` | Added `is_mood INTEGER NOT NULL DEFAULT 0` to `CREATE TABLE team_questions`; appended `CREATE TABLE standup_mood_scores` (SQLite) |
| `db/schema.sql` | Appended `is_mood` ALTER TABLE + `CREATE TABLE standup_mood_scores` (MySQL, InnoDB) |
| `db/schema-postgresql.sql` | Added `is_mood BOOLEAN NOT NULL DEFAULT FALSE` inline + ALTER TABLE IF NOT EXISTS + `standup_mood_scores` table (SERIAL, SMALLINT, TIMESTAMP, CONSTRAINT UNIQUE) |
| `src/StandupEmailer.php` | Added `scoreMoodAnswer(string): ?int` — pure function, 5 score tiers, regex+emoji matching, returns null for unrecognised input |
| `src/TeamRepository.php` | Added `setMoodQuestion()`, `clearMoodQuestion()` — same two-UPDATE pattern as blocker helpers |
| `src/SubmissionRepository.php` | Added `recordMoodScore()` (PDOException catch for duplicate idempotency); called in `saveSubmission()` inside the existing transaction, before `commit()` |
| `src/DashboardRepository.php` | Added `getMoodTrend(PDO, int, string, string): array` — daily AVG/COUNT over `standup_mood_scores` for 30-day window |
| `public/teams/dashboard.php` | "Mood Trend" section: `getQuestions()` called, `$hasMoodQuestion` flag, text table + emoji legend; rendered for owners only |
| `public/teams/questions.php` | `set_mood` / `clear_mood` POST actions + per-question 😊 toggle buttons |
| `tests/MoodTrackingTest.php` | 3 tests: `scoreMoodAnswer` known patterns, null for unrecognised, `setMoodQuestion` one-per-team invariant |

**Test result**: 95 tests, 184 assertions — all pass (US-31 + US-32 together)
**PHPStan**: 0 errors at level 5

---

## Cycle count

**1 cycle** — Gate D approved on first submission.

---

## Notes

1. **`recordMoodScore()` portability** — Used `try/catch (PDOException)` for duplicate-key detection rather than driver-specific upsert syntax (`ON DUPLICATE KEY UPDATE` / `ON CONFLICT`). This is the safest cross-driver approach and keeps the function identical for MySQL, PostgreSQL, and SQLite.
2. **Mood scoring inside `saveSubmission()` transaction** — The `recordMoodScore()` call is placed after the answers loop and before `$pdo->commit()`. A mood scoring failure triggers `rollBack()` via the existing `catch (Throwable)` — this is intentional: mood score is part of submission atomicity.
3. **`dashboard.php` — `getQuestions()` not previously called** — `dashboard.php` previously only called `DashboardRepository` functions. Added `require_once TeamRepository.php` was already present via `require_once` chain; `getQuestions()` call added for `$hasMoodQuestion` detection before rendering mood section.
4. **STORY.md AC-3 upsert note** — Spec suggested `dbInsertIgnore()` from US-25 or a driver-aware approach. Chose plain INSERT + `PDOException` catch instead — simpler, no dependency on `Db.php` helper which is cron-facing.
