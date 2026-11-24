# NEEN

A responsive, multi-page Free Fire creator website built with the project's existing HTML, CSS, and JavaScript. No framework or new package dependencies are required.

## Pages

- `index.html` — Home hero, navigation to every page, one real TikTok video preview, and selected character-art previews.
- `account.html` — Account registration, login/logout, optional SMTP-backed email verification, profile editing, Cairo-time daily attendance rewards, Purchased/Earned Coins balances, purchase requests, wallet history, and password recovery through the PHP API.
- `memory-match.html` — Account-backed Memory Match game using the eight original images in `assets/hero-loot/Memory Match pic/`; board state and matching are validated and saved by the PHP API.
- `picture-jigsaw.html` — Account-backed 3 × 3 Picture Jigsaw using all three original images in `assets/Picture Jigsaw pic/`; image choice, piece swaps, and completion are validated and saved by the PHP API.
- `target-rush.html` and `loot-sequence.html` — Server-validated daily games with private server-generated state, free Cairo-day starts, and Earned Coins credited only after verified wins. Target Rush uses the four approved screen images, a 30-second round, and server-verified target hits, score, and reward.
- `sunny-island.html` — A responsive 2D cartoon adventure with four explorable zones, touch/keyboard movement, permanent collectibles and Codex, and server-validated daily objectives, XP, and Earned Coins.
- `sunny-island-3d.html` — A standalone Three.js third-person Sunny Island game with local animated GLB models, server-backed adventures and shop, combat, and keyboard/touch controls; it does not replace the 2D game.
- `videos.html` — Five TikTok player embeds, each lazy-loaded and paired with its existing direct-video link as a fallback.
- `about.html` — Creator introduction using only the description and account already present in the project.
- `gallery.html` — Eighteen original character images in a varied, uncropped layout with keyboard and touch navigation in the lightbox.
- `diamonds.html` — Database-backed diamond package selection, Player ID validation, optional payment transaction reference, order submission, and authenticated order tracking. Payment and delivery are manual; no real provider is configured.
- `events.html` — NEEN Events Hub backed by server-managed events, suggestions, votes, published results, and eligible rewards.
- `events-admin.php` — Server-gated, bilingual (English/Arabic) admin dashboard with a shared language preference across the admin and Owner control rooms. `events-admin.html` is a content-free compatibility redirect; admin access is available through the protected dashboard routes, not a public wordmark link.
- `owner-dashboard.php` — Separate Owner-only dashboard for live overview data, account/profile/role/status management, ledger-backed wallet adjustments, runtime-backed game settings, site settings, admin activity, and links to the existing admin modules.
- `events-detail.html`, `events-votes.html`, `events-suggestions.html`, `events-profile.html`, `events-rewards.html`, `events-results.html` — Separate Events Hub views connected to server data and authenticated actions. `events-challenges.html` is the active daily challenge hub; `events-leaderboard.html` displays public profile-safe server rankings.

The existing content pages share `styles.css`, `script.js`, a consistent navigation/footer, and the existing TikTok profile link. The account page adds `account.css` and `account.js`; event pages also use `events.css`. The social link available in the project is `https://www.tiktok.com/@neen_0o`.

## Accounts backend

The PHP 8.3/MySQL APIs in `api/` provide registration, login/logout, profiles, server-side sessions, CSRF protection, a Purchased/Earned Coins wallet, an immutable ledger, configurable purchase/review flows, server-validated game sessions, daily attendance rewards, homepage reward claims, community events and leaderboards, and diamond recharge requests. Configure the hosting environment and install its schema as described in [`docs/phase-1.md`](docs/phase-1.md), [`docs/phase-3.md`](docs/phase-3.md), [`docs/phase-4.md`](docs/phase-4.md), [`docs/phase-5.md`](docs/phase-5.md), [`docs/phase-6.md`](docs/phase-6.md), [`docs/phase-7.md`](docs/phase-7.md), [`docs/phase-8.md`](docs/phase-8.md), and [`docs/phase-9.md`](docs/phase-9.md). No database credentials or production secrets are stored in the repository.

Daily attendance is determined by the server in `Africa/Cairo`; each successful check-in credits 100 Earned Coins, once per date. Seven consecutive check-ins complete a 700-Coin cycle; missing a day starts progress at day one without removing earlier earnings. Memory Match, Picture Jigsaw, NEEN Target Rush, Loot Sequence Strike, and Sunny Island default to three server-counted free starts per Egypt-local day and 50 Earned Coins per server-verified win; the Owner can change these stored game-catalog settings, and each game runtime enforces them. Target Rush uses the existing game catalog, session, daily-attempt, and Coins ledger records without an additional migration. Sunny Island additionally saves permanent collectibles and zone discoveries, grants 100 XP per completed daily adventure, and advances a level every 300 XP. None of the games debits Coins. Homepage reward rules start inactive and require an authorized administrator to configure the amount and claim window; claims are recorded once per configured reward window and credited through the existing wallet ledger. Email format and uniqueness are enforced. Registration creates a `pending_verification` account and sends a one-time email-verification link when mail delivery is configured; the account cannot log in until the 24-hour link is used successfully. Pending and legacy active/unverified accounts can request a resend without exposing account existence. Password recovery uses a random, hashed, expiring, single-use token, revokes existing sessions after reset, and establishes the verified mailbox for a legacy active account. No Coins packages, prices, or payment methods are preconfigured, and no live payment credentials are stored in the repository.

## Homepage 3D loot preview

The homepage also retains its animated loot preview. Its collectible visuals use the visitor's device clock and local storage only and do not synchronize or grant Coins. The separate Homepage Rewards claim control uses the authenticated server API and wallet ledger; local preview state cannot affect claim eligibility or value.

## Environment and deployment setup

For local use, copy `.env.example` to the project root as `.env`. On cPanel, select PHP 8.3 in MultiPHP Manager, enable PDO and `pdo_mysql`, and place `.env` outside `public_html` at the sibling path `~/neeng.cc/.env` when the document root is `~/public_html/neeng.cc/`. `api/bootstrap.php` loads that external file first and falls back to the project-root `.env` for local development; server-provided environment values are never overridden. Keep the external file readable by the PHP account and restricted to the account owner (for example, mode `600` where supported). Apache also denies hidden files, including `.env`, under the document root. The repository intentionally keeps local secrets out of source control via `.gitignore` and does not ship production credentials or payment details.

For SMTP-backed verification and password recovery, configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, and `SMTP_ENCRYPTION` (`tls` for STARTTLS, `ssl` for implicit TLS, or `none` only when explicitly required by a trusted local mail relay). `SMTP_FROM` must be a valid sender address. If SMTP is not configured, the application uses the existing PHP `mail()` fallback for verification and password recovery; in test mode, mail is recorded in the isolated test mail sink. Mail delivery failure does not activate the account.

## Curated image map

| Section | Image | Why it fits |
| --- | --- | --- |
| Home hero | `neno/WhatsApp Image 2026-10-29 at 3.08.15 PM (1).webp` — 1374 × 1145 | The character is framed on the left with open sky and city on the right, leaving room for the NEEN headline. The optimized WebP preserves the original framing; CSS positions and layers it without redrawing the character. |
| About Me | `neno/WhatsApp Image 2026-10-29 at 11.23.14 PM.webp` — 313 × 627 | The moonlit rooftop portrait gives the creator page a distinct character frame. |
| Gallery 01 and Home preview | `neno/WhatsApp Image 2026-10-29 at 3.08.14 PM.webp` — 1600 × 738 | Wide rose-garden composition; used as the gallery's landscape opening frame. |
| Gallery 02 | `neno/WhatsApp Image 2026-10-29 at 3.08.14 PM (1).webp` — 1145 × 1374 | Portrait framing and full seated pose suit a taller gallery card. |
| Gallery 03 and Home preview | `neno/WhatsApp Image 2026-10-29 at 3.08.15 PM.webp` — 1145 × 1374 | A distinct moonlit close composition provides color and pose variety. |
| Gallery 04 | `neno/WhatsApp Image 2026-10-29 at 3.08.15 PM (1).webp` — 1374 × 1145 | The car and sunset form a second landscape composition with useful negative space. |
| Diamonds | No character image | The page focuses on accurate package/payment setup and request safety rather than decorative imagery. |
| Homepage feature cards | New 11.23 PM character portraits | The Feed, Gallery, About, and Events cards each use a distinct image rather than repeating the same portraits. |
| Homepage character preview | Four new 11.23.15 PM portraits | Sakura, garden, sunset, and neon scenes form a varied strip linking to the full gallery. |
| Events Hub | Neon-crown and sunset portraits | Two decorative community images add character art to the status card without implying live events or participants. |
| Videos page | No character thumbnails | Only real TikTok embeds and their direct links are shown. |

Referenced JPEG and PNG assets were converted to WebP where the validated result was smaller, with dimensions and transparency preserved. All 44 converted originals were removed; existing WebP assets were retained.

## Diamonds recharge and provider requirements

Diamond package terms are managed in the protected admin console. The MySQL migration adds six active JOD packages (100–5,000 diamonds, 1.00–40.00 JOD) when matching package rows are absent. The verified UWallet alias `Neen99` is configured in `payment-config.js`; CliQ remains unavailable until its recipient details are verified and configured. Users can submit a request for an active package with a numeric Player ID and optional wallet-transfer/CliQ transaction reference, then track the reference and order status while signed in. The server snapshots the package terms, creates a unique order number, and rejects tampered amounts or prices and duplicate open orders.

Administrators manually record payment verification and fulfillment actions through the audited order lifecycle. The internal states distinguish payment and delivery and support rejection, failure, and refunds where allowed. No real payment or delivery integration is enabled: provider adapter contracts are present but unconfigured, and attempting to use them returns an explicit unavailable response. Configure only approved provider credentials in server-side deployment settings after formal authorization; never place secrets in browser code.

## Community Events and administrative scope

Community Events use the application database and session system. Authenticated users can submit suggestions and cast one vote per event while its server-controlled voting window is open. Published results are calculated and approved server-side. Event rewards are recorded against eligible participants and credited through the Coins ledger with duplicate-claim protection. The public `events-leaderboard.html` page reads Earned Coins, completed-game wins, and current attendance streaks from public profile-safe server rankings. The `events-challenges.html` hub displays daily attendance, Memory Match, Picture Jigsaw, NEEN Target Rush, and Loot Sequence Strike with live daily attempts and reward values. Admin APIs enforce separate permissions for event management, suggestion review, and reward issuance.

The admin console also includes read-only, permission-scoped user/role/permission search, global wallet snapshots and recent Coins ledger history, Homepage Rewards settings, event and suggestion management, voting/results review, diamond packages and order operations, Coins catalog/order workflows, games, and audit records. Account-state and role changes are restricted to the separate Owner Dashboard; administrative attendance reporting remains unavailable. These sections expose no mock data or direct balance-column editing controls. Provider-based payment and delivery remain disabled pending official integration details.

The Owner Dashboard and its current scope, trusted Owner provisioning, deployment limitations, and verification instructions are documented in [`docs/phase-10-owner-dashboard.md`](docs/phase-10-owner-dashboard.md).

## Run locally

Run the PHP API and PHP dashboards with PHP's built-in server from the repository root:

```sh
php -S localhost:4173 api/router.php
```

Then open `http://localhost:4173/`. Set the environment variables listed in `.env.example` in the shell or hosting environment and configure the database before using the PHP API.
