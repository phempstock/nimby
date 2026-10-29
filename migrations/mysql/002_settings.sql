-- NIMBY schema, part 2: small key/value settings remembered between runs
-- (e.g. which area the last sync covered, so widening it is noticed).

CREATE TABLE IF NOT EXISTS settings (
    name   VARCHAR(50)  NOT NULL PRIMARY KEY,
    value  TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
