# NEEN Phase 6 — Picture Jigsaw

Picture Jigsaw is implemented as a separate account-backed game using the existing PHP/PDO API, account session, and game-session tables. Memory Match, account behavior, and the Coins wallet/ledger are not replaced or changed.

## Approved images

The source directory is [`assets/Picture Jigsaw pic/`](../assets/Picture%20Jigsaw%20pic/). It contains optimized WebP versions of the three original images. Conversion preserved each image's dimensions and framing:

| Image | Dimensions |
| --- | --- |
| `WhatsApp Image 2026-11-07 at 8.29.09 PM.webp` | 1600 × 738 |
| `WhatsApp Image 2026-11-07 at 8.32.06 PM.webp` | 314 × 627 |
| `WhatsApp Image 2026-11-07 at 8.32.37 PM.webp` | 418 × 627 |

[`assets/hero-loot/picture-jigsaw-images.json`](../assets/hero-loot/picture-jigsaw-images.json) lists the three stable image IDs and approved paths used by the game. Owners can replace each image from its existing slot in the Owner Dashboard's **Site Images & Media Manager**; resetting a slot restores the manifest image. Replacements use the existing `site_settings` media flow and `uploads/site/` storage, without a separate game-image library or database table. The image count, order, stable IDs, and saved-session behavior remain unchanged.

Image choice previews use `object-fit: contain`. The puzzle board retains the chosen image's aspect ratio and divides the full image into a 3 × 3 tile grid; each tile uses the original image as a position-mapped background. This keeps both the wide and portrait character art proportional, without stretching or cropping.

## Gameplay and sessions

The owner selected **3 × 3 (9 pieces)**. [`picture-jigsaw.html`](../picture-jigsaw.html), [`picture-jigsaw.css`](../picture-jigsaw.css), and [`picture-jigsaw.js`](../picture-jigsaw.js) provide the image chooser, responsive tile board, drag-and-drop via Pointer Events (mouse, pen, and touch), keyboard selection/swap, move count, correct-position progress, completion state, replay, and return to image selection. Abandoning an active puzzle is explicit and the already-started free attempt remains counted.

The server generates a secure shuffled arrangement and stores the selected approved image, permutation, session owner, move count, status, timestamps, and expiry. Each move submits only two board positions; the PHP service locks and checks the authenticated owner's active session, swaps the server-side permutation, and marks completion only if it becomes the exact original ordering. Client-provided completion, score, or permutation fields are rejected. Repeated moves after completion fail. Session state and completed results can be recovered after page reload; inactive sessions expire after 30 minutes.

The existing approved policy of three free starts per game per account per Egypt-local day is tracked in the shared daily-attempt table, using the separate Picture Jigsaw game ID. No paid attempts, Coins debits, or 50-Coin reward are implemented.

## Database and API

The existing installer [`api/migrate.php`](../api/migrate.php) applies [`api/games-schema.sql`](../api/games-schema.sql). It seeds the `picture-jigsaw` catalog row alongside the existing Memory Match row and adds `picture_jigsaw_states`; it reuses `game_catalog`, `user_game_daily_attempts`, and `game_sessions`. Image replacements are handled by the existing Site Media manager and stored under `uploads/site/`. The migration creates no new account, wallet, permission, or ledger system.

| Method | Path | Access / effect |
| --- | --- | --- |
| `GET` | `/api/games/picture-jigsaw` | Public approved image catalog and 3 × 3 rule |
| `GET` | `/api/games/picture-jigsaw/sessions/current` | Authenticated; recover active or latest completed session and daily starts remaining |
| `POST` | `/api/games/picture-jigsaw/sessions` | Authenticated + CSRF; start/resume with approved `image_id` and idempotency key |
| `GET` | `/api/games/picture-jigsaw/sessions/{id}` | Authenticated owner only; read server state |
| `POST` | `/api/games/picture-jigsaw/sessions/{id}/swap` | Authenticated owner + CSRF; request a two-position swap |
| `POST` | `/api/games/picture-jigsaw/sessions/{id}/abandon` | Authenticated owner + CSRF; close an active session without refunding its already-used start |

No separate submit-win endpoint exists. The successful final swap is checked in the same server transaction that saves the arrangement and completion status. Rewards can later consume this recorded completion, but the wallet is untouched in Phase 6.

## Verification

Run:

```sh
php tests/auth-api.php
```

The SQLite-backed HTTP integration suite verifies all three images and dimensions, actual image delivery, account-only starts, per-user session access, idempotency and refresh recovery, server-generated nine-piece arrangements, invalid and forged moves, incorrect placement not completing the puzzle, completion of all three chosen images, duplicate completion denial, three-start daily limit, and unchanged wallet balance. The suite also reruns the existing account/Coins and Memory Match coverage. PHP syntax checks, JavaScript syntax check, and `git diff --check` are run as well.

The environment provides PDO SQLite but not `pdo_mysql` or a MySQL server. No MySQL migration or transaction-locking test is claimed. Pointer/touch interactions and layout are covered in the implementation and static/integration checks, but a real desktop/mobile browser visual test was not available.
