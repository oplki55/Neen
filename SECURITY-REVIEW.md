# Pre-deployment security review

## Findings

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | 🟠 HIGH | `.htaccess` (missing before this review) | — | Without root webroot rules, Apache could serve `.git/`, `.env*`, `docs/`, `tests/`, Markdown, SQL, and other dotfiles if no higher-level hosting rule blocks them. | 9/10 |
| 2 | 🟡 MEDIUM | `api/src/Api.php` | 970-971, 1034-1075, 1171-1275 | Registration activates accounts without verifying email ownership. Someone can register using another person's address, enabling identity squatting; this is not a password-reset bypass for an existing account. | 9/10 |

## Remediation and observations

- Root `.htaccess` now disables directory indexes and denies direct access to dotfiles, `.git/`, `docs/`, `tests/`, `*.sql`, and `*.md`. `api/.htaccess` denies `migrate.php`, SQL files, and `api/src/*`; `api/src/.htaccess` denies direct access to its implementation files.
- The email ownership issue is intentionally retained for this phase because SMTP-backed verification is not configured. It is documented as a pre-launch limitation in `README.md`; add email verification and gate account access before public launch.
- When mail delivery is configured, password reset tokens are random, stored as hashes, expire, are invalidated after use, and cause existing sessions to be revoked after a successful reset. Requests require CSRF protection and return an enumeration-safe response.
- `payment-config.js` contains no secrets, private keys, or live payment endpoint. The public UWallet alias is `Neen99`; CliQ remains flagged for setup until its recipient details are verified.

## Unused heavy assets

The listed directories had no references in HTML, CSS, JavaScript, JSON, or PHP and were removed. Sizes are original file sizes.

| Removed file | Size (bytes) |
|---|---:|
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.45 AM (1).jpeg` | 208800 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.45 AM.jpeg` | 259121 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.46 AM (1).jpeg` | 214636 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.46 AM (2).jpeg` | 223315 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.46 AM (3).jpeg` | 318563 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.46 AM (4).jpeg` | 321056 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.46 AM.jpeg` | 180157 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.47 AM (1).jpeg` | 206855 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.47 AM (2).jpeg` | 281839 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.47 AM.jpeg` | 234002 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.48 AM (1).jpeg` | 249226 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.48 AM.jpeg` | 333971 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.49 AM (1).jpeg` | 287251 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.49 AM (2).jpeg` | 288269 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.49 AM.jpeg` | 233276 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.50 AM.jpeg` | 296260 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.51 AM (1).jpeg` | 235806 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.51 AM.jpeg` | 293108 |
| `WhatsApp Unknown 2026-10-29 at 2.26.01 PM/WhatsApp Image 2026-10-28 at 12.03.52 AM.jpeg` | 247053 |
| `color/WhatsApp Image 2026-10-29 at 11.04.56 PM.jpeg` | 65809 |
| `color/ss/Dream Beyond Limits.png` | 1929037 |
| `neen/Neon NEEN Free Fire Gaming Homepage-1.png` | 2233787 |

`ven/WhatsApp Video 2026-10-29 at 8.27.50 PM.mp4` (3,361,693 bytes) was retained because `index.html` references it.

## cPanel deployment checks

- Confirm Apache honors `.htaccess` (`AllowOverride` is enabled) and `mod_rewrite` is available. If these rules are ignored, the direct-access protections do not apply.
- Configure the environment variables from `.env.example` through the hosting environment; PHP does not load `.env` files automatically. Use a least-privileged database account and keep all credentials out of the webroot and source control.
- Configure HTTPS and SMTP-backed email ownership verification before public registration. Until verification is enabled, treat unverified email ownership as a known launch blocker for services that depend on trusted identities.
