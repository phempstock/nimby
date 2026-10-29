-- SQLite equivalent of migrations/mysql/002_settings.sql.

CREATE TABLE IF NOT EXISTS settings (
    name   TEXT NOT NULL PRIMARY KEY,
    value  TEXT NULL
);
