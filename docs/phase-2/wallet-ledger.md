# Wallet and Coins Ledger

## 1. Wallet creation policy

Every user must receive a wallet automatically after successful registration. The wallet should be created in a transaction and begin with zero Coins and zero Diamonds unless the business rules later specify another startup value.

## 2. Wallet model

`wallets` stores:

- user identity
- current coin balance
- current diamond balance
- currency code
- updated_at
- version for optimistic locking or concurrency safety

The wallet is not editable at the frontend and is never the source of truth for financial correctness alone. The system of record is the `wallet_transactions` ledger.

## 3. Ledger design

The wallet ledger records every signed movement:

- debit
- credit
- adjustment
- refund
- hold
- release

Each transaction stores:

- wallet ID
- user ID
- type
- category
- amount
- previous balance
- new balance
- reference type and ID
- idempotency key
- status
- note
- created_at

## 4. Ledger rule

The balance should be derived from the sum of all completed ledger events, not from a mutable one-off balance number that can be tampered with in the browser or by a partial write. The wallet row is a current snapshot only.

## 5. Negative balance protection

The default rule is no negative balance for Coins. The application must reject any balance deduction that would make the user less than zero unless an explicit and approved business exception is created.

## 6. Attempt deduction logic

The approved policy is:

- free attempts: 3 per user per game per day
- free attempt reset at Egypt local midnight
- paid attempts: remain available until used
- no coin deduction until an attempt is actually consumed
- paid attempt price is admin controlled per game
- deduct only after attempt execution begins or a real attempt is consumed

This means a purchased attempt is not immediately subtracted at the point of purchase if the backend does not use it yet. A hold/release model may be used if desired, but the final effective deduction must happen on actual use.

## 7. Win reward logic

Every successful game win grants a fixed 50 NEEN Coins. This is enforced server-side.

## 8. Technical failure compensation policy

When a user has consumed an attempt and the system later proves the attempt was consumed due to a technical fault, the project requires compensation.

The compensation workflow should:

1. identify the failed or invalid attempt
2. confirm whether the user was charged or consumed a real attempt
3. create a ledger adjustment record with a clear note
4. restore the user’s state or compensate in a controlled transaction
5. record the event in `audit_logs`

## 9. Idempotency

Every financial change must include a unique idempotency key, for example:

- `game-win-claim-123-001`
- `reward-claim-45-2026-11-07`
- `diamond-order-2001-review`

The backend should reject duplicate processing of the same logical action.

## 10. Transaction pattern

Implementation should wrap wallet and claim writing in a database transaction:

- lock wallet row if needed
- insert ledger row
- update wallet balance
- commit only if all steps succeed
- rollback on any failure

## 11. Separation of NEEN Coins and NEEN Diamonds

The system must separate:

- NEEN Coins: used for gameplay and reward balance
- NEEN Diamonds: used for purchase packages and top-up workflows

These must never be treated as the same currency or shared ledger.

## 12. Important rule for frontend

The browser may display balance values, but it must not decide final wallet totals, win validity, or reward distribution. Only the server can write the authoritative wallet state.

## 13. Summary

The wallet model is intentionally debt-safe, audit-friendly, and designed for future use with a real ledger. It ensures every Coins movement is captured, reviewable, and protected from browser-side tampering.
