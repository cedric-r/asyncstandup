-- AsyncStandUp test schema — SQLite-compatible version of db/schema.sql
-- Transformations from MySQL:
--   INT UNSIGNED / TINYINT(1) -> INTEGER
--   AUTO_INCREMENT PRIMARY KEY -> INTEGER PRIMARY KEY AUTOINCREMENT
--   VARCHAR(n) / TIME / DATE / DATETIME -> TEXT
--   ENGINE= / CHARSET= / DEFAULT (UTC_TIMESTAMP()) -> removed
--   UNIQUE KEY uq_... (col1, col2) -> UNIQUE(col1, col2) constraint
--   FOREIGN KEY syntax kept (enforced via PRAGMA foreign_keys = ON in createTestPdo)
--   SET NAMES / SET foreign_key_checks -> removed

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    email          TEXT NOT NULL UNIQUE,
    password_hash  TEXT NOT NULL,
    display_name   TEXT,
    timezone       TEXT NOT NULL DEFAULT 'UTC',
    is_admin       INTEGER NOT NULL DEFAULT 0,
    account_status TEXT NOT NULL DEFAULT 'pending',
    created_at     TEXT NOT NULL DEFAULT '',
    teams_aad_id           TEXT NULL,
    teams_conversation_ref TEXT NULL
);

CREATE TABLE IF NOT EXISTS organisations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    created_by  INTEGER NULL,
    created_at  TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS org_members (
    org_id  INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    PRIMARY KEY (org_id, user_id),
    FOREIGN KEY (org_id)  REFERENCES organisations(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS teams (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    org_id       INTEGER NOT NULL,
    name         TEXT NOT NULL,
    timezone     TEXT NOT NULL,
    standup_time TEXT NOT NULL,
    summary_to_all_developers INTEGER NOT NULL DEFAULT 0,
    frequency      TEXT    NOT NULL DEFAULT 'daily',
    frequency_day  INTEGER NULL,
    status       TEXT NOT NULL DEFAULT 'active',
    created_by   INTEGER NULL,
    created_at   TEXT NOT NULL DEFAULT '',
    notification_channel   TEXT NOT NULL DEFAULT 'email',
    teams_webhook_url      TEXT NULL,
    teams_channel_name     TEXT NULL,
    teams_conversation_ref TEXT NULL,
    FOREIGN KEY (org_id)     REFERENCES organisations(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS team_members (
    team_id      INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    is_owner     INTEGER NOT NULL DEFAULT 0,
    is_developer INTEGER NOT NULL DEFAULT 0,
    is_recipient INTEGER NOT NULL DEFAULT 0,
    joined_at    TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (team_id, user_id),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS team_questions (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id    INTEGER NOT NULL,
    question   TEXT NOT NULL,
    position   INTEGER NOT NULL,
    is_blocker INTEGER NOT NULL DEFAULT 0,
    is_mood    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (team_id) REFERENCES teams(id)
);

CREATE TABLE IF NOT EXISTS team_recipients (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id           INTEGER NOT NULL,
    email             TEXT NOT NULL,
    display_name      TEXT,
    added_by          INTEGER,
    unsubscribe_token TEXT NULL UNIQUE,
    created_at        TEXT NOT NULL DEFAULT '',
    UNIQUE(team_id, email),
    FOREIGN KEY (team_id)  REFERENCES teams(id),
    FOREIGN KEY (added_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS invitations (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id        INTEGER NOT NULL,
    invited_email  TEXT NOT NULL,
    token          TEXT NOT NULL UNIQUE,
    invited_by     INTEGER NOT NULL,
    intended_roles TEXT NOT NULL DEFAULT 'developer',
    created_at     TEXT NOT NULL DEFAULT '',
    accepted_at    TEXT NULL,
    FOREIGN KEY (team_id)    REFERENCES teams(id),
    FOREIGN KEY (invited_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS standup_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id    INTEGER NOT NULL,
    user_id    INTEGER NULL,
    token      TEXT NOT NULL UNIQUE,
    send_date  TEXT NOT NULL,
    sent_at    TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at          TEXT NULL,
    reminder_sent_at TEXT NULL,
    UNIQUE(team_id, user_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS standup_submissions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    token_id     INTEGER NOT NULL UNIQUE,
    user_id      INTEGER NULL,
    team_id      INTEGER NOT NULL,
    submitted_at TEXT NOT NULL DEFAULT '',
    FOREIGN KEY (token_id) REFERENCES standup_tokens(id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (team_id)  REFERENCES teams(id)
);

CREATE TABLE IF NOT EXISTS standup_answers (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    question_id   INTEGER NOT NULL,
    answer        TEXT,
    FOREIGN KEY (submission_id) REFERENCES standup_submissions(id),
    FOREIGN KEY (question_id)   REFERENCES team_questions(id)
);

CREATE TABLE IF NOT EXISTS summary_sent (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id   INTEGER NOT NULL,
    send_date TEXT NOT NULL,
    sent_at   TEXT NOT NULL DEFAULT '',
    UNIQUE(team_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id)
);

CREATE TABLE IF NOT EXISTS password_resets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    token      TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT '',
    expires_at TEXT NOT NULL,
    used_at    TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS login_attempts (
    email            TEXT    NOT NULL PRIMARY KEY,
    attempt_count    INTEGER NOT NULL DEFAULT 0,
    first_attempt_at TEXT    NOT NULL,
    locked_until     TEXT
);

CREATE TABLE IF NOT EXISTS password_reset_requests (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    requested_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS standup_mood_scores (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    question_id   INTEGER NOT NULL,
    score         INTEGER NOT NULL,
    scored_at     TEXT NOT NULL,
    UNIQUE(submission_id, question_id),
    FOREIGN KEY (submission_id) REFERENCES standup_submissions(id),
    FOREIGN KEY (question_id)   REFERENCES team_questions(id)
);

CREATE TABLE IF NOT EXISTS api_keys (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    key_hash     TEXT NOT NULL UNIQUE,
    name         TEXT NOT NULL DEFAULT '',
    created_at   TEXT NOT NULL DEFAULT '',
    last_used_at TEXT NULL,
    revoked_at   TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS api_request_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    key_hash     TEXT NOT NULL,
    requested_at TEXT NOT NULL
);
