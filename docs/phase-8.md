# NEEN Phase 8 — Daily Attendance and Weekly Rewards

Phase 8 adds daily attendance to the existing account page and credits each successful daily check-in to the existing Earned Coins wallet through `CoinsLedger`. It does not create another wallet or change game rewards, paid attempts, Coins packages, or purchased balance.

## Rules and server flow

- The server determines the current date using `Africa/Cairo`. No date or user ID is accepted from the browser.
- An authenticated user can receive one 100 Earned Coins reward per Cairo date.
- Seven consecutive attendance days progress through one cycle, for 700 Coins in total. The reward is 100 Coins each day; there is no separate completion bonus.
- Missing a Cairo date, or completing a seven-day cycle, starts the next attendance on day one of a new cycle. Previously earned Coins remain in the wallet.
- An attendance row, its `daily_attendance` ledger credit, and the wallet update are committed in the same database transaction. Any ledger failure rolls back the attendance as well.
- The user row is locked during check-in on MySQL; SQLite uses `BEGIN IMMEDIATE` in local tests. Unique constraints on user/date, user/cycle/day, and ledger entry plus a deterministic `daily-attendance-{YYYY-MM-DD}` idempotency key provide additional duplicate protection.
- `GET /api/attendance/daily` returns the authenticated user's Cairo date, cycle progress, seven-day display state, and current wallet balances.
- `POST /api/attendance/check-in` requires the authenticated session and CSRF token, and accepts an empty JSON object only. A new check-in returns `201`; a same-day replay returns the existing state with `200` and does not credit again.

## Changed implementation surfaces

- `api/attendance-schema.sql` — attendance table, foreign keys, fixed reward/cycle constraints, and uniqueness constraints.
- `api/src/Attendance.php` — server date, cycle progression/reset, transactional ledger credit, and response state.
- `api/src/Api.php`, `api/index.php` — authenticated attendance API routes and service loading.
- `api/migrate.php` — installs the attendance schema with the existing MySQL schemas.
- `account.html`, `account.css`, `account.js` — responsive seven-day attendance progress, check-in button, success/error state, and refreshed server wallet totals.
- `tests/fixtures/account-schema.sqlite.sql`, `tests/auth-api.php` — SQLite test schema and attendance API/service integration coverage.
- `README.md` — current account feature and Phase 8 documentation link.

## Verification

Executed successfully:

```sh
php tests/auth-api.php
find api tests -name '*.php' -print0 | xargs -0 -n1 php -l
node --check account.js
git diff --check
```

The integration suite uses a temporary SQLite database. Attendance tests cover seven consecutive days and 700 Earned Coins, a new cycle after day seven, missed-day reset while preserving the earned balance, same-day replay, concurrent same-day requests, unauthorized access, client-supplied date/user rejection, ledger-failure rollback, and isolation between users. The suite also runs the existing account, wallet, Memory Match, Picture Jigsaw, and game reward checks.

MySQL behavior was not run in this environment: `pdo_mysql` and a MySQL server are unavailable. The migration and MySQL `FOR UPDATE` serialization therefore still require verification against the configured hosting database. The test suite checks the API behavior using SQLite, but does not perform browser/device visual automation; responsive styling should also be checked in the target browsers.
