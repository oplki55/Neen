# NEEN — Phase 2: Architecture & Database Design

## Scope

This folder contains the design documentation for the second phase of the NEEN project. The design is intentionally documentation-only and does not create a database, install runtime packages, or add backend implementation code.

The design is based on the approved project direction and the architecture decisions discussed during the project review.

## Approved technical direction

- PHP 8.3
- MySQL
- PDO (pdo_mysql)
- cPanel hosting target
- Open registration for visitors
- Automatic wallet creation for each user with zero balance
- Three free attempts per game per user per day
- Free-attempt reset at Egypt local midnight
- Paid attempts remain available until used
- Coin deduction only on actual use
- Admin-controlled attempt prices per game
- Technical failure compensation policy
- Fixed reward of 50 Coins for each successful win
- No frontend control over wallet balances or win validation
- Support for multiple admins with independent privileges and per-game admin assignment
- Clear separation between NEEN Coins and Free Fire Diamonds

## Documentation map

1. [architecture.md](architecture.md) — system architecture and runtime structure
2. [modules.md](modules.md) — module decomposition and ownership
3. [database-schema.md](database-schema.md) — detailed schema and constraints
4. [erd.md](erd.md) — Mermaid ERD
5. [api-spec.md](api-spec.md) — endpoint contract and payload design
6. [auth-and-access.md](auth-and-access.md) — authentication, sessions, role model
7. [wallet-ledger.md](wallet-ledger.md) — wallet, coins, ledger, and debit/credit rules
8. [games-and-rewards.md](games-and-rewards.md) — attempts, sessions, wins, reward prevention
9. [diamonds-and-payments.md](diamonds-and-payments.md) — packages, orders, and review workflow
10. [security-and-recovery.md](security-and-recovery.md) — security, validation, backup, and recovery
11. [status-transitions.md](status-transitions.md) — operational status transitions for key entities
12. [undecided-decisions.md](undecided-decisions.md) — decisions that still require explicit owner confirmation

## Design principles

- All sensitive financial actions must be server-side.
- Wallet balances are never user-controlled.
- Every coin movement must be recorded in an immutable ledger.
- Duplicate actions must be prevented using idempotency keys and unique constraints.
- Database transactions are required for wallet changes, reward claims, and purchase flows.
- Frontend must never trust user input for final win validation, reward granting, or balance updates.
- Game modules must be extensible for future additions.

## Out of scope for this phase

- Creating the real MySQL database
- Creating the actual PHP backend
- Deploying to hosting
- Changing the current static HTML/CSS/JS site
- Installing packages or dependencies
- Beginning the implementation phase

## Final status

The Phase 2 design documentation is complete and saved inside this folder. The project remains in Design-Only mode until project owner approval is received.
