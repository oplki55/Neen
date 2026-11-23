# NEEN Phase 10 — Owner Dashboard

## What is connected

[`owner-dashboard.php`](../owner-dashboard.php) is a separate, server-protected control room. It requires an active `neen_session`, the `owner` role, and `access_owner_dashboard`; its APIs repeat the role and permission check. CSRF protection applies to all mutations. The migration creates the Owner role and grants it the existing administrative permissions, but never assigns it to a user.

- **Overview:** real database counts for accounts, recent/active users, wallet balances and ledger activity, diamond orders, events, votes, rewards, attendance, and games. Four charts use daily database aggregates over the preceding 30 days. Recent registrations, orders, and audit entries are read from their tables.
- **Users:** searched, filtered, sorted, paginated account records; account details include the profile, roles, effective permissions, wallet, recent ledger entries, recharge orders, game sessions, and current session count. Owner actions can edit limited profile fields, grant/revoke existing non-owner roles, and activate/suspend accounts. Self role/status changes and changes to any Owner account are denied by the API. Suspending an account revokes its active sessions.
- **Wallet:** Coins adjustments call the existing `/api/admin/wallets/{id}/adjustments` transaction and immutable ledger, with required reasons, idempotency keys, non-negative-balance checks, and audit records. No direct wallet-column edit is exposed.
- **Games:** Owner APIs edit name, description, active/disabled/maintenance state, daily free attempts, and verified-win reward amount. These fields are read by both game services and the reward ledger. Board size, payment for attempts, and other rules that are not safely configurable remain unavailable.
- **Site settings:** an idempotent `site_settings` table stores the current TikTok profile URL and site description. The public `/api/site-settings` endpoint returns only those values; shared site JavaScript applies them to existing profile links and the page description. The TikTok value is restricted to the official profile URL format.
- **Connected administration:** the existing `events-admin.php` console is linked rather than duplicated. It handles existing Homepage Rewards, Community Events, Diamond Recharge, Coins, and other database-backed modules using their current permission-checked APIs.
- **Admin activity:** read-only, searchable, paginated audit entries include available actor, role, action, entity, payload, IP, and timestamp. Owner account/profile/role/status/game/settings operations are audited with before/after details.

## Not implemented / external limits

- Videos, gallery assets, and general page content remain static project files; there is no media upload/storage/publishing workflow. No placeholder CMS controls are shown.
- Broader reports such as arbitrary date ranges and exports are not implemented; dashboard charts are fixed to 30 days.
- Diamond payments and fulfillment remain manual/internal. No payment or delivery provider is configured, and the dashboard does not claim an external transaction succeeded.
- No production Owner identity was provided. Owner access remains unavailable until an authorized operator assigns the role to the verified account through a trusted MySQL session. Do not expose this step as a public endpoint.
- MySQL migration/production hosting and browser-based visual testing were unavailable in this environment. Local integration tests use a temporary SQLite database and do not prove a production deployment.

## One-time Owner provisioning

After verifying the owner's account out-of-band and running the database migration, an authorized database operator can assign the role. Replace the email placeholder with the verified account; execute this SQL only over the trusted hosting/database administration channel:

```sql
INSERT INTO user_roles (user_id, role_id, granted_at)
SELECT u.id, r.id, UTC_TIMESTAMP()
FROM users u
JOIN roles r ON r.name = 'owner'
WHERE u.email = 'verified-owner@example.com'
  AND u.status = 'active'
ON DUPLICATE KEY UPDATE granted_at = VALUES(granted_at);
```

The web dashboard cannot grant or revoke `owner`, including for another Owner.

## Verification

Run:

```sh
php tests/auth-api.php
php -l api/src/OwnerDashboard.php
php -l api/src/Api.php
php -l owner-dashboard.php
node --check owner-dashboard.js
node --check script.js
git diff --check
```

The integration suite covers regular-user/admin/Owner separation, Owner page and API gates, real overview data, game setting enforcement, settings persistence and URL validation, profile/role/status operations, self/Owner protections, session revocation, wallet idempotency and insufficient-balance handling, and audit records.
