# NEEN Phase 5 — Memory Match

Memory Match is a playable account-backed game integrated with the existing PHP/PDO API, account session, and MySQL database. It does not create a replacement authentication system, modify wallet or ledger data, award Coins, sell attempts, or start Picture Jigsaw.

## Images

The approved source is [`assets/hero-loot/Memory Match pic/`](../assets/hero-loot/Memory%20Match%20pic/). Inspection found eight JPEG images; all eight are used, each verified as a valid 1600 × 738 image. The originals are served in place and have not been cropped, converted, or overwritten. [`assets/hero-loot/memory-match-images.json`](../assets/hero-loot/memory-match-images.json) maps stable image IDs, accessible descriptions, and original paths.

The eight manifest images remain the fixed Memory Match image set. Owners can replace each image from its existing slot in the Owner Dashboard's **Site Images & Media Manager**; resetting a slot restores its manifest image. Replacements use the existing `site_settings` media flow and `uploads/site/` storage, so no separate game-image library or database table is introduced. Image order, count, stable IDs, and saved-session behavior remain unchanged. The browser presents the full original image inside a square card with `object-fit: contain`, so the wide source art is letterboxed rather than stretched or cropped.

## Gameplay and server authority

`memory-match.html`, `memory-match.css`, and `memory-match.js` implement the responsive game and integrate it into the existing NEEN/Events visual language. The board is shuffled on the server for each game. The browser receives no unrevealed card identities. It sends a card index for each flip; the server checks session ownership/status, valid index, duplicate/matched cards, and the two-card turn limit, then decides whether the pair matches. Unmatched cards remain server-locked for at least 900 ms. The server marks a session completed only after every pair has been found; the client cannot post a score or a win result.

Active session progress is saved and can be resumed from the server after a page refresh. Sessions expire after 30 minutes without a game action. Each account gets up to three free starts per game per calendar day using the server's `Africa/Cairo` date and database-serialized daily counters. Start idempotency keys prevent retries from consuming a second attempt, and an existing active session is resumed rather than replaced. Paid attempts, Coins debits, and the approved 50-Coin reward are not wired into this phase.

## Database and migration

The idempotent installer now also applies [`api/games-schema.sql`](../api/games-schema.sql):

- `game_catalog` — the active `memory-match` entry and its approved daily limit/reset timezone.
- `user_game_daily_attempts` — per-user/per-game/per-Cairo-day free and paid usage counters; only free starts are used here.
- `game_sessions` — owner, attempt type, state, move/pair counts, idempotency key, activity, and expiry/completion timestamps.
- `memory_match_states` — server-only shuffled board, matched card identities, open card identities, and mismatch timer.

The schema installer is `php api/migrate.php`; it requires the existing configured MySQL database and the same environment variables in [`phase-3.md`](phase-3.md). The existing Site Media manager controls image replacements through `site_settings`; no game-specific image table or permissions are added. Existing owner-role protection and audit logging apply.

## API

| Method | Path | Access / effect |
| --- | --- | --- |
| `GET` | `/api/games/memory-match` | Public game metadata and validated image catalog; no board identities |
| `GET` | `/api/games/memory-match/sessions/current` | Authenticated; resumes current session and returns today's remaining free starts |
| `POST` | `/api/games/memory-match/sessions` | Authenticated + CSRF; creates/resumes a session with an `idempotency_key` |
| `GET` | `/api/games/memory-match/sessions/{id}` | Authenticated owner only; returns matched and currently revealed cards |
| `POST` | `/api/games/memory-match/sessions/{id}/flip` | Authenticated owner + CSRF; accepts only a `card_index`, all match/state logic is server-side |
| `POST` | `/api/games/memory-match/sessions/{id}/hide-mismatch` | Authenticated owner + CSRF; conceals a mismatched pair after the server-enforced reveal delay |

Game results are stored but are not connected to the wallet or a reward claim. Replaying a flip after completion is rejected. No public game-result, score, win, wallet-debit, or reward endpoint is added.

## Verification

Run:

```sh
php tests/auth-api.php
```

The SQLite-backed HTTP integration suite covers anonymous access denial, game catalog/page delivery, idempotent start/recovery, unrevealed board secrecy, legal and invalid flips, duplicate flips, mismatches and third-card rejection during the reveal delay, matched-card protection, full server-validated completion, duplicate completion rejection, daily attempt cap, and user-owned sessions. It also checks the image manifest count and source dimensions. PHP lint, JavaScript syntax checks, and `git diff --check` are run with the implementation.

The local environment has PDO SQLite but no `pdo_mysql` or MySQL server. MySQL migration/locking behavior and visual behavior in a real browser/mobile device therefore require deployment/browser verification; no MySQL test result is claimed here.
