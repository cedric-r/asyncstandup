-- AsyncStandUp schema
-- All DATETIME columns store UTC. Timezone conversion is handled in PHP only.
-- CASCADE deletes are handled in PHP (not DB) to make the order explicit.

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(100),
    timezone      VARCHAR(50) NOT NULL DEFAULT 'UTC',
    created_at    DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP())
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organisations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    created_by  INT UNSIGNED NOT NULL,
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
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    org_id       INT UNSIGNED NOT NULL,
    name         VARCHAR(255) NOT NULL,
    timezone     VARCHAR(50) NOT NULL,
    standup_time TIME NOT NULL,
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
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
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    team_id      INT UNSIGNED NOT NULL,
    email        VARCHAR(255) NOT NULL,
    display_name VARCHAR(100),
    added_by     INT UNSIGNED,
    created_at   DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
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
    user_id    INT UNSIGNED NOT NULL,
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
    user_id      INT UNSIGNED NOT NULL,
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
