# Database Schema

## 1. Design assumptions

The schema below is intentionally explicit so it can be translated directly to MySQL definitions later. This is a design-document phase and no database is created in this repository.

## 2. Conventions

- All primary keys are unsigned integers unless explicitly stated otherwise.
- `created_at`, `updated_at`, and `deleted_at` follow UTC timestamps.
- `JSON` fields are used for flexible metadata where required.
- All sensitive actions must be logged in `audit_logs`.
- `uuid` or random token generation may be used where appropriate for non-guessable references.

## 3. Core tables

### 3.1 users

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | unique internal user identifier |
| username | VARCHAR(50) | NOT NULL, UNIQUE | public handle |
| email | VARCHAR(255) | NOT NULL, UNIQUE | account email |
| password_hash | VARCHAR(255) | NOT NULL | hashed password |
| status | ENUM('active','suspended','deleted','pending_verification') | NOT NULL | account state |
| email_verified | TINYINT(1) | NOT NULL DEFAULT 0 | verification status |
| created_at | DATETIME | NOT NULL | account creation |
| updated_at | DATETIME | NOT NULL | last update |
| last_login_at | DATETIME | NULL | last successful login |

Indexes:

- `idx_users_status` on `status`
- `idx_users_email` on `email`
- `idx_users_username` on `username`

### 3.2 user_profiles

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, UNIQUE, FK -> users.id | user profile |
| full_name | VARCHAR(150) | NULL | full name |
| display_name | VARCHAR(80) | NULL | public display name |
| avatar_url | VARCHAR(255) | NULL | avatar or profile image |
| country | VARCHAR(80) | NULL | user country |
| bio | TEXT | NULL | profile description |
| created_at | DATETIME | NOT NULL | |
| updated_at | DATETIME | NOT NULL | |

Indexes:

- `idx_user_profiles_user_id` on `user_id`

### 3.3 user_sessions

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | owner |
| session_token_hash | VARCHAR(255) | NOT NULL, UNIQUE | hashed session token |
| user_agent | TEXT | NULL | browser metadata |
| ip_address | VARCHAR(45) | NULL | IPv4/IPv6 |
| expires_at | DATETIME | NOT NULL | session expiry |
| revoked_at | DATETIME | NULL | invalidation marker |
| created_at | DATETIME | NOT NULL | |

Indexes:

- `idx_user_sessions_user_id` on `user_id`
- `idx_user_sessions_expires_at` on `expires_at`

### 3.4 roles

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | |
| name | VARCHAR(60) | NOT NULL, UNIQUE | role name |
| description | TEXT | NULL | role purpose |
| created_at | DATETIME | NOT NULL | |

### 3.5 permissions

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | |
| name | VARCHAR(80) | NOT NULL, UNIQUE | permission key |
| description | TEXT | NULL | permission description |
| created_at | DATETIME | NOT NULL | |

### 3.6 user_roles

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| role_id | INT UNSIGNED | NOT NULL, FK -> roles.id | |
| granted_at | DATETIME | NOT NULL | |

Unique:

- `UNIQUE(user_id, role_id)`

Indexes:

- `idx_user_roles_user_id` on `user_id`
- `idx_user_roles_role_id` on `role_id`

### 3.7 game_catalog

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | |
| slug | VARCHAR(80) | NOT NULL, UNIQUE | internal code |
| name | VARCHAR(120) | NOT NULL | display name |
| description | TEXT | NULL | game description |
| status | ENUM('active','disabled','maintenance') | NOT NULL DEFAULT 'active' | |
| attempt_price_coins | BIGINT | NOT NULL DEFAULT 0 | price of a paid attempt |
| free_attempt_limit | INT | NOT NULL DEFAULT 3 | free attempts per day |
| reward_amount_coins | BIGINT | NOT NULL DEFAULT 50 | fixed reward per successful win |
| reset_timezone | VARCHAR(64) | NOT NULL DEFAULT 'Africa/Cairo' | daily reset timezone |
| created_at | DATETIME | NOT NULL | |
| updated_at | DATETIME | NOT NULL | |

Indexes:

- `idx_game_catalog_slug` on `slug`
- `idx_game_catalog_status` on `status`

### 3.8 user_game_daily_attempts

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| game_id | INT UNSIGNED | NOT NULL, FK -> game_catalog.id | |
| attempt_date | DATE | NOT NULL | reset period date |
| free_attempts_used | INT | NOT NULL DEFAULT 0 | number of free attempts consumed |
| paid_attempts_used | INT | NOT NULL DEFAULT 0 | number of paid attempts consumed |
| free_attempts_reset_at | DATETIME | NOT NULL | last reset timestamp |
| created_at | DATETIME | NOT NULL | |
| updated_at | DATETIME | NOT NULL | |

Unique:

- `UNIQUE(user_id, game_id, attempt_date)`

Indexes:

- `idx_user_game_daily_attempts_user_game` on `user_id, game_id`
- `idx_user_game_daily_attempts_date` on `attempt_date`

### 3.9 game_sessions

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| game_id | INT UNSIGNED | NOT NULL, FK -> game_catalog.id | |
| attempt_type | ENUM('free','paid') | NOT NULL | |
| status | ENUM('started','in_progress','completed','failed','cancelled','compensated') | NOT NULL | session state |
| score | INT | NULL | game score |
| reward_awarded | TINYINT(1) | NOT NULL DEFAULT 0 | if reward granted |
| reward_amount_coins | BIGINT | NOT NULL DEFAULT 0 | awarded reward |
| started_at | DATETIME | NOT NULL | |
| completed_at | DATETIME | NULL | |
| created_at | DATETIME | NOT NULL | |
| updated_at | DATETIME | NOT NULL | |

Indexes:

- `idx_game_sessions_user_id` on `user_id`
- `idx_game_sessions_game_id` on `game_id`
- `idx_game_sessions_status` on `status`

### 3.10 attempt_usage

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| game_id | INT UNSIGNED | NOT NULL, FK -> game_catalog.id | |
| session_id | BIGINT UNSIGNED | NULL, FK -> game_sessions.id | owning session |
| attempt_kind | ENUM('free','paid') | NOT NULL | |
| attempt_cost_coins | BIGINT | NOT NULL DEFAULT 0 | actual cost, 0 for free |
| was_consumed | TINYINT(1) | NOT NULL DEFAULT 0 | if the attempt was actually used |
| consumption_status | ENUM('pending','used','rolled_back','compensated') | NOT NULL DEFAULT 'pending' | |
| started_at | DATETIME | NOT NULL | |
| consumed_at | DATETIME | NULL | |
| created_at | DATETIME | NOT NULL | |

Indexes:

- `idx_attempt_usage_user_game` on `user_id, game_id`
- `idx_attempt_usage_session_id` on `session_id`

### 3.11 wallets

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, UNIQUE, FK -> users.id | |
| coin_balance | BIGINT | NOT NULL DEFAULT 0 | current wallet bias |
| diamond_balance | BIGINT | NOT NULL DEFAULT 0 | separate NEEN Diamond balance |
| currency_code | VARCHAR(10) | NOT NULL DEFAULT 'EGP' | localized stable unit |
| updated_at | DATETIME | NOT NULL | |
| version | BIGINT UNSIGNED | NOT NULL DEFAULT 0 | optimistic locking marker |

Indexes:

- `idx_wallets_user_id` on `user_id`

### 3.12 wallet_transactions

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| wallet_id | BIGINT UNSIGNED | NOT NULL, FK -> wallets.id | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | denormalized for easy lookup |
| type | ENUM('credit','debit','adjustment','refund','hold','release') | NOT NULL | |
| category | VARCHAR(60) | NOT NULL | e.g. game_win |
| amount | BIGINT | NOT NULL | amount value |
| balance_before | BIGINT | NOT NULL | |
| balance_after | BIGINT | NOT NULL | |
| reference_type | VARCHAR(80) | NULL | game_session, reward_claim |
| reference_id | BIGINT UNSIGNED | NULL | |
| idempotency_key | VARCHAR(255) | NOT NULL, UNIQUE | prevents duplicate posting |
| status | ENUM('pending','completed','failed','reversed') | NOT NULL DEFAULT 'completed' | |
| note | TEXT | NULL | reason |
| created_at | DATETIME | NOT NULL | |

Indexes:

- `idx_wallet_transactions_wallet_id` on `wallet_id`
- `idx_wallet_transactions_user_id` on `user_id`
- `idx_wallet_transactions_reference` on `reference_type, reference_id`
- `idx_wallet_transactions_created_at` on `created_at`

### 3.13 reward_campaigns

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| title | VARCHAR(150) | NOT NULL | |
| description | TEXT | NULL | |
| type | ENUM('weekly','special','event') | NOT NULL | |
| starts_at | DATETIME | NOT NULL | |
| ends_at | DATETIME | NULL | |
| status | ENUM('draft','active','expired','cancelled') | NOT NULL | |
| created_by | BIGINT UNSIGNED | NULL, FK -> users.id | |
| created_at | DATETIME | NOT NULL | |

### 3.14 rewards

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| campaign_id | BIGINT UNSIGNED | NOT NULL, FK -> reward_campaigns.id | |
| code | VARCHAR(80) | NOT NULL, UNIQUE | reward code |
| name | VARCHAR(120) | NOT NULL | |
| reward_type | ENUM('coins','badge','cosmetic','physical') | NOT NULL | |
| reward_value | BIGINT | NULL | number of coins or item value |
| requirement_type | VARCHAR(80) | NULL | e.g. participation_score |
| requirement_value | INT | NULL | threshold value |
| active | TINYINT(1) | NOT NULL DEFAULT 1 | |
| created_at | DATETIME | NOT NULL | |

### 3.15 reward_claims

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| reward_id | BIGINT UNSIGNED | NOT NULL, FK -> rewards.id | |
| campaign_id | BIGINT UNSIGNED | NOT NULL, FK -> reward_campaigns.id | |
| status | ENUM('pending','approved','rejected','processed','cancelled') | NOT NULL DEFAULT 'pending' | |
| claimed_at | DATETIME | NOT NULL | |
| processed_by | BIGINT UNSIGNED | NULL, FK -> users.id | reviewer |
| processed_at | DATETIME | NULL | |
| note | TEXT | NULL | review rationale |
| idempotency_key | VARCHAR(255) | NOT NULL, UNIQUE | prevents duplicate claims |

Indexes:

- `idx_reward_claims_user_id` on `user_id`
- `idx_reward_claims_reward_id` on `reward_id`
- `idx_reward_claims_campaign_id` on `campaign_id`

### 3.16 diamond_packages

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| country_code | VARCHAR(10) | NOT NULL | e.g. JO, EG |
| name | VARCHAR(120) | NOT NULL | package display name |
| diamond_amount | INT UNSIGNED | NOT NULL | number of NEEN diamonds |
| price | DECIMAL(10,2) | NOT NULL | package price |
| currency_code | VARCHAR(10) | NOT NULL | e.g. JOD, EGP |
| is_active | TINYINT(1) | NOT NULL DEFAULT 1 | package availability |
| created_at | DATETIME | NOT NULL | |
| updated_at | DATETIME | NOT NULL | |

### 3.17 diamond_orders

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| package_id | BIGINT UNSIGNED | NOT NULL, FK -> diamond_packages.id | |
| payment_method | VARCHAR(80) | NOT NULL | method name |
| payment_reference | VARCHAR(150) | NULL | external reference |
| player_uid | VARCHAR(50) | NOT NULL | player identifier |
| contact_phone | VARCHAR(30) | NOT NULL | contact number |
| order_status | ENUM('pending_review','approved','rejected','paid','fulfilled','cancelled') | NOT NULL | business state |
| total_price | DECIMAL(10,2) | NOT NULL | |
| currency_code | VARCHAR(10) | NOT NULL | |
| reviewed_by | BIGINT UNSIGNED | NULL, FK -> users.id | admin reviewer |
| reviewed_at | DATETIME | NULL | |
| created_at | DATETIME | NOT NULL | |
| updated_at | DATETIME | NOT NULL | |

Indexes:

- `idx_diamond_orders_user_id` on `user_id`
- `idx_diamond_orders_status` on `order_status`
- `idx_diamond_orders_payment_reference` on `payment_reference`

### 3.18 payment_submission_files

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| order_id | BIGINT UNSIGNED | NOT NULL, FK -> diamond_orders.id | |
| uploaded_by | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| storage_path | VARCHAR(255) | NOT NULL | secure file path |
| original_name | VARCHAR(255) | NOT NULL | original file name |
| mime_type | VARCHAR(120) | NOT NULL | upload type |
| size_bytes | INT UNSIGNED | NOT NULL | |
| status | ENUM('submitted','accepted','rejected') | NOT NULL DEFAULT 'submitted' | |
| created_at | DATETIME | NOT NULL | |

### 3.19 admin_roles

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | |
| name | VARCHAR(80) | NOT NULL, UNIQUE | e.g. super_admin |
| description | TEXT | NULL | |
| created_at | DATETIME | NOT NULL | |

### 3.20 admin_game_assignments

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| admin_user_id | BIGINT UNSIGNED | NOT NULL, FK -> users.id | |
| game_id | INT UNSIGNED | NOT NULL, FK -> game_catalog.id | |
| role_name | VARCHAR(80) | NOT NULL | e.g. game_manager |
| assigned_at | DATETIME | NOT NULL | |

Unique:

- `UNIQUE(admin_user_id, game_id, role_name)`

### 3.21 audit_logs

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT | |
| actor_user_id | BIGINT UNSIGNED | NULL, FK -> users.id | user performing action |
| actor_role | VARCHAR(80) | NULL | actor role label |
| action_type | VARCHAR(80) | NOT NULL | e.g. wallet_credit |
| entity_type | VARCHAR(80) | NOT NULL | table or module name |
| entity_id | BIGINT UNSIGNED | NULL | affected entity |
| payload | JSON | NULL | event details |
| ip_address | VARCHAR(45) | NULL | source IP |
| user_agent | TEXT | NULL | browser metadata |
| created_at | DATETIME | NOT NULL | |

Indexes:

- `idx_audit_logs_actor_user_id` on `actor_user_id`
- `idx_audit_logs_action_type` on `action_type`
- `idx_audit_logs_created_at` on `created_at`

### 3.22 system_settings

| Column | Type | Constraints | Notes |
|---|---|---|---|
| id | INT UNSIGNED | PK, AUTO_INCREMENT | |
| setting_key | VARCHAR(120) | NOT NULL, UNIQUE | key name |
| setting_value | TEXT | NOT NULL | JSON or serialized setting |
| description | TEXT | NULL | |
| updated_by | BIGINT UNSIGNED | NULL, FK -> users.id | |
| updated_at | DATETIME | NOT NULL | |

## 4. Referential integrity rules

- A wallet must exist for every user.
- A wallet transaction must always reference a valid wallet and user.
- A game session must always belong to a valid user and game.
- A reward claim must belong to a valid user and reward.
- A diamond order must belong to a valid user and package.
- Audit rows may be created without a user actor but must not conflict with the schema.

## 5. Constraints and guardrails

- `coin_balance` must never be below zero in normal operations.
- `wallet_transactions` must use unique idempotency keys.
- `reward_claims` must prevent duplicate claims using a unique composite or idempotency key.
- `user_game_daily_attempts` must prevent multiple daily counters for same user and game.
- `user_roles` must prevent duplicate role assignment.

## 6. Implementation note

This schema is designed to support future real backend implementation and may be converted to MySQL DDL without structural adjustments, as long as the business rules stay unchanged.
