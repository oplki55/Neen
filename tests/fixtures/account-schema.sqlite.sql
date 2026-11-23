PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL UNIQUE,
    free_fire_id TEXT,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    email_verified INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_login_at TEXT
);
CREATE TABLE user_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    full_name TEXT,
    display_name TEXT,
    avatar_url TEXT,
    country TEXT,
    bio TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    description TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE role_permissions (
    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
    PRIMARY KEY (role_id, permission_id)
);
CREATE TABLE user_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES roles(id),
    granted_at TEXT NOT NULL,
    UNIQUE (user_id, role_id)
);
CREATE TABLE user_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_token_hash TEXT NOT NULL UNIQUE,
    csrf_token_hash TEXT NOT NULL,
    user_agent TEXT,
    ip_address TEXT,
    expires_at TEXT NOT NULL,
    last_activity_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE wallets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    coin_balance INTEGER NOT NULL DEFAULT 0 CHECK (coin_balance >= 0),
    purchased_coin_balance INTEGER NOT NULL DEFAULT 0 CHECK (purchased_coin_balance >= 0),
    earned_coin_balance INTEGER NOT NULL DEFAULT 0 CHECK (earned_coin_balance >= 0),
    diamond_balance INTEGER NOT NULL DEFAULT 0 CHECK (diamond_balance >= 0),
    currency_code TEXT NOT NULL DEFAULT 'EGP',
    updated_at TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 0,
    CHECK (coin_balance = purchased_coin_balance + earned_coin_balance)
);
CREATE TABLE password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE email_verification_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE auth_rate_limits (
    bucket_hash TEXT PRIMARY KEY,
    attempts INTEGER NOT NULL,
    window_started_at INTEGER NOT NULL
);
CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    actor_role TEXT,
    action_type TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER,
    payload TEXT,
    ip_address TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE site_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO site_settings (setting_key, setting_value, updated_at) VALUES
    ('tiktok_url', 'https://www.tiktok.com/@neen_0o', CURRENT_TIMESTAMP),
    ('site_description', 'NEEN — Free Fire content creator. Explore videos, character art, and more.', CURRENT_TIMESTAMP);

INSERT INTO roles (name, description, created_at) VALUES
    ('user', 'Standard registered account', CURRENT_TIMESTAMP),
    ('moderator', 'Moderation access', CURRENT_TIMESTAMP),
    ('game_admin', 'Game-specific administration access', CURRENT_TIMESTAMP),
    ('support_admin', 'Customer support and payment review access', CURRENT_TIMESTAMP),
    ('super_admin', 'Full administrative access', CURRENT_TIMESTAMP),
    ('owner', 'Dedicated site-owner access', CURRENT_TIMESTAMP);
INSERT INTO permissions (name, description, created_at) VALUES
    ('view_wallet', 'View wallet', CURRENT_TIMESTAMP),
    ('submit_diamond_order', 'Submit an order', CURRENT_TIMESTAMP),
    ('claim_reward', 'Claim a reward', CURRENT_TIMESTAMP),
    ('review_payment', 'Review a payment', CURRENT_TIMESTAMP),
    ('manage_game_settings', 'Manage game settings', CURRENT_TIMESTAMP),
    ('approve_reward', 'Approve a reward', CURRENT_TIMESTAMP),
    ('view_audit_logs', 'View audit logs', CURRENT_TIMESTAMP),
    ('adjust_wallet', 'Adjust wallet', CURRENT_TIMESTAMP);
INSERT INTO permissions (name, description, created_at) VALUES
    ('view_wallet_transactions', 'View wallet transaction history', CURRENT_TIMESTAMP),
    ('manage_coin_packages', 'Manage Coins packages', CURRENT_TIMESTAMP),
    ('manage_coin_payment_methods', 'Manage Coins payment methods', CURRENT_TIMESTAMP),
    ('review_coin_orders', 'Review Coins orders', CURRENT_TIMESTAMP),
    ('manage_homepage_rewards', 'Configure homepage rewards', CURRENT_TIMESTAMP),
    ('manage_community_events', 'Manage community events', CURRENT_TIMESTAMP),
    ('review_event_suggestions', 'Review event suggestions', CURRENT_TIMESTAMP),
    ('manage_event_rewards', 'Manage event rewards', CURRENT_TIMESTAMP),
    ('manage_diamond_packages', 'Manage diamond packages', CURRENT_TIMESTAMP),
    ('manage_diamond_orders', 'Manage diamond orders', CURRENT_TIMESTAMP),
    ('view_admin_users', 'View administrative users', CURRENT_TIMESTAMP),
    ('view_all_wallets', 'View all wallets', CURRENT_TIMESTAMP),
    ('access_owner_dashboard', 'Access owner dashboard', CURRENT_TIMESTAMP);
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r JOIN permissions p ON
    (r.name = 'user' AND p.name IN ('view_wallet', 'view_wallet_transactions'))
    OR r.name IN ('super_admin', 'owner');

CREATE TABLE coin_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    coin_amount INTEGER NOT NULL CHECK (coin_amount > 0),
    price TEXT NOT NULL,
    currency_code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE coin_payment_methods (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    instructions TEXT,
    proof_required INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE coin_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    package_id INTEGER NOT NULL REFERENCES coin_packages(id) ON DELETE RESTRICT,
    package_name TEXT NOT NULL,
    coin_amount INTEGER NOT NULL,
    price TEXT NOT NULL,
    currency_code TEXT NOT NULL,
    payment_method_id INTEGER NOT NULL REFERENCES coin_payment_methods(id) ON DELETE RESTRICT,
    payment_method_code TEXT NOT NULL,
    payment_method_name TEXT NOT NULL,
    proof_required INTEGER NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('awaiting_payment', 'pending_review', 'approved', 'rejected', 'cancelled')),
    idempotency_key TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, idempotency_key)
);
CREATE TABLE coin_order_evidence (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_order_id INTEGER NOT NULL REFERENCES coin_orders(id) ON DELETE RESTRICT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    payment_reference TEXT,
    proof_details TEXT,
    idempotency_key TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, idempotency_key),
    UNIQUE (coin_order_id)
);
CREATE TABLE coin_ledger (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    wallet_id INTEGER NOT NULL REFERENCES wallets(id) ON DELETE RESTRICT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    operation_type TEXT NOT NULL CHECK (operation_type IN ('credit', 'debit', 'reversal')),
    source TEXT NOT NULL,
    amount INTEGER NOT NULL CHECK (amount > 0),
    balance_type TEXT NOT NULL CHECK (balance_type IN ('purchased', 'earned')),
    balance_before INTEGER NOT NULL,
    balance_after INTEGER NOT NULL,
    total_balance_before INTEGER NOT NULL,
    total_balance_after INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'completed' CHECK (status = 'completed'),
    reference_type TEXT,
    reference_id INTEGER,
    idempotency_key TEXT NOT NULL,
    reversal_of_id INTEGER UNIQUE REFERENCES coin_ledger(id) ON DELETE RESTRICT,
    note TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, idempotency_key)
);
CREATE TABLE daily_attendance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    attendance_date TEXT NOT NULL,
    cycle_number INTEGER NOT NULL,
    cycle_day INTEGER NOT NULL CHECK (cycle_day BETWEEN 1 AND 7),
    reward_amount INTEGER NOT NULL DEFAULT 100 CHECK (reward_amount = 100),
    ledger_entry_id INTEGER UNIQUE REFERENCES coin_ledger(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, attendance_date),
    UNIQUE (user_id, cycle_number, cycle_day)
);
CREATE TABLE coin_order_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    coin_order_id INTEGER NOT NULL UNIQUE REFERENCES coin_orders(id) ON DELETE RESTRICT,
    reviewer_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    decision TEXT NOT NULL CHECK (decision IN ('approved', 'rejected')),
    reason TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    ledger_entry_id INTEGER REFERENCES coin_ledger(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    UNIQUE (reviewer_user_id, idempotency_key)
);
CREATE TRIGGER trg_coin_ledger_no_update
BEFORE UPDATE ON coin_ledger
BEGIN
    SELECT RAISE(ABORT, 'Coin ledger entries are immutable');
END;
CREATE TRIGGER trg_coin_ledger_no_delete
BEFORE DELETE ON coin_ledger
BEGIN
    SELECT RAISE(ABORT, 'Coin ledger entries are immutable');
END;

CREATE TABLE game_catalog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled', 'maintenance')),
    attempt_price_coins INTEGER NOT NULL DEFAULT 0,
    free_attempt_limit INTEGER NOT NULL DEFAULT 3,
    reward_amount_coins INTEGER NOT NULL DEFAULT 50,
    reset_timezone TEXT NOT NULL DEFAULT 'Africa/Cairo',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO game_catalog
    (slug, name, description, status, attempt_price_coins, free_attempt_limit,
     reward_amount_coins, reset_timezone, created_at, updated_at)
VALUES
    ('memory-match', 'Memory Match', 'Match pairs of NEEN character images.', 'active', 0, 3, 50, 'Africa/Cairo',
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
INSERT INTO game_catalog
    (slug, name, description, status, attempt_price_coins, free_attempt_limit,
     reward_amount_coins, reset_timezone, created_at, updated_at)
VALUES
    ('picture-jigsaw', 'Picture Jigsaw', 'Reassemble an original NEEN picture.', 'active', 0, 3, 50, 'Africa/Cairo',
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
INSERT INTO game_catalog
    (slug, name, description, status, attempt_price_coins, free_attempt_limit,
     reward_amount_coins, reset_timezone, created_at, updated_at)
VALUES
    ('vault_cracker', 'NEEN Vault Cracker', 'Crack the four-icon code in six guesses.', 'active', 0, 3, 50, 'Africa/Cairo',
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
INSERT INTO game_catalog
    (slug, name, description, status, attempt_price_coins, free_attempt_limit,
     reward_amount_coins, reset_timezone, created_at, updated_at)
VALUES
    ('loot_sequence', 'Loot Sequence Strike', 'Remember and repeat four escalating sticker sequences.', 'active', 0, 3, 50, 'Africa/Cairo',
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
INSERT INTO game_catalog
    (slug, name, description, status, attempt_price_coins, free_attempt_limit,
     reward_amount_coins, reset_timezone, created_at, updated_at)
VALUES
    ('sunny_island', 'NEEN Sunny Island', 'Explore Sunny Island and complete a daily adventure.', 'active', 0, 3, 50, 'Africa/Cairo',
     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);
CREATE TABLE user_game_daily_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    game_id INTEGER NOT NULL REFERENCES game_catalog(id) ON DELETE RESTRICT,
    attempt_date TEXT NOT NULL,
    free_attempts_used INTEGER NOT NULL DEFAULT 0,
    paid_attempts_used INTEGER NOT NULL DEFAULT 0,
    free_attempts_reset_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, game_id, attempt_date)
);
CREATE TABLE game_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    game_id INTEGER NOT NULL REFERENCES game_catalog(id) ON DELETE RESTRICT,
    attempt_type TEXT NOT NULL DEFAULT 'free' CHECK (attempt_type IN ('free', 'paid')),
    status TEXT NOT NULL CHECK (status IN ('started', 'in_progress', 'completed', 'failed', 'cancelled', 'compensated')),
    score INTEGER,
    move_count INTEGER NOT NULL DEFAULT 0,
    matched_pairs INTEGER NOT NULL DEFAULT 0,
    idempotency_key TEXT NOT NULL,
    started_at TEXT NOT NULL,
    last_activity_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    completed_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, idempotency_key)
);
CREATE INDEX idx_game_sessions_owner_active ON game_sessions (user_id, game_id, status, expires_at);
CREATE TABLE memory_match_states (
    session_id INTEGER PRIMARY KEY REFERENCES game_sessions(id) ON DELETE CASCADE,
    deck_json TEXT NOT NULL,
    matched_cards_json TEXT NOT NULL,
    open_cards_json TEXT NOT NULL,
    mismatch_at TEXT
);
CREATE TABLE picture_jigsaw_states (
    session_id INTEGER PRIMARY KEY REFERENCES game_sessions(id) ON DELETE CASCADE,
    image_id TEXT NOT NULL,
    permutation_json TEXT NOT NULL,
    board_size INTEGER NOT NULL CHECK (board_size >= 2)
);
CREATE TABLE vault_cracker_states (
    session_id INTEGER PRIMARY KEY REFERENCES game_sessions(id) ON DELETE CASCADE,
    secret_json TEXT NOT NULL,
    guesses_json TEXT NOT NULL
);
CREATE TABLE loot_sequence_states (
    session_id INTEGER PRIMARY KEY REFERENCES game_sessions(id) ON DELETE CASCADE,
    sequences_json TEXT NOT NULL,
    inputs_json TEXT NOT NULL,
    round_number INTEGER NOT NULL DEFAULT 1 CHECK (round_number BETWEEN 1 AND 4)
);
CREATE TABLE sunny_island_progress (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    xp INTEGER NOT NULL DEFAULT 0 CHECK (xp >= 0),
    level INTEGER NOT NULL DEFAULT 1 CHECK (level >= 1),
    unlocked_collectibles_json TEXT NOT NULL,
    discovered_zones_json TEXT NOT NULL,
    total_adventures_completed INTEGER NOT NULL DEFAULT 0 CHECK (total_adventures_completed >= 0),
    last_position_json TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE sunny_island_states (
    session_id INTEGER PRIMARY KEY REFERENCES game_sessions(id) ON DELETE CASCADE,
    contract_json TEXT NOT NULL,
    completed_objectives_json TEXT NOT NULL
);
CREATE TABLE sunny_island_action_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES game_sessions(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    request_key TEXT NOT NULL,
    payload_hash TEXT NOT NULL,
    action_type TEXT NOT NULL,
    target_id TEXT,
    status TEXT NOT NULL,
    response_payload_json TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (session_id, request_key)
);
CREATE TABLE homepage_reward_config (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    reward_amount INTEGER CHECK (reward_amount IS NULL OR reward_amount > 0),
    window_seconds INTEGER CHECK (window_seconds IS NULL OR window_seconds > 0),
    is_active INTEGER NOT NULL DEFAULT 0 CHECK (
        is_active = 0 OR (reward_amount IS NOT NULL AND window_seconds IS NOT NULL)
    ),
    version INTEGER NOT NULL DEFAULT 1,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);
INSERT INTO homepage_reward_config (id, reward_amount, window_seconds, is_active, version, updated_at)
VALUES (1, NULL, NULL, 0, 1, CURRENT_TIMESTAMP);
CREATE TABLE homepage_reward_claims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    window_key TEXT NOT NULL,
    reward_amount INTEGER NOT NULL CHECK (reward_amount > 0),
    ledger_entry_id INTEGER UNIQUE REFERENCES coin_ledger(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    UNIQUE (user_id, window_key)
);
CREATE TABLE community_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (
        status IN ('draft', 'published', 'paused', 'cancelled', 'results_published')
    ),
    created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    updated_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (ends_at > starts_at)
);
CREATE TABLE community_event_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES community_events(id) ON DELETE RESTRICT,
    label TEXT NOT NULL,
    position INTEGER NOT NULL,
    UNIQUE (event_id, position),
    UNIQUE (event_id, id)
);
CREATE TABLE community_event_suggestions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'rejected')),
    admin_note TEXT,
    reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE community_event_votes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    UNIQUE (event_id, user_id),
    FOREIGN KEY (event_id, option_id) REFERENCES community_event_options(event_id, id) ON DELETE RESTRICT
);
CREATE TABLE community_event_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    vote_count INTEGER NOT NULL,
    approved_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    approved_at TEXT NOT NULL,
    UNIQUE (event_id, option_id),
    FOREIGN KEY (event_id, option_id) REFERENCES community_event_options(event_id, id) ON DELETE RESTRICT
);
CREATE TABLE community_event_rewards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL REFERENCES community_events(id) ON DELETE RESTRICT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    reward_amount INTEGER NOT NULL CHECK (reward_amount > 0),
    reason TEXT NOT NULL,
    ledger_entry_id INTEGER UNIQUE REFERENCES coin_ledger(id) ON DELETE RESTRICT,
    awarded_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    UNIQUE (event_id, user_id)
);
CREATE TABLE community_event_activity (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER REFERENCES community_events(id) ON DELETE SET NULL,
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    activity_type TEXT NOT NULL,
    metadata TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE diamond_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    diamond_amount INTEGER NOT NULL CHECK (diamond_amount > 0),
    price TEXT NOT NULL,
    currency_code TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE diamond_recharge_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_number TEXT NOT NULL UNIQUE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    package_id INTEGER NOT NULL REFERENCES diamond_packages(id) ON DELETE RESTRICT,
    package_name TEXT NOT NULL,
    diamond_amount INTEGER NOT NULL CHECK (diamond_amount > 0),
    price TEXT NOT NULL,
    currency_code TEXT NOT NULL,
    player_id TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    order_status TEXT NOT NULL CHECK (
        order_status IN ('pending', 'payment_verification', 'paid', 'processing', 'delivered', 'rejected', 'failed', 'refunded', 'cancelled')
    ),
    payment_status TEXT NOT NULL CHECK (
        payment_status IN ('pending', 'verification', 'paid', 'rejected', 'failed', 'refunded')
    ),
    delivery_status TEXT NOT NULL CHECK (
        delivery_status IN ('not_started', 'processing', 'delivered', 'failed', 'refunded')
    ),
    payment_reference TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, idempotency_key)
);
CREATE TABLE diamond_order_activity (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL REFERENCES diamond_recharge_orders(id) ON DELETE RESTRICT,
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    activity_type TEXT NOT NULL,
    from_order_status TEXT,
    to_order_status TEXT NOT NULL,
    payment_status TEXT NOT NULL,
    delivery_status TEXT NOT NULL,
    admin_note TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE news_ticker_settings (
    singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    theme TEXT NOT NULL DEFAULT 'pink_indigo',
    speed_seconds INTEGER NOT NULL DEFAULT 55 CHECK (speed_seconds BETWEEN 20 AND 120),
    background_color TEXT NOT NULL DEFAULT '#17132F',
    text_color TEXT NOT NULL DEFAULT '#FFF8FC',
    sender_color TEXT NOT NULL DEFAULT '#F3A3C6',
    separator_color TEXT NOT NULL DEFAULT '#C8C8ED',
    glow_color TEXT NOT NULL DEFAULT '#D65F96',
    updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE news_ticker_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_name TEXT NOT NULL,
    message TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Approved', 'Rejected', 'Deleted')),
    display_active INTEGER NOT NULL DEFAULT 0 CHECK (display_active IN (0, 1)),
    ip_address TEXT,
    source TEXT NOT NULL DEFAULT 'Visitor' CHECK (source IN ('Visitor', 'Owner')),
    approved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    approved_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX idx_news_ticker_public ON news_ticker_messages(status, display_active, approved_at);
CREATE INDEX idx_news_ticker_status_created ON news_ticker_messages(status, created_at);

INSERT INTO news_ticker_settings
    (singleton, enabled, theme, speed_seconds, background_color, text_color,
     sender_color, separator_color, glow_color, updated_at)
VALUES
    (1, 1, 'pink_indigo', 55, '#17132F', '#FFF8FC', '#F3A3C6', '#C8C8ED', '#D65F96', CURRENT_TIMESTAMP);
