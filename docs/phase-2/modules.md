# Modules and Responsibilities

## 1. Core modules

### 1.1 Auth Module
Responsible for:

- user registration
- login and logout
- password hashing and verification
- session creation
- token rotation and invalidation
- account locking and suspension

### 1.2 User Module
Responsible for:

- profile creation
- display name and avatar metadata
- country and contact settings
- user status management

### 1.3 Wallet Module
Responsible for:

- wallet creation upon user registration
- coin balance maintenance
- debit and credit operations
- ledger storage and review
- zero-balance initial state
- balance reconciliation and validation

### 1.4 Games Module
Responsible for:

- defining supported games
- storing configured attempt pricing
- per-user daily free attempt counters
- paid attempt usage
- game session lifecycle
- win detection and reward validation
- compensation handling for technical failures

### 1.5 Rewards Module
Responsible for:

- campaigns and reward definitions
- claim creation
- duplicate prevention
- reward status handling
- reward audit and review

### 1.6 Diamonds / Payment Module
Responsible for:

- product catalog setup
- country and method mapping
- order creation
- payment verification workflow
- review and fulfillment status
- separation from wallet coin logic

### 1.7 Admin Module
Responsible for:

- role assignment
- admin management
- privilege checks
- game-level admin assignment
- support workflow for orders and claims
- manual action and adjustment logs

### 1.8 Audit Module
Responsible for:

- wallet events
- admin actions
- reward claims
- order review actions
- compensation decisions
- suspicious activity tracking

## 2. Interaction model

Each module interacts through the API layer and should not bypass the application business logic. The wallet does not trust client calls; the game service does not determine the final result from a browser object; diamonds orders do not become fulfilled merely because a request was submitted.

## 3. Layer boundaries

- Frontend: display, input collection, final user experience
- API: validation, access control, route handling
- Service logic: business rules
- Database: persistence and transactional integrity

## 4. Ownership rules

- Only admin users with explicit permission may modify prices, review payments, or approve compensation.
- Only the wallet service can write to wallet totals.
- Only the games service can create and finalize sessions.
- Only the reward service can create reward claims and finalize payout decisions.
- Only the diamonds service can change order and payment state.

## 5. Extensibility model

All game-specific logic should use a common contract:

- game definition
- free attempt policy
- paid attempt price
- attempt consumption lifecycle
- session result validation
- reward grant event

This allows new games to be added without redesigning the wallet or reward layers.
