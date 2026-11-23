CREATE TABLE IF NOT EXISTS homepage_reward_config (
    id TINYINT UNSIGNED NOT NULL,
    reward_amount BIGINT UNSIGNED NULL,
    window_seconds BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_homepage_reward_config_singleton CHECK (id = 1),
    CONSTRAINT chk_homepage_reward_config_values CHECK (
        (reward_amount IS NULL OR reward_amount > 0)
        AND (window_seconds IS NULL OR window_seconds > 0)
        AND (is_active = 0 OR (reward_amount IS NOT NULL AND window_seconds IS NOT NULL))
    ),
    CONSTRAINT fk_homepage_reward_config_admin FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO homepage_reward_config (id, reward_amount, window_seconds, is_active, version, updated_at)
VALUES (1, NULL, NULL, 0, 1, UTC_TIMESTAMP());

CREATE TABLE IF NOT EXISTS homepage_reward_claims (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    window_key VARCHAR(60) NOT NULL,
    reward_amount BIGINT UNSIGNED NOT NULL,
    ledger_entry_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_homepage_reward_claim_user_window (user_id, window_key),
    UNIQUE KEY uq_homepage_reward_claim_ledger (ledger_entry_id),
    KEY idx_homepage_reward_claim_created (created_at),
    CONSTRAINT fk_homepage_reward_claim_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_homepage_reward_claim_ledger FOREIGN KEY (ledger_entry_id) REFERENCES coin_ledger (id) ON DELETE RESTRICT,
    CONSTRAINT chk_homepage_reward_claim_amount CHECK (reward_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
