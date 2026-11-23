# Architecture

## 1. High-level architecture

The future NEEN platform follows a layered architecture designed for an authenticated web application operating on cPanel with PHP 8.3 and MySQL.

```text
Frontend (HTML/CSS/JS)
    ↓ HTTPS API requests
Application Layer (PHP 8.3 + PDO)
    ↓ validated business rules
Domain Modules
  - Auth
  - Users
  - Wallet
  - Games
  - Rewards
  - Diamonds
  - Admin
  - Audit
    ↓ transactional database access
MySQL Database
    ↓ backups / replication / restore
Storage + Monitoring + Logs
```

## 2. Runtime target

- PHP 8.3
- MySQL 8.x preferred
- PDO with pdo_mysql
- cPanel hosting environment
- HTTPS only
- session handling through server side cookies

## 3. Responsibilities by layer

### 3.1 Presentation layer

This layer remains the current static frontend and is responsible for:

- rendering pages
- collecting user input
- sending API requests
- showing server responses
- no wallet authority
- no direct decision-making for reward claims or balances

### 3.2 Application layer

This layer is the authoritative logic layer and is responsible for:

- authentication
- session validation
- request validation
- RBAC checks
- game rules
- reward validation
- wallet adjustments
- order review
- audit logging

### 3.3 Data layer

The MySQL database stores the system of record for:

- users
- sessions
- wallets
- game sessions
- attempts
- reward claims
- diamond orders
- audit logs
- admin settings

## 4. Design principles

1. No balance or reward may be trusted from the frontend.
2. All financial operations use database transactions.
3. Every balance movement is recorded in a ledger entry.
4. Every claim must be idempotent.
5. External operations like payment review are decoupled from order creation.
6. Game state is validated on the server before coins are granted.
7. Admin actions are logged.

## 5. Domain modules

### Auth & identity

- registration
- login/logout
- password hashing
- session creation and invalidation
- account status management

### Users & profiles

- profile creation
- metadata storage
- country settings
- avatar and display profile data

### Wallet & ledger

- automatic wallet creation
- balance tracking
- coin debit/credit history
- transaction logging
- constraints and reconciliation

### Games

- game catalog
- free and paid attempts
- daily reset rules
- session tracking
- win validation
- reward application

### Rewards

- campaign structure
- item definitions
- claim prevention
- duplicate protection
- status review

### Diamonds

- product package catalog
- payment method configuration
- order creation
- payment proof submission
- admin review and fulfillment

### Admin

- moderator and admin roles
- permission mapping
- review tools
- manual adjustments
- audit review

### Audit

- security event logs
- wallet activity logs
- order review history
- failed or suspicious activity tracking

## 6. Operational model

The application should be built around the concept of authoritative server-side state, where client-side browsers only display data or send requests. Decision-making for balance, reward, and payment finality remains entirely on the backend.

## 7. Extensibility

The modular structure supports future additions such as:

- new game types
- new reward types
- more payment methods
- different admin roles per business unit
- additional challenge or event modules

The DB design is normalized enough to expand without disturbing core wallet and audit logic.

## 8. Non-goals in this phase

- direct SQL executed outside PDO
- hard-coded user balances in frontend code
- JavaScript-based financial state mutation
- database creation or live deployment

## 9. Summary

This architecture separates business rules, security, and persistence, while keeping the existing site as a frontend presentation layer. It is a clean foundation for eventual implementation without exposing risk-sensitive logic to the browser.
