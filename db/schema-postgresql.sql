-- AsyncStandUp schema — PostgreSQL
-- Run on a fresh database only. Not a migration file.
-- For MySQL schema: db/schema.sql
-- For SQLite test schema: tests/schema-sqlite.sql
--
-- All TIMESTAMP columns store UTC. Timezone conversion is handled in PHP only.
-- CASCADE deletes are handled in PHP (not DB) to make the order explicit.

CREATE TABLE IF NOT EXISTS users (
    id             SERIAL PRIMARY KEY,
    email          TEXT NOT NULL UNIQUE,
    password_hash  TEXT NOT NULL,
    display_name   TEXT,
    timezone       TEXT NOT NULL DEFAULT 'UTC',
    is_admin       BOOLEAN NOT NULL DEFAULT FALSE,
    account_status TEXT NOT NULL DEFAULT 'pending',
    created_at     TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS organisations (
    id         SERIAL PRIMARY KEY,
    name       TEXT NOT NULL,
    created_by INTEGER NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS org_members (
    org_id  INTEGER NOT NULL REFERENCES organisations(id),
    user_id INTEGER NOT NULL REFERENCES users(id),
    PRIMARY KEY (org_id, user_id)
);

CREATE TABLE IF NOT EXISTS teams (
    id                        SERIAL PRIMARY KEY,
    org_id                    INTEGER NOT NULL REFERENCES organisations(id),
    name                      TEXT NOT NULL,
    timezone                  TEXT NOT NULL,
    standup_time              TIME NOT NULL,
    summary_to_all_developers BOOLEAN NOT NULL DEFAULT FALSE,
    frequency                 VARCHAR(10) NOT NULL DEFAULT 'daily',
    frequency_day             SMALLINT NULL,
    status                    TEXT NOT NULL DEFAULT 'active',
    created_by                INTEGER NULL REFERENCES users(id),
    created_at                TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS team_members (
    team_id      INTEGER NOT NULL REFERENCES teams(id),
    user_id      INTEGER NOT NULL REFERENCES users(id),
    is_owner     BOOLEAN NOT NULL DEFAULT FALSE,
    is_developer BOOLEAN NOT NULL DEFAULT FALSE,
    is_recipient BOOLEAN NOT NULL DEFAULT FALSE,
    joined_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (team_id, user_id)
);

CREATE TABLE IF NOT EXISTS team_questions (
    id         SERIAL PRIMARY KEY,
    team_id    INTEGER NOT NULL REFERENCES teams(id),
    question   TEXT NOT NULL,
    position   INTEGER NOT NULL,
    is_blocker BOOLEAN NOT NULL DEFAULT FALSE,
    is_mood    BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS team_recipients (
    id                SERIAL PRIMARY KEY,
    team_id           INTEGER NOT NULL REFERENCES teams(id),
    email             TEXT NOT NULL,
    display_name      TEXT,
    added_by          INTEGER NULL REFERENCES users(id),
    unsubscribe_token TEXT NULL UNIQUE,
    created_at        TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_team_recipient UNIQUE (team_id, email)
);

CREATE TABLE IF NOT EXISTS invitations (
    id             SERIAL PRIMARY KEY,
    team_id        INTEGER NOT NULL REFERENCES teams(id),
    invited_email  TEXT NOT NULL,
    token          TEXT NOT NULL UNIQUE,
    invited_by     INTEGER NOT NULL REFERENCES users(id),
    intended_roles TEXT NOT NULL DEFAULT 'developer',
    created_at     TIMESTAMP NOT NULL DEFAULT NOW(),
    accepted_at    TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS standup_tokens (
    id         SERIAL PRIMARY KEY,
    team_id    INTEGER NOT NULL REFERENCES teams(id),
    user_id    INTEGER NULL REFERENCES users(id),
    token      TEXT NOT NULL UNIQUE,
    send_date  DATE NOT NULL,
    sent_at    TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at          TIMESTAMP NULL,
    reminder_sent_at TIMESTAMP NULL,
    CONSTRAINT uq_token_team_user_date UNIQUE (team_id, user_id, send_date)
);

CREATE TABLE IF NOT EXISTS standup_submissions (
    id           SERIAL PRIMARY KEY,
    token_id     INTEGER NOT NULL UNIQUE REFERENCES standup_tokens(id),
    user_id      INTEGER NULL REFERENCES users(id),
    team_id      INTEGER NOT NULL REFERENCES teams(id),
    submitted_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS standup_answers (
    id            SERIAL PRIMARY KEY,
    submission_id INTEGER NOT NULL REFERENCES standup_submissions(id),
    question_id   INTEGER NOT NULL REFERENCES team_questions(id),
    answer        TEXT
);

CREATE TABLE IF NOT EXISTS summary_sent (
    id        SERIAL PRIMARY KEY,
    team_id   INTEGER NOT NULL REFERENCES teams(id),
    send_date DATE NOT NULL,
    sent_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    CONSTRAINT uq_summary_team_date UNIQUE (team_id, send_date)
);

CREATE TABLE IF NOT EXISTS password_resets (
    id         SERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    token      TEXT NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL,
    used_at    TIMESTAMP NULL
);

-- US-24: login attempt tracking for rate limiting
CREATE TABLE IF NOT EXISTS login_attempts (
    email            TEXT NOT NULL PRIMARY KEY,
    attempt_count    INTEGER NOT NULL DEFAULT 0,
    first_attempt_at TIMESTAMP NOT NULL,
    locked_until     TIMESTAMP NULL
);

-- US-24: separate request log for rate limiting (never deleted)
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id),
    requested_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_prrq_user_time ON password_reset_requests (user_id, requested_at);

-- US-26: team suspension (run on existing PostgreSQL installs)
ALTER TABLE teams ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'active';

-- US-29: submission reminder tracking
ALTER TABLE standup_tokens ADD COLUMN IF NOT EXISTS reminder_sent_at TIMESTAMP NULL;

-- US-30: configurable frequency
ALTER TABLE teams ADD COLUMN IF NOT EXISTS frequency     VARCHAR(10) NOT NULL DEFAULT 'daily';
ALTER TABLE teams ADD COLUMN IF NOT EXISTS frequency_day SMALLINT NULL;

-- US-31: blocker question flagging
ALTER TABLE team_questions ADD COLUMN IF NOT EXISTS is_blocker BOOLEAN NOT NULL DEFAULT FALSE;
-- US-32: mood question flagging
ALTER TABLE team_questions ADD COLUMN IF NOT EXISTS is_mood    BOOLEAN NOT NULL DEFAULT FALSE;

-- US-32: mood scores
CREATE TABLE IF NOT EXISTS standup_mood_scores (
    id            SERIAL PRIMARY KEY,
    submission_id INTEGER NOT NULL REFERENCES standup_submissions(id),
    question_id   INTEGER NOT NULL REFERENCES team_questions(id),
    score         SMALLINT NOT NULL,
    scored_at     TIMESTAMP NOT NULL,
    CONSTRAINT uq_mood_submission_question UNIQUE (submission_id, question_id)
);

-- US-33: public REST API
CREATE TABLE IF NOT EXISTS api_keys (
    id           SERIAL PRIMARY KEY,
    user_id      INTEGER NOT NULL REFERENCES users(id),
    key_hash     VARCHAR(64) NOT NULL UNIQUE,
    label        VARCHAR(100) NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS api_request_log (
    id           SERIAL PRIMARY KEY,
    key_hash     VARCHAR(64) NOT NULL,
    requested_at TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_api_log_key_time ON api_request_log (key_hash, requested_at);

-- US-35: API key management — rename label → name, add soft-delete column
ALTER TABLE api_keys RENAME COLUMN label TO name;
ALTER TABLE api_keys ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMP NULL;

-- US-36: MS Teams integration — teams columns
ALTER TABLE teams ADD COLUMN IF NOT EXISTS notification_channel   VARCHAR(10)  NOT NULL DEFAULT 'email';
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_webhook_url      VARCHAR(500) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_channel_name     VARCHAR(100) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_conversation_ref TEXT         NULL;

-- US-36: MS Teams integration — users columns
ALTER TABLE users ADD COLUMN IF NOT EXISTS teams_aad_id           VARCHAR(100) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS teams_conversation_ref TEXT         NULL;

-- US-40: Teams delivery error tracking
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_last_error    VARCHAR(255) NULL;
ALTER TABLE teams ADD COLUMN IF NOT EXISTS teams_last_error_at TIMESTAMP   NULL;
