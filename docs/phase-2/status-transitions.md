# Status Transitions

## 1. Game session states

`game_sessions.status` may transition as follows:

- `started` → `in_progress`
- `started` → `cancelled`
- `in_progress` → `completed`
- `in_progress` → `failed`
- `completed` → `compensated` (only if technical correction is required)
- `failed` → `compensated` (if a technical fault caused improper usage)

### Rules

- a reward can only be granted while the session is valid and not already rewarded
- a compensated session must retain an audit record
- a duplicate reward grant is rejected even if the state is already `completed`

## 2. Attempt usage states

`attempt_usage.consumption_status` may transition:

- `pending` → `used`
- `pending` → `rolled_back`
- `used` → `compensated`
- `pending` → `compensated`

### Rules

- deduction only happens when `used`
- compensation can restore or offset the failure and must leave an audit trail

## 3. Reward states

`reward_claims.status` may transition:

- `pending` → `approved`
- `pending` → `rejected`
- `pending` → `cancelled`
- `approved` → `processed`
- `approved` → `cancelled` (only for valid admin action)

### Rules

- duplicates are rejected before write
- processed claims must carry the review actor and timestamp

## 4. Diamond order states

`diamond_orders.order_status` may transition:

- `pending_review` → `approved`
- `pending_review` → `rejected`
- `pending_review` → `cancelled`
- `approved` → `paid`
- `paid` → `fulfilled`
- `approved` → `rejected`

### Rules

- the order is never treated as fulfilled during creation
- payment review is a distinct step from order creation
- the order status cannot be changed without proper admin authorization

## 5. Wallet transaction states

`wallet_transactions.status` may be:

- `pending`
- `completed`
- `failed`
- `reversed`

### Rules

- only a `completed` transaction is eligible for wallet balance snapshot use
- `failed` or `reversed` entries must be audit tracked and not treated as a valid balance increase

## 6. Summary

Every status transition must include both a database state and an audit trail. The workflow is designed so that no state is finalized without explicit validation and clear ownership.
