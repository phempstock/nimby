-- NIMBY schema, part 3: weekly email subscribers and the digests sent to them.

CREATE TABLE IF NOT EXISTS subscribers (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(254)  NOT NULL,
    name             VARCHAR(100)  NULL,
    status           VARCHAR(12)   NOT NULL,           -- pending | active | unsubscribed
    confirm_hash     CHAR(64)      NULL,               -- sha256 of the emailed confirmation token
    confirm_sent_at  DATETIME      NULL,
    created_at       DATETIME      NOT NULL,
    confirmed_at     DATETIME      NULL,               -- evidence of consent (double opt-in)
    unsubscribed_at  DATETIME      NULL,
    UNIQUE KEY uq_subscribers_email (email),
    INDEX idx_subscribers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-up attempts by hashed IP address, kept for a day to limit abuse of the form.
CREATE TABLE IF NOT EXISTS signup_attempts (
    id       INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_hash  CHAR(64)      NOT NULL,
    at       DATETIME      NOT NULL,
    INDEX idx_signup_attempts (ip_hash, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digests (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    period_start  DATETIME      NOT NULL,             -- applications first seen from here...
    period_end    DATETIME      NOT NULL,             -- ...up to (not including) here
    created_at    DATETIME      NOT NULL,
    applications  INT           NOT NULL,
    status        VARCHAR(12)   NOT NULL              -- empty | sending | sent
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per digest per subscriber, so a re-run never emails anyone twice.
CREATE TABLE IF NOT EXISTS digest_sends (
    digest_id      INT UNSIGNED  NOT NULL,
    subscriber_id  INT UNSIGNED  NOT NULL,
    sent_at        DATETIME      NULL,                -- null while it has only failed
    error          TEXT          NULL,
    PRIMARY KEY (digest_id, subscriber_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
