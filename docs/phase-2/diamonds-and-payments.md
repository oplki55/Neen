# Diamonds Packages and Payment Workflow

## 1. Business separation

The platform must treat NEEN Coins and NEEN Diamonds as separate financial concepts.

- `wallets.coin_balance` is for NEEN Coins
- `wallets.diamond_balance` is for NEEN Diamonds
- `diamond_orders` is for package requests and order review
- `wallet_transactions` is for Coin movement only

## 2. Diamond packages

`diamond_packages` stores all valid package information by country.

Important fields:

- country code
- package name
- diamond amount
- price
- currency
- active flag

Each package should be approved and activated by admin configuration only.

## 3. Payment method flow

The user chooses:

1. country
2. payment method
3. diamond package
4. player UID
5. contact phone

Then the order is submitted for review.

The approval workflow must not be confused with payment confirmation. A successful order creation is not a payment proof, not a verified transfer, and not a fulfillment event.

## 4. Order lifecycle

`diamond_orders` should use a clear state machine.

### States

- `pending_review`
- `approved`
- `rejected`
- `paid`
- `fulfilled`
- `cancelled`

## 5. Payment workflow rules

### Step 1: Create order

- validate selected package and method
- verify user identity
- create order with status `pending_review`
- store pricing and contact details
- return `order_id`

### Step 2: Submit proof

- user uploads proof file (if required)
- backend validates the file type and size
- file is stored outside public browsing if possible
- record is saved in `payment_submission_files`

### Step 3: Admin review

- admin checks order and proof
- admin chooses `approved`, `rejected`, or `cancelled`
- all review actions are logged into `audit_logs`

### Step 4: Fulfillment

- only after approval and payment validation can the order be marked as `fulfilled`
- the diamond delivery is then processed according to the package and user record

## 6. Price and method control

No public front-end config should be considered authoritative for actual payment values. Prices and active methods must come from server-controlled settings or admin-managed configuration.

## 7. Payment review separation

The system must maintain a strict separation between:

- order submission
- payment verification
- payment review
- asset fulfillment

This avoids the risk of shipping real diamonds or credits before actual confirmation of payment.

## 8. Security requirements

- payment proof files require validation
- all order updates require authorization
- no client-side verdict on payment completion
- all review decisions must be logged
- no fake or spoofed order IDs may be accepted

## 9. Summary

The diamonds module is a review-driven fulfillment flow. It is intentionally separate from wallet coin logic and is designed to prevent abuse, fake payment claims, and direct client-side wallet manipulation.
