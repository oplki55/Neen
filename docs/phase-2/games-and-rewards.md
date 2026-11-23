# Games, Attempts, and Rewards

## 1. Game model

The future platform must support game additions without redesigning the core engine. Each game should be represented in the `game_catalog` table and mapped to a consistent lifecycle.

### Required game metadata

- unique slug
- display name
- status
- free attempt limit per day
- paid attempt pricing
- reward amount for successful win
- reset timezone

## 2. Free attempts policy

For every user and game:

- maximum free attempts per day = 3
- reset at Egypt local midnight
- free attempts are tracked at `user_game_daily_attempts`
- the calculation is based on the configured timezone, not browser time

## 3. Paid attempts policy

The approved policy states:

- paid attempts do not expire until used
- the app should maintain availability of the paid attempt until the user actually consumes it
- admin can control the price for each game
- the system must deduct Coins only when a paid attempt is actually used

This means the purchase and usage events are distinct. A user can buy or reserve a paid attempt, but the currency is deducted only when the use is confirmed.

## 4. Attempt consumption lifecycle

A single attempt lifecycle should be represented by:

- `attempt_usage` record
- `game_sessions` record
- `user_game_daily_attempts` counters
- `wallet_transactions` if payment or deduction is triggered

The server must ensure:

- the user is authorized
- the game is active
- the free daily quota is respected
- a paid attempt is valid and not already consumed
- attempt consumption is documented in the ledger if price is charged

## 5. Game session lifecycle

Each game session begins with:

- user ID
- game ID
- attempt kind (free or paid)
- status = started

The session moves through:

- `started`
- `in_progress`
- `completed`
- `failed`
- `cancelled`
- `compensated`

A session is considered valid only if it is tied to a real user and a valid game. Browser state cannot authorize a win on its own.

## 6. Win validation

A successful result must be validated by the server. The backend must check:

- the session belongs to the user
- the game is active
- the attempt has actually been consumed
- the outcome is valid under game rules
- no duplicate reward has been granted
- the reward has not already been applied in a prior session

Only after this validation may the system award 50 Coins.

## 7. Fixed reward rule

Every successful win grants exactly 50 Coins.

This value must be stored in `game_catalog.reward_amount_coins` and must not be mutated by frontend logic.

## 8. Duplicate prevention

The platform must prevent the following duplicate actions:

- duplicate reward claims
- repeated reward grant for same session
- duplicate diamond order additions
- repeated discount or promotional credit without unique ID

This is enforced by:

- unique keys
- idempotency keys
- unique constraints on claim and usage records
- transaction-safe checks before inserting ledger rows

## 9. Reward model

Rewards are defined by `reward_campaigns` and `rewards` and are claimed through `reward_claims`.

Rules:

- no duplicate claims for same reward and user
- reward claim is tied to a campaign and must respect a valid time range
- reward type may include coins, badge, cosmetic, or physical item depending on design
- all reward-based payouts must be server-side

## 10. Reward claim flow

1. user requests reward claim
2. backend validates campaign status, reward status, and user eligibility
3. backend checks for existing claim by same user and reward
4. if no prior claim exists, it creates a record with `pending` or directly `approved`
5. reward is applied via wallet ledger or item assignment
6. audit log is created

## 11. Free vs paid mode under a common design

Although different game engines can vary, they should all share a common business interface:

- permitted attempt type
- limit rules
- pricing rules
- result validation
- reward grant
- compensation entry

This keeps future game additions simple and consistent.

## 12. Summary

The game infrastructure is built to support server-side attempt accounting, accurate daily quotas, compensation logic, exact reward amounts, and preventing duplicate claims or wins. This design is critical because the frontend must not decide game success or wallet effects.
