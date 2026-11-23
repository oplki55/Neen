CREATE TABLE IF NOT EXISTS game_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(80) NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'disabled', 'maintenance') NOT NULL DEFAULT 'active',
    attempt_price_coins BIGINT NOT NULL DEFAULT 0,
    free_attempt_limit INT NOT NULL DEFAULT 3,
    reward_amount_coins BIGINT NOT NULL DEFAULT 50,
    reset_timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Cairo',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_catalog_slug (slug),
    KEY idx_game_catalog_status (status),
    CONSTRAINT chk_game_catalog_attempt_price CHECK (attempt_price_coins >= 0),
    CONSTRAINT chk_game_catalog_free_attempts CHECK (free_attempt_limit >= 0),
    CONSTRAINT chk_game_catalog_reward CHECK (reward_amount_coins >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_game_daily_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NOT NULL,
    attempt_date DATE NOT NULL,
    free_attempts_used INT UNSIGNED NOT NULL DEFAULT 0,
    paid_attempts_used INT UNSIGNED NOT NULL DEFAULT 0,
    free_attempts_reset_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_daily_attempt_user_day (user_id, game_id, attempt_date),
    CONSTRAINT fk_game_daily_attempt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_game_daily_attempt_game FOREIGN KEY (game_id) REFERENCES game_catalog (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    game_id INT UNSIGNED NOT NULL,
    attempt_type ENUM('free', 'paid') NOT NULL DEFAULT 'free',
    status ENUM('started', 'in_progress', 'completed', 'failed', 'cancelled', 'compensated') NOT NULL,
    score INT NULL,
    move_count INT UNSIGNED NOT NULL DEFAULT 0,
    matched_pairs INT UNSIGNED NOT NULL DEFAULT 0,
    idempotency_key VARCHAR(100) NOT NULL,
    started_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_game_sessions_user_idempotency (user_id, idempotency_key),
    KEY idx_game_sessions_owner_active (user_id, game_id, status, expires_at),
    CONSTRAINT fk_game_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_game_sessions_game FOREIGN KEY (game_id) REFERENCES game_catalog (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memory_match_states (
    session_id BIGINT UNSIGNED NOT NULL,
    deck_json JSON NOT NULL,
    matched_cards_json JSON NOT NULL,
    open_cards_json JSON NOT NULL,
    mismatch_at DATETIME(3) NULL,
    PRIMARY KEY (session_id),
    CONSTRAINT fk_memory_match_state_session FOREIGN KEY (session_id) REFERENCES game_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vault_cracker_states (
    session_id BIGINT UNSIGNED NOT NULL,
    secret_json JSON NOT NULL,
    guesses_json JSON NOT NULL,
    PRIMARY KEY (session_id),
    CONSTRAINT fk_vault_cracker_state_session FOREIGN KEY (session_id) REFERENCES game_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loot_sequence_states (
    session_id BIGINT UNSIGNED NOT NULL,
    sequences_json JSON NOT NULL,
    inputs_json JSON NOT NULL,
    round_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (session_id),
    CONSTRAINT chk_loot_sequence_round CHECK (round_number BETWEEN 1 AND 4),
    CONSTRAINT fk_loot_sequence_state_session FOREIGN KEY (session_id) REFERENCES game_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sunny_island_progress (
    user_id BIGINT UNSIGNED NOT NULL,
    xp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    unlocked_collectibles_json JSON NOT NULL,
    discovered_zones_json JSON NOT NULL,
    total_adventures_completed BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_position_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT chk_sunny_island_level CHECK (level >= 1),
    CONSTRAINT fk_sunny_island_progress_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sunny_island_states (
    session_id BIGINT UNSIGNED NOT NULL,
    contract_json JSON NOT NULL,
    completed_objectives_json JSON NOT NULL,
    PRIMARY KEY (session_id),
    CONSTRAINT fk_sunny_island_state_session FOREIGN KEY (session_id) REFERENCES game_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sunny_island_action_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    request_key VARCHAR(100) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    action_type VARCHAR(32) NOT NULL,
    target_id VARCHAR(80) NULL,
    status VARCHAR(16) NOT NULL,
    response_payload_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sunny_action_request (session_id, request_key),
    KEY idx_sunny_action_request_user (user_id, created_at),
    CONSTRAINT fk_sunny_action_request_session FOREIGN KEY (session_id) REFERENCES game_sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_sunny_action_request_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_catalog
    (slug, name, description, status, attempt_price_coins, free_attempt_limit,
     reward_amount_coins, reset_timezone, created_at, updated_at)
VALUES
    ('memory-match', 'Memory Match', 'Match pairs of NEEN character images.', 'active', 0, 3, 50, 'Africa/Cairo',
     UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('picture-jigsaw', 'Picture Jigsaw', 'Reassemble an original NEEN picture.', 'active', 0, 3, 50, 'Africa/Cairo',
     UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('vault_cracker', 'NEEN Vault Cracker', 'Crack the four-icon code in six guesses.', 'active', 0, 3, 50, 'Africa/Cairo',
     UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('loot_sequence', 'Loot Sequence Strike', 'Remember and repeat four escalating sticker sequences.', 'active', 0, 3, 50, 'Africa/Cairo',
     UTC_TIMESTAMP(), UTC_TIMESTAMP()),
    ('sunny_island', 'NEEN Sunny Island', 'Explore Sunny Island and complete a daily adventure.', 'active', 0, 3, 50, 'Africa/Cairo',
     UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    free_attempt_limit = VALUES(free_attempt_limit),
    reward_amount_coins = VALUES(reward_amount_coins),
    reset_timezone = VALUES(reset_timezone);

CREATE TABLE IF NOT EXISTS picture_jigsaw_states (
    session_id BIGINT UNSIGNED NOT NULL,
    image_id VARCHAR(60) NOT NULL,
    permutation_json JSON NOT NULL,
    board_size TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (session_id),
    CONSTRAINT chk_picture_jigsaw_board_size CHECK (board_size >= 2),
    CONSTRAINT fk_picture_jigsaw_state_session FOREIGN KEY (session_id) REFERENCES game_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
