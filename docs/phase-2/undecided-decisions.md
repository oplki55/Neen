# Undecided Decisions Requiring Owner Approval

This section documents business decisions that were discussed but not finalized by the project owner during the design phase. These items must be confirmed before backend implementation begins.

## 1. User account model

- Should registration allow a username-only account, or must every user also provide a phone number or another verified identifier?
- Should email verification be enforced before the user can play or claim rewards?

## 2. Wallet and coin policy

- Should NEEN Coins be exchangeable with any external value or remain internal-only?
- Should negative Coins be permanently prohibited, or are there any business exceptions?

## 3. Game economics

- Are free attempts always fixed at 3 per day for all games, or should some games vary?
- Should paid attempts be purchased once and then consumed at any time, or should they expire after a defined timeframe?

## 4. Rewards and benefits

- Are all win rewards fixed at 50 Coins, or should some games have custom reward values?
- Should reward campaigns be admin-approved or self-qualifying based on game activity?

## 5. Diamond payment flow

- Is the review step performed only by admins, or will it also require a finance or support team workflow?
- Should proof uploads be mandatory for all diamond orders?

## 6. Admin model

- Should a single super admin exist, or should multiple admins be assigned independently by game and function?
- Should game admins be limited to reading and approving only their own game sessions and orders?

## 7. Timezone and reset policy

- Is the reset always based on `Africa/Cairo`, or should this be configurable per game or per region?

## 8. Audit and recovery policy

- Should every wallet adjustment require a reason code from a controlled list?
- Should compensation decisions require double approval when they affect user balance?

## 9. Hosting and deployment constraints

- Are there project-specific hosting restrictions not yet documented?
- Is there an established cPanel user, PHP version, or database user configuration already approved?

## 10. Summary

The architecture and data model are complete and internally consistent, but the decisions above must be approved before the implementation phase begins. These are not implementation details; they are business rules that directly affect backend logic and database policy.
