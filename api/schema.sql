CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(254) NOT NULL,
    free_fire_id VARCHAR(20) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'suspended', 'deleted', 'pending_verification') NOT NULL DEFAULT 'active',
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(150) NULL,
    display_name VARCHAR(80) NULL,
    avatar_url VARCHAR(255) NULL,
    country VARCHAR(80) NULL,
    bio TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_profiles_user_id (user_id),
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    granted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_roles_user_role (user_id, role_id),
    KEY idx_user_roles_role_id (role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_token_hash CHAR(64) NOT NULL,
    csrf_token_hash CHAR(64) NOT NULL,
    user_agent VARCHAR(512) NULL,
    ip_address VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    last_activity_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_sessions_token_hash (session_token_hash),
    KEY idx_user_sessions_user_expiry (user_id, expires_at),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    coin_balance BIGINT NOT NULL DEFAULT 0,
    purchased_coin_balance BIGINT NOT NULL DEFAULT 0,
    earned_coin_balance BIGINT NOT NULL DEFAULT 0,
    diamond_balance BIGINT NOT NULL DEFAULT 0,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'EGP',
    updated_at DATETIME NOT NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallets_user_id (user_id),
    CONSTRAINT chk_wallets_nonnegative_balances CHECK (
        coin_balance >= 0 AND purchased_coin_balance >= 0 AND earned_coin_balance >= 0 AND diamond_balance >= 0
    ),
    CONSTRAINT chk_wallets_coin_bucket_total CHECK (
        coin_balance = purchased_coin_balance + earned_coin_balance
    ),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_user_expiry (user_id, expires_at),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_verification_token_hash (token_hash),
    KEY idx_email_verification_user_expiry (user_id, expires_at),
    CONSTRAINT fk_email_verification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket_hash CHAR(64) NOT NULL,
    attempts INT UNSIGNED NOT NULL,
    window_started_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (bucket_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_role VARCHAR(80) NULL,
    action_type VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    payload JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_logs_actor (actor_user_id),
    KEY idx_audit_logs_action (action_type),
    KEY idx_audit_logs_created (created_at),
    CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(80) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (setting_key),
    CONSTRAINT fk_site_settings_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES
    ('tiktok_url', 'https://www.tiktok.com/@neen_0o', UTC_TIMESTAMP()),
    ('site_description', 'NEEN — Free Fire content creator. Explore videos, character art, and more.', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO roles (name, description, created_at) VALUES
    ('user', 'Standard registered account', UTC_TIMESTAMP()),
    ('moderator', 'Moderation access', UTC_TIMESTAMP()),
    ('game_admin', 'Game-specific administration access', UTC_TIMESTAMP()),
    ('support_admin', 'Customer support and payment review access', UTC_TIMESTAMP()),
    ('super_admin', 'Full administrative access', UTC_TIMESTAMP()),
    ('owner', 'Dedicated site-owner access', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO permissions (name, description, created_at) VALUES
    ('view_wallet', 'View the current wallet snapshot', UTC_TIMESTAMP()),
    ('submit_diamond_order', 'Submit a diamond order', UTC_TIMESTAMP()),
    ('claim_reward', 'Claim an eligible reward', UTC_TIMESTAMP()),
    ('review_payment', 'Review a payment submission', UTC_TIMESTAMP()),
    ('manage_game_settings', 'Manage assigned game settings', UTC_TIMESTAMP()),
    ('approve_reward', 'Approve an eligible reward claim', UTC_TIMESTAMP()),
    ('view_audit_logs', 'View administrative audit logs', UTC_TIMESTAMP()),
    ('adjust_wallet', 'Perform an authorized wallet adjustment', UTC_TIMESTAMP()),
    ('view_wallet_transactions', 'View wallet transaction history', UTC_TIMESTAMP()),
    ('manage_coin_packages', 'Create and manage NEEN Coins packages', UTC_TIMESTAMP()),
    ('manage_coin_payment_methods', 'Configure Coins payment methods', UTC_TIMESTAMP()),
    ('review_coin_orders', 'Review and approve Coins purchase orders', UTC_TIMESTAMP()),
    ('manage_homepage_rewards', 'Configure homepage reward eligibility and value', UTC_TIMESTAMP()),
    ('manage_community_events', 'Create and manage community events', UTC_TIMESTAMP()),
    ('review_event_suggestions', 'Review community event suggestions', UTC_TIMESTAMP()),
    ('manage_event_rewards', 'Issue and review community event rewards', UTC_TIMESTAMP()),
    ('manage_diamond_packages', 'Create and manage diamond packages', UTC_TIMESTAMP()),
    ('manage_diamond_orders', 'Review and fulfill diamond recharge orders', UTC_TIMESTAMP()),
    ('view_admin_users', 'View users, administrative roles, and effective permissions', UTC_TIMESTAMP()),
    ('view_all_wallets', 'View all wallet balances and Coins ledger entries', UTC_TIMESTAMP()),
    ('access_owner_dashboard', 'Access the owner-only control dashboard and APIs', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON
    (r.name = 'user' AND p.name IN ('view_wallet', 'view_wallet_transactions'))
    OR r.name IN ('super_admin', 'owner');
