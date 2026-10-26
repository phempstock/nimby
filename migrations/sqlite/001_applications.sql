-- SQLite equivalent of migrations/mysql/001_applications.sql, for local development and tests.

CREATE TABLE IF NOT EXISTS applications (
    keyval          TEXT    NOT NULL PRIMARY KEY,
    app_ref         TEXT    NOT NULL,
    app_type        TEXT    NULL,
    address         TEXT    NULL,
    proposal        TEXT    NULL,
    date_received   TEXT    NULL,
    date_valid      TEXT    NULL,
    status          TEXT    NULL,
    decision        TEXT    NULL,
    decision_date   TEXT    NULL,
    lat             REAL    NULL,
    lng             REAL    NULL,
    first_seen_at   TEXT    NOT NULL,
    last_seen_at    TEXT    NOT NULL,
    updated_at      TEXT    NULL,
    removed_at      TEXT    NULL,
    backfilled      INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_app_ref ON applications (app_ref);
CREATE INDEX IF NOT EXISTS idx_first_seen ON applications (first_seen_at);

CREATE TABLE IF NOT EXISTS application_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    keyval      TEXT    NOT NULL,
    field       TEXT    NOT NULL,
    old_value   TEXT    NULL,
    new_value   TEXT    NULL,
    changed_at  TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_events_keyval ON application_events (keyval);

CREATE TABLE IF NOT EXISTS sync_runs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at   TEXT    NOT NULL,
    finished_at  TEXT    NULL,
    status       TEXT    NOT NULL,
    fetched      INTEGER NULL,
    inserted     INTEGER NULL,
    updated      INTEGER NULL,
    removed      INTEGER NULL,
    message      TEXT    NULL
);
