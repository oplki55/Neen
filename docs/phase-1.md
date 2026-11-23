# NEEN Phase 1 — Production Database & Infrastructure

This phase establishes the production-ready database and hosting configuration required for the account and Coins APIs.

## Database layer

- Use MySQL 8.x in production.
- Keep SQLite only for local tests and CI smoke checks.
- Validate driver compatibility in `api/src/Database.php` before executing any migration.
- Configure the same schema flow for both MySQL and SQLite test targets.

## Deployment configuration

For local development, copy `.env.example` to the project root as `.env`. On cPanel, select PHP 8.3 in MultiPHP Manager and place the configured `.env` outside the document root. For a document root of `~/public_html/neeng.cc/`, the loader checks `~/neeng.cc/.env` first and only uses the project-root `.env` as a local fallback. Restrict the external file to the hosting account owner (for example, mode `600` where supported). Never commit `.env` or production secrets.

Required variables:

- `APP_ENV` — application environment (`production`, `staging`, or `test`)
- `NEEN_APP_KEY` — minimum 32 random characters used to protect session and CSRF material
- `NEEN_APP_URL` — public HTTPS origin without a trailing slash
- `NEEN_DB_DSN` — optional MySQL DSN override
- `NEEN_DB_HOST` / `NEEN_DB_NAME` / `NEEN_DB_USER` / `NEEN_DB_PASSWORD` — production MySQL connection details
- `NEEN_MAIL_FROM` — sender address for recovery emails

## Migration safety

- `api/migrate.php` applies the account, Coins, games, attendance, homepage rewards, community events, and diamond recharge schemas.
- Schema creation and permission registration are safe to rerun. Reward settings and diamond packages are not seeded with assumed values.
- It requires MySQL and performs guarded column and trigger additions.
- It refuses legacy wallet reconciliation when prior nonzero balances exist without an approved migration path.

## Production readiness checks

Before launch, verify:

1. HTTPS is enabled.
2. Database credentials are stored server-side only.
3. `php api/migrate.php` succeeds against a staging MySQL database.
4. The app can read/write wallet and session records without leaking connection details.
5. `.env` stays out of source control.

The project intentionally does not seed a production admin account or payment provider credentials. Those must be created through an approved hosting/admin workflow.
