ALTER TABLE account CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE profile CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE sent CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE received CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE account ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 0;
UPDATE account SET enabled = IFNULL(active, 0);

ALTER TABLE profile ADD COLUMN plec VARCHAR(64) NULL;
ALTER TABLE profile ADD COLUMN wojewodztwo VARCHAR(128) NULL;
ALTER TABLE profile ADD COLUMN stan_cywilny VARCHAR(128) NULL;
ALTER TABLE profile ADD COLUMN najbardziej_lubie VARCHAR(255) NULL;
ALTER TABLE profile ADD COLUMN online_status VARCHAR(255) NULL;
ALTER TABLE profile ADD COLUMN updated_at DATETIME NULL;

CREATE INDEX idx_profile_profile_id ON profile(profile_id);
CREATE INDEX idx_profile_last_seen ON profile(last_seen);
CREATE INDEX idx_account_enabled ON account(enabled);
CREATE INDEX idx_sent_account_id ON sent(account_id);
CREATE INDEX idx_sent_recipient_id ON sent(recipient_id);

CREATE TABLE IF NOT EXISTS message_attempt (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT NOT NULL,
    recipient_id INT NOT NULL,
    status VARCHAR(32) NOT NULL,
    error_message VARCHAR(500) NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'manual',
    message_preview VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_message_attempt_account (account_id),
    KEY idx_message_attempt_recipient (recipient_id),
    KEY idx_message_attempt_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sync_run (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind VARCHAR(64) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sync_run_kind (kind),
    KEY idx_sync_run_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

