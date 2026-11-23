# NEEN Phase 4 — Coins Wallet and Ledger

Phase 4 extends the existing Phase 3 wallet and accounts. It does not create another wallet/account system, grant administrative roles to customers, or add Coins to Diamonds.

## Database deployment

The same PHP 8.3, PDO MySQL, MySQL 8.x, HTTPS, and server-side environment configuration documented in [`phase-3.md`](phase-3.md) is required. Run the idempotent installer against the existing Phase 3 database:

```sh
php api/migrate.php
```

The installer creates the Coins ledger, package catalog, configurable payment methods, purchase orders, payment-evidence records, and review records. It adds Purchased and Earned balance columns to the existing one-wallet-per-user table and enforces:

```text
coin_balance = purchased_coin_balance + earned_coin_balance
```

Phase 3 creates zero-balance wallets. If the migration finds nonzero legacy wallet balances without the new bucket columns, it stops before installing the Coins schema and requires owner-approved reconciliation of their Purchased/Earned provenance; it does not guess a balance type. After reconciliation, record the approved opening buckets and ledger entries before enabling wallet writes. The migration does not create or alter any Diamond balance.

No package, price, currency, payment method, transfer instruction, or payment provider is seeded or assumed. New packages and payment methods start disabled. An administrator must enter and explicitly activate approved values before users can create purchase requests. There is no initial super-admin account; grant the approved administrative role/permissions only through a trusted database session after verifying the account. No public API can grant roles or permissions.

## Balances and ledger

`GET /api/wallet` returns:

- `coin_balance` and `total_coin_balance` — total NEEN Coins
- `purchased_coin_balance` — Coins credited after purchase approval
- `earned_coin_balance` — Coins credited by approved reward/game or administrative flows
- `diamond_balance` — the separate existing Diamond snapshot

`GET /api/wallet/transactions?limit=50&offset=0` returns the authenticated user’s ledger entries. Optional filters are `type`, `balance_type`, and `source`. Pages return at most 100 records.

Each immutable completed ledger row records its wallet, user, operation, source, positive amount, affected balance type, before/after bucket and total balances, status, reference, idempotency key, optional reason, and UTC timestamp. Database unique constraints prevent repeat idempotency keys and duplicate reversals. MySQL `BEFORE UPDATE` and `BEFORE DELETE` triggers reject edits/deletions; corrections create a linked reversal row instead.

All balance changes run in a database transaction, lock the one wallet row with `SELECT ... FOR UPDATE`, verify the bucket and total snapshots agree, reject insufficient funds, append the ledger entry, and update the balance snapshot with a version check. The wallet schema constrains balances to nonnegative values and the total to the sum of its buckets.

The internal `CoinsLedger::debitAfterConfirmedUsage()` service is not exposed as an HTTP endpoint. Future game/service code must call it only after the backend has confirmed actual usage, with the durable usage reference, a stable idempotency key, and a reason. It only records a server-originated `service_usage` debit; opening pages, creating orders, and submitting payment details do not call it. No game attempts or attempt purchases are implemented in this phase.

## Purchase and review flow

1. An authorized administrator creates/edits a package and a payment method through the management APIs. Both are disabled by default.
2. The customer selects an active package and active payment method on the account page. `POST /api/coins/orders` snapshots the configured values into a new `awaiting_payment` order; it does not credit Coins.
3. After arranging payment outside this application, the customer submits a payment reference and/or text proof details with `POST /api/coins/orders/{id}/evidence`. The order becomes `pending_review`; this submission is not verification.
4. An authorized reviewer approves or rejects using `POST /api/admin/coin-orders/{id}/review`. Approval, order status, review/audit record, Purchased Coins ledger credit, and wallet update commit atomically. Rejection never credits Coins. A unique order review and stable ledger idempotency key prevent duplicate grants.

Proof references and explanatory text are stored in the database with restricted owner/admin API access. No binary upload or public proof URL is accepted. A private upload flow requires approved hosting storage, MIME/size limits, retention, and access rules and is deliberately not assumed here. The backend does not charge a payment method or claim to verify an external transfer automatically; the configured administrator/reviewer must verify payment before approval.

## Endpoints

| Method | Path | Access |
| --- | --- | --- |
| `GET` | `/api/coins/packages` | Public; active packages only |
| `GET` | `/api/coins/payment-methods` | Public; active methods and their configured instructions |
| `POST`, `GET` | `/api/coins/orders` | Authenticated customer; create request / list own requests |
| `POST` | `/api/coins/orders/{id}/evidence` | Authenticated owner; requires CSRF |
| `GET`, `POST` | `/api/admin/coin-packages` | `manage_coin_packages`; list/create |
| `PATCH` | `/api/admin/coin-packages/{id}` | `manage_coin_packages`; edit/activate |
| `GET`, `POST` | `/api/admin/coin-payment-methods` | `manage_coin_payment_methods`; list/create |
| `PATCH` | `/api/admin/coin-payment-methods/{id}` | `manage_coin_payment_methods`; edit/activate |
| `GET` | `/api/admin/coin-orders?status=pending_review` | `review_coin_orders` |
| `POST` | `/api/admin/coin-orders/{id}/review` | `review_coin_orders`; requires CSRF |
| `POST` | `/api/admin/wallets/{user_id}/adjustments` | `adjust_wallet`; audited, reason and idempotency key required |
| `POST` | `/api/admin/coin-ledger/{id}/reverse` | `adjust_wallet`; audited reversal, reason and idempotency key required |

New users retain only the `user` role and read-only wallet permissions. Coins package, payment-method, review, adjustment, and reversal operations all require database-backed permissions; the Phase 4 migration adds management permissions to the existing `super_admin` role only.

## Tests and deployment limits

Run:

```sh
php tests/auth-api.php
```

The HTTP integration suite uses a temporary SQLite database and test-only package/payment configuration. It checks default-empty catalogs, role protection, package/method configuration, idempotent order creation, no credit on order/evidence submission/rejection, Purchased/Earned separation, approval exactly once, internal debit idempotency and insufficient-balance protection, reversals, append-only ledger enforcement, wallet history, and the Phase 3 account flows.

This workspace has PDO SQLite but no `pdo_mysql` driver or MySQL server. The integration suite cannot validate MySQL trigger/DDL behavior or production transaction contention here. Run the migration and a deployment smoke test on the configured MySQL host before accepting real payments; leave package/payment settings inactive until the owner-approved prices, currency, instructions, and review process are in place.
