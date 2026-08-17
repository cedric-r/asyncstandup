-- AsyncStandUp schema
-- All DATETIME columns store UTC. Timezone conversion is handled in PHP only.
-- CASCADE deletes are handled in PHP (not DB) to make the order explicit.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email          VARCHAR(255) NOT NULL UNIQUE,
    password_hash  VARCHAR(255) NOT NULL,
    display_name   VARCHAR(100),
    timezone       VARCHAR(50)  NOT NULL DEFAULT 'UTC',
    is_admin       TINYINT(1)   NOT NULL DEFAULT 0,
    account_status VARCHAR(10)  NOT NULL DEFAULT 'pending',
    created_at     DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organisations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS org_members (
    org_id  INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (org_id, user_id),
    FOREIGN KEY (org_id)  REFERENCES organisations(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS teams (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id                    INT UNSIGNED NOT NULL,
    name                      VARCHAR(255) NOT NULL,
    timezone                  VARCHAR(50) NOT NULL,
    standup_time              TIME NOT NULL,
    summary_to_all_developers TINYINT(1) NOT NULL DEFAULT 0,
    created_by                INT UNSIGNED NULL,
    created_at                DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (org_id)     REFERENCES organisations(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_members (
    team_id      INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    is_owner     TINYINT(1) NOT NULL DEFAULT 0,
    is_developer TINYINT(1) NOT NULL DEFAULT 0,
    is_recipient TINYINT(1) NOT NULL DEFAULT 0,
    joined_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (team_id, user_id),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_questions (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id    INT UNSIGNED NOT NULL,
    question   VARCHAR(500) NOT NULL,
    position   INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS team_recipients (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id           INT UNSIGNED NOT NULL,
    email             VARCHAR(255) NOT NULL,
    display_name      VARCHAR(100),
    added_by          INT UNSIGNED,
    unsubscribe_token VARCHAR(64) NULL UNIQUE,
    created_at        DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    UNIQUE KEY uq_team_recipient (team_id, email),
    FOREIGN KEY (team_id)  REFERENCES teams(id),
    FOREIGN KEY (added_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invitations (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id        INT UNSIGNED NOT NULL,
    invited_email  VARCHAR(255) NOT NULL,
    token          VARCHAR(64) NOT NULL UNIQUE,
    invited_by     INT UNSIGNED NOT NULL,
    intended_roles VARCHAR(50) NOT NULL DEFAULT 'developer',
    created_at     DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    accepted_at    DATETIME NULL,
    FOREIGN KEY (team_id)    REFERENCES teams(id),
    FOREIGN KEY (invited_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS standup_tokens (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    send_date  DATE NOT NULL,
    sent_at    DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    UNIQUE KEY uq_token_team_user_date (team_id, user_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS standup_submissions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_id     INT UNSIGNED NOT NULL UNIQUE,
    user_id      INT UNSIGNED NULL,
    team_id      INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    FOREIGN KEY (token_id) REFERENCES standup_tokens(id),
    FOREIGN KEY (user_id)  REFERENCES users(id),
    FOREIGN KEY (team_id)  REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS standup_answers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    submission_id INT UNSIGNED NOT NULL,
    question_id   INT UNSIGNED NOT NULL,
    answer        TEXT,
    FOREIGN KEY (submission_id) REFERENCES standup_submissions(id),
    FOREIGN KEY (question_id)   REFERENCES team_questions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS summary_sent (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id   INT UNSIGNED NOT NULL,
    send_date DATE NOT NULL,
    sent_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    UNIQUE KEY uq_summary_team_date (team_id, send_date),
    FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Migration: US-16 + US-17 (run on existing deployments)
-- ============================================================================

-- US-16: make user_id nullable on archival tables (submissions/tokens survive
--        user deletion); make created_by nullable on orgs/teams.
ALTER TABLE standup_submissions MODIFY user_id INT UNSIGNED NULL;
ALTER TABLE standup_tokens      MODIFY user_id INT UNSIGNED NULL;
ALTER TABLE organisations       MODIFY created_by INT UNSIGNED NULL;
ALTER TABLE teams               MODIFY created_by INT UNSIGNED NULL;

-- US-17: add admin flag + account status to users.
ALTER TABLE users
    ADD COLUMN is_admin       TINYINT(1)  NOT NULL DEFAULT 0,
    ADD COLUMN account_status VARCHAR(10) NOT NULL DEFAULT 'pending';

-- Approve all existing users so they are not locked out after migration.
UPDATE users SET account_status = 'approved' WHERE account_status = 'pending';

-- US-20: unsubscribe token for team_recipients
ALTER TABLE team_recipients ADD COLUMN unsubscribe_token VARCHAR(64) NULL UNIQUE;

-- US-21: send summary to all developers flag
ALTER TABLE teams ADD COLUMN summary_to_all_developers TINYINT(1) NOT NULL DEFAULT 0;

-- US-24 Fix 3: login attempt tracking for rate limiting
CREATE TABLE IF NOT EXISTS login_attempts (
    email            VARCHAR(255) NOT NULL PRIMARY KEY,
    attempt_count    INT          NOT NULL DEFAULT 0,
    first_attempt_at DATETIME     NOT NULL,
    locked_until     DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE login_attempts MODIFY email VARCHAR(255) NOT NULL;  -- idempotent self-ref to mark migration point

-- US-24 Fix 2C: separate request log for rate limiting (never deleted)
-- Allows Fix 2B (DELETE unused tokens) and Fix 2C (rate limit) to work independently.
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL,
    INDEX idx_prrq_user_time (user_id, requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- US-26: team suspension
ALTER TABLE teams ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active';

-- US-29: submission reminder tracking
ALTER TABLE standup_tokens ADD COLUMN reminder_sent_at DATETIME NULL;

-- US-30: configurable frequency
ALTER TABLE teams ADD COLUMN frequency     VARCHAR(10) NOT NULL DEFAULT 'daily';
ALTER TABLE teams ADD COLUMN frequency_day TINYINT(1)  NULL;
