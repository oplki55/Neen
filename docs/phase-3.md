# NEEN Phase 3 — Accounts and Sessions

This phase added a PHP 8.3 JSON API and account page while leaving the existing static pages and their content in place. It established identity, profile, roles and permissions, server-side sessions, password-reset tokens, audit records, rate limits, and a zero-balance wallet. The wallet has since been extended with the Coins ledger and purchase review flow described in [`phase-4.md`](phase-4.md); game actions, rewards, attempt purchases, Diamond orders, and payment processing remain out of scope.

## Deployment requirements

- PHP 8.3+ with PDO and `pdo_mysql`
- MySQL 8.x, with an existing database and a database user limited to that database
- HTTPS enabled for the site
- PHP environment variables configured in the hosting account, not in browser files or a public config file
- A working PHP `mail()` transport and a valid sender address for password-recovery messages

Configure these server-side environment variables:

| Variable | Purpose |
| --- | --- |
| `NEEN_DB_HOST` | MySQL hostname |
| `NEEN_DB_NAME` | Existing database name |
| `NEEN_DB_USER` | Restricted database username |
| `NEEN_DB_PASSWORD` | Database password |
| `NEEN_APP_KEY` | At least 32 cryptographically random characters, used to sign anonymous CSRF cookies and protect rate-limit identifiers |
| `NEEN_APP_URL` | Public HTTPS origin of the site, without a trailing slash |
| `NEEN_MAIL_FROM` | Valid sender email address for reset emails |

`NEEN_DB_DSN` may be set instead of the host and database name, for example `mysql:host=localhost;dbname=DATABASE_NAME;charset=utf8mb4`. Do not commit credentials, a production app key, or a local secrets file.

Run the schema installer from the hosting account's PHP CLI after setting the same database environment variables:

```sh
php api/migrate.php
```

The migration creates the account and Coins tables and seeds the roles and permission definitions. New users receive only read-only wallet permissions; Coins administration permissions are assigned only to the existing `super_admin` role. It does not create a database, create a super-admin account, or modify existing site tables. Grant an initial administrative role or other staff permissions only through a trusted database session after verifying the account and approved assignments; there is no public privilege-escalation endpoint.

The API uses the Apache rewrite rules in `api/.htaccess`. Deploy the site over HTTPS with the project root as the document root. Session cookies are `Secure`, `HttpOnly`, and `SameSite=Lax`; account operations reject non-HTTPS requests outside tests. The account UI is at `/account.html`.

## Account and email behavior

Registration requires a unique username, valid email address, and password and creates the profile, default `user` role, and one zero-balance wallet in a single transaction. Passwords are stored with PHP `password_hash()` and checked with `password_verify()`. Login accepts either the registered email address or username; older API clients may continue sending the `email` field.

Registration creates a `pending_verification` account. Email verification is mandatory: login and session authentication require `email_verified = 1`, and verification tokens expire after 24 hours and are single-use. Resend is rate-limited and returns a non-enumerating response. Legacy accounts that are active but unverified also cannot authenticate and can receive a verification resend. Password recovery proves mailbox access using a single-use token that expires after 60 minutes and revokes existing sessions after reset. The database stores only password-reset and verification token hashes. If the hosting mail transport is unavailable, delivery failure is recorded in the server error log while the public response remains non-enumerating.

Sessions use random opaque cookies; only their SHA-256 hashes are stored. Sessions expire after 30 minutes of inactivity, are refreshed on authenticated requests, and can be revoked on logout or password reset. State-changing endpoints require a CSRF token. Login, registration, and password-recovery requests are rate limited using database-backed hashed buckets.

## API endpoints

| Method | Path | Access |
| --- | --- | --- |
| `GET` | `/api/auth/csrf` | Public; obtains a CSRF token |
| `POST` | `/api/auth/register` | Public; requires CSRF |
| `POST` | `/api/auth/login` | Public; requires CSRF |
| `POST` | `/api/auth/logout` | Authenticated; requires CSRF |
| `GET` | `/api/auth/me` | Authenticated |
| `POST` | `/api/auth/password/forgot` | Public; requires CSRF |
| `POST` | `/api/auth/password/reset` | Public; requires CSRF and a one-time email token |
| `GET`, `PATCH` | `/api/users/me` | Authenticated; `PATCH` requires CSRF |
| `GET` | `/api/wallet` | Authenticated with `view_wallet`; read-only snapshot |

JSON responses follow the Phase 2 envelope and include a request ID. Clients obtain a CSRF token from `/api/auth/csrf` before public mutations, then send it in `X-CSRF-Token`. After login, use the `csrf_token` returned by the login response for authenticated mutations. All protected routes resolve the server-side session and active account status; caller-supplied user IDs or roles are never trusted.

## Verification

Run the HTTP integration tests with:

```sh
php tests/auth-api.php
```

The test starts a local PHP server against a temporary SQLite database and a test-only email sink. It exercises valid, invalid, and duplicate registration; email- and username-based login; invalid credentials; logout and session revocation; CSRF enforcement; profile reads and updates; protected-route denial; one-time zero-balance wallet creation; password recovery; hashed/single-use reset tokens; and login with the reset password. SQLite is test-only; production requires MySQL. MySQL migration, HTTPS cookie behavior, and the hosting mail transport must be verified after deployment with the configured hosting database and mail service.
