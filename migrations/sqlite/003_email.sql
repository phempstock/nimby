-- SQLite equivalent of migrations/mysql/003_email.sql.

CREATE TABLE IF NOT EXISTS subscribers (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    email            TEXT    NOT NULL UNIQUE,
    name             TEXT    NULL,
    status           TEXT    NOT NULL,
    confirm_hash     TEXT    NULL,
    confirm_sent_at  TEXT    NULL,
    created_at       TEXT    NOT NULL,
    confirmed_at     TEXT    NULL,
    unsubscribed_at  TEXT    NULL
);
CREATE INDEX IF NOT EXISTS idx_subscribers_status ON subscribers (status);

CREATE TABLE IF NOT EXISTS signup_attempts (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_hash  TEXT    NOT NULL,
    at       TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_signup_attempts ON signup_attempts (ip_hash, at);

CREATE TABLE IF NOT EXISTS digests (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    period_start  TEXT    NOT NULL,
    period_end    TEXT    NOT NULL,
    created_at    TEXT    NOT NULL,
    applications  INTEGER NOT NULL,
    status        TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS digest_sends (
    digest_id      INTEGER NOT NULL,
    subscriber_id  INTEGER NOT NULL,
    sent_at        TEXT    NULL,
    error          TEXT    NULL,
    PRIMARY KEY (digest_id, subscriber_id)
);
