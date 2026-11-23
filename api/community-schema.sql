CREATE TABLE IF NOT EXISTS community_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('draft', 'published', 'paused', 'cancelled', 'results_published') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_community_events_status_dates (status, starts_at, ends_at),
    CONSTRAINT fk_community_events_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_community_events_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_community_events_dates CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_event_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(150) NOT NULL,
    position INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_event_option_position (event_id, position),
    UNIQUE KEY uq_community_event_option_pair (event_id, id),
    CONSTRAINT fk_community_event_options_event FOREIGN KEY (event_id) REFERENCES community_events (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_event_suggestions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    admin_note VARCHAR(1000) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_community_suggestions_status_created (status, created_at),
    KEY idx_community_suggestions_user (user_id, created_at),
    CONSTRAINT fk_community_suggestions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_community_suggestions_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_event_votes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_event_vote_user (event_id, user_id),
    KEY idx_community_event_vote_option (event_id, option_id),
    CONSTRAINT fk_community_event_vote_option FOREIGN KEY (event_id, option_id)
        REFERENCES community_event_options (event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_community_event_vote_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_event_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NOT NULL,
    vote_count BIGINT UNSIGNED NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_event_result_option (event_id, option_id),
    CONSTRAINT fk_community_event_result_option FOREIGN KEY (event_id, option_id)
        REFERENCES community_event_options (event_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_community_event_result_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_event_rewards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reward_amount BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    ledger_entry_id BIGINT UNSIGNED NULL,
    awarded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_community_event_reward_user (event_id, user_id),
    UNIQUE KEY uq_community_event_reward_ledger (ledger_entry_id),
    CONSTRAINT fk_community_event_reward_event FOREIGN KEY (event_id) REFERENCES community_events (id) ON DELETE RESTRICT,
    CONSTRAINT fk_community_event_reward_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_community_event_reward_ledger FOREIGN KEY (ledger_entry_id) REFERENCES coin_ledger (id) ON DELETE RESTRICT,
    CONSTRAINT fk_community_event_reward_admin FOREIGN KEY (awarded_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_community_event_reward_amount CHECK (reward_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS community_event_activity (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    activity_type VARCHAR(80) NOT NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_community_event_activity_event (event_id, created_at),
    KEY idx_community_event_activity_actor (actor_user_id, created_at),
    CONSTRAINT fk_community_event_activity_event FOREIGN KEY (event_id) REFERENCES community_events (id) ON DELETE SET NULL,
    CONSTRAINT fk_community_event_activity_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
