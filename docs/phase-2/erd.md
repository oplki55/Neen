# ERD

## 1. Mermaid ERD

```mermaid
erDiagram
    USERS ||--o{ USER_PROFILES : has
    USERS ||--o{ USER_SESSIONS : owns
    USERS ||--o{ USER_ROLES : has
    ROLES ||--o{ USER_ROLES : grants
    USERS ||--|| WALLETS : has
    WALLETS ||--o{ WALLET_TRANSACTIONS : records
    USERS ||--o{ GAME_SESSIONS : plays
    GAME_CATALOG ||--o{ GAME_SESSIONS : defines
    USERS ||--o{ USER_GAME_DAILY_ATTEMPTS : tracks
    GAME_CATALOG ||--o{ USER_GAME_DAILY_ATTEMPTS : configures
    GAME_SESSIONS ||--o{ ATTEMPT_USAGE : includes
    USERS ||--o{ REWARD_CLAIMS : submits
    REWARD_CAMPAIGNS ||--o{ REWARDS : contains
    REWARDS ||--o{ REWARD_CLAIMS : can_be_claimed
    USERS ||--o{ DIAMOND_ORDERS : creates
    DIAMOND_PACKAGES ||--o{ DIAMOND_ORDERS : offers
    DIAMOND_ORDERS ||--o{ PAYMENT_SUBMISSION_FILES : includes
    USERS ||--o{ AUDIT_LOGS : creates
    USERS ||--o{ ADMIN_GAME_ASSIGNMENTS : assigned_as
    GAME_CATALOG ||--o{ ADMIN_GAME_ASSIGNMENTS : assigned_to

    USERS {
      bigint id PK
      varchar username
      varchar email
      varchar password_hash
      enum status
      datetime created_at
    }

    USER_PROFILES {
      bigint id PK
      bigint user_id FK
      varchar display_name
      varchar avatar_url
      varchar country
      text bio
    }

    USER_SESSIONS {
      bigint id PK
      bigint user_id FK
      varchar session_token_hash
      datetime expires_at
    }

    ROLES {
      int id PK
      varchar name
    }

    USER_ROLES {
      bigint id PK
      bigint user_id FK
      int role_id FK
    }

    WALLETS {
      bigint id PK
      bigint user_id FK
      bigint coin_balance
      bigint diamond_balance
    }

    WALLET_TRANSACTIONS {
      bigint id PK
      bigint wallet_id FK
      bigint user_id FK
      enum type
      varchar category
      bigint amount
      bigint balance_before
      bigint balance_after
      varchar idempotency_key
    }

    GAME_CATALOG {
      int id PK
      varchar slug
      varchar name
      bigint attempt_price_coins
      int free_attempt_limit
      bigint reward_amount_coins
    }

    USER_GAME_DAILY_ATTEMPTS {
      bigint id PK
      bigint user_id FK
      int game_id FK
      date attempt_date
      int free_attempts_used
      int paid_attempts_used
    }

    GAME_SESSIONS {
      bigint id PK
      bigint user_id FK
      int game_id FK
      enum attempt_type
      enum status
      int score
      bigint reward_amount_coins
    }

    ATTEMPT_USAGE {
      bigint id PK
      bigint user_id FK
      int game_id FK
      bigint session_id FK
      enum attempt_kind
      bigint attempt_cost_coins
      tinyint was_consumed
    }

    REWARD_CAMPAIGNS {
      bigint id PK
      varchar title
      enum type
      datetime starts_at
      datetime ends_at
    }

    REWARDS {
      bigint id PK
      bigint campaign_id FK
      varchar code
      enum reward_type
      bigint reward_value
    }

    REWARD_CLAIMS {
      bigint id PK
      bigint user_id FK
      bigint reward_id FK
      bigint campaign_id FK
      enum status
      varchar idempotency_key
    }

    DIAMOND_PACKAGES {
      bigint id PK
      varchar country_code
      varchar name
      int diamond_amount
      decimal price
      varchar currency_code
    }

    DIAMOND_ORDERS {
      bigint id PK
      bigint user_id FK
      bigint package_id FK
      varchar payment_method
      varchar payment_reference
      varchar player_uid
      varchar contact_phone
      enum order_status
    }

    PAYMENT_SUBMISSION_FILES {
      bigint id PK
      bigint order_id FK
      bigint uploaded_by FK
      varchar storage_path
      varchar original_name
    }

    AUDIT_LOGS {
      bigint id PK
      bigint actor_user_id FK
      varchar action_type
      varchar entity_type
      bigint entity_id
      json payload
    }

    ADMIN_GAME_ASSIGNMENTS {
      bigint id PK
      bigint admin_user_id FK
      int game_id FK
      varchar role_name
    }
```

## 2. Relationship notes

- Each user has one wallet and optional profile metadata.
- Wallet transactions always belong to a wallet and a user.
- Game sessions are tied to one user and one game.
- A game usage event is linked to a specific session if applicable.
- Reward claims are tied to campaigns and reward definitions.
- Diamond orders are separate from wallet coins and must never be mixed at the balance layer.
- Audit logs record all sensitive activities.

## 3. Design interpretation

This entity relationship is intentionally normalized enough to support full implementation while keeping the final architecture easy to expand with new games or admin responsibilities.
