# NEEN Phase 7 — Game Rewards and Coins Integration

Phase 7 connects server-verified Memory Match and Picture Jigsaw wins to the existing Coins wallet. It reuses the Phase 4 `CoinsLedger`; it does not add another wallet, a public reward-claim API, paid attempts, or a new reward table.

## Reward and transaction flow

Every completed win grants exactly **50 Earned Coins**, regardless of game, moves, picture, or attempt type. `GameRewards` is called only from the successful final Memory Match flip or Picture Jigsaw swap, inside that game's existing database transaction.

Before crediting, the shared service locks and checks the owner-bound completed session, active game, completion timestamp, and non-expired session. It independently verifies the full server-stored Memory Match deck/matched-card state or the complete Picture Jigsaw permutation. Browser-supplied win flags, scores, arrangements, user IDs, and reward requests are not accepted.

The service calls the existing `CoinsLedger::credit()` with:

- `source = game_reward`
- `balance_type = earned`
- `amount = 50`
- `reference_type = game_session`
- `reference_id =` the completed session ID
- a deterministic unique key, `game-win-{game-slug}-{session-id}`

The ledger's existing unique `(user_id, idempotency_key)` constraint and wallet row lock prevent duplicate credits. The game state update and ledger/wallet changes commit together. A ledger failure rolls back the completion as well as the credit. Existing Purchased Coins are unchanged. No schema migration was needed.

There is no standalone reward endpoint. Repeated final game requests fail because completed sessions are closed. A successful final move includes the reward amount and server-read Earned/total balances in its response. Refreshing a completed session returns the saved game result but no new reward receipt; the game pages load current wallet balances from `/api/wallet`.

## Changed implementation surfaces

- `api/src/GameRewards.php` — server validation, fixed reward amount, and ledger receipt.
- `api/src/MemoryMatch.php`, `api/src/PictureJigsaw.php` — award within the final-move transaction; Memory Match also recovers completed sessions after refresh.
- `api/src/Api.php`, `api/index.php` — load the shared service and return the final-move reward receipt; no claim route was added.
- `memory-match.html`, `memory-match.js`, `memory-match.css`; `picture-jigsaw.html`, `picture-jigsaw.js`, `picture-jigsaw.css` — show server-backed wallet balances and one-time win feedback.
- `tests/auth-api.php` — game reward, ledger, rollback, ownership, replay, refresh, and concurrent-request coverage.
- `README.md` — current game reward behavior and Phase 7 documentation link.

## Verification

Run:

```sh
php tests/auth-api.php
find api tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check memory-match.js
node --check picture-jigsaw.js
git diff --check
```

The HTTP integration suite uses a temporary SQLite database. It verifies a first and a second Memory Match win, multiple Picture Jigsaw wins, exact 50-Coin credits to Earned only, session-bound immutable ledger entries, insufficient/invalid claim paths, rejection of client-forged wins, owner isolation, completed-session refresh without a repeated receipt, replay prevention, a simulated ledger insert failure that rolls back Memory Match completion and wallet changes, and two concurrent final Jigsaw move requests producing only one successful completion/reward. It also reruns the existing account, wallet, and game tests.

This environment has `pdo_sqlite` but no `pdo_mysql` driver or MySQL server. The concurrent request check therefore exercises the HTTP/API idempotency behavior on SQLite, not MySQL row-lock contention. MySQL migration/locking and browser visual behavior must be verified against the configured hosting database and desktop/mobile browsers; no MySQL or real-device test is claimed.
