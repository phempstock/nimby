-- NIMBY schema, part 1: planning applications and sync history.
-- Run once on the IONOS MySQL database (phpMyAdmin > SQL, or the mysql client over SSH).

CREATE TABLE IF NOT EXISTS applications (
    keyval          VARCHAR(20)   NOT NULL PRIMARY KEY,  -- council system key, used in Public Access links
    app_ref         VARCHAR(30)   NOT NULL,              -- e.g. 26/01848/PWC
    app_type        VARCHAR(10)   NULL,                  -- suffix of app_ref, e.g. PWC, PFUL3
    address         VARCHAR(255)  NULL,
    proposal        TEXT          NULL,                  -- council truncates at 300 characters
    date_received   DATE          NULL,
    date_valid      DATE          NULL,
    status          VARCHAR(100)  NULL,
    decision        VARCHAR(100)  NULL,
    decision_date   DATE          NULL,
    lat             DECIMAL(9,6)  NULL,
    lng             DECIMAL(9,6)  NULL,
    first_seen_at   DATETIME      NOT NULL,              -- drives the weekly digest
    last_seen_at    DATETIME      NOT NULL,
    updated_at      DATETIME      NULL,                  -- last time any tracked field changed
    removed_at      DATETIME      NULL,                  -- set if it disappears from the council feed
    backfilled      TINYINT(1)    NOT NULL DEFAULT 0,    -- loaded by the first run; never emailed
    INDEX idx_app_ref (app_ref),
    INDEX idx_first_seen (first_seen_at),
    INDEX idx_date_received (date_received)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_events (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    keyval      VARCHAR(20)   NOT NULL,
    field       VARCHAR(30)   NOT NULL,
    old_value   TEXT          NULL,
    new_value   TEXT          NULL,
    changed_at  DATETIME      NOT NULL,
    INDEX idx_events_keyval (keyval),
    INDEX idx_events_changed (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_at   DATETIME      NOT NULL,
    finished_at  DATETIME      NULL,
    status       VARCHAR(10)   NOT NULL,  -- running | ok | failed
    fetched      INT           NULL,
    inserted     INT           NULL,
    updated      INT           NULL,
    removed      INT           NULL,
    message      TEXT          NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
