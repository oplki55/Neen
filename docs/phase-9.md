# NEEN Phase 9 — Permission-Gated Admin Dashboard

The administration entry point is [`events-admin.php`](../events-admin.php). It verifies the existing `neen_session` opaque cookie against `user_sessions`, applies a 30-minute sliding expiry and a 12-hour absolute maximum lifetime, requires an active account, and checks server-stored permissions before extending the session or sending dashboard HTML. Anonymous or expired sessions are redirected to `/account.html`; authenticated accounts without an existing administrative permission receive HTTP 403. The old [`events-admin.html`](../events-admin.html) path contains only a redirect and no dashboard content.

The page does not accept a role or user identifier from the browser and grants no roles. Each administrative API repeats its own permission check; mutations also require the session-bound CSRF token from `/api/auth/csrf`. The migration registers the feature permissions and grants them to the existing `super_admin` role. It does not create an administrator or grant that role to any account.

## Connected sections

- **Dashboard:** live counts from permission-checked game, event, suggestion, diamond-order, and audit APIs. Counts that use bounded list APIs are labeled as latest results.
- **Users:** read-only search by username or user ID, with account status and effective roles/permissions. No account-state mutation is exposed.
- **Coins & Wallet:** read-only wallet balances and up to 100 recent immutable Coins ledger entries per account; there is no direct balance-edit API.
- **Coin Orders / Coins setup:** existing review, catalog, and payment-method APIs. Coin order approval remains subject to manual payment verification and the atomic Coins ledger transaction.
- **Homepage Rewards:** displays the persisted configuration and allows authorized admins to configure the reward amount, claim window, and active state. Claims remain server-authoritative and ledger-backed.
- **Community Events:** creates draft events, publishes/pauses/cancels voting, displays the event catalog, and shows event activity. The API enforces dates, options, and permission checks.
- **Event Suggestions:** lists pending user suggestions and records acceptance or rejection with an optional note.
- **Event Voting & Results:** shows server-calculated option totals and allows results approval only after the voting window ends.
- **Event Rewards:** admins with `manage_event_rewards` can issue a ledger-backed reward to an eligible participant after results are published. Eligibility and duplicate prevention are enforced by the API.
- **Diamond Packages:** creates inactive packages and toggles activation. Package quantities, prices, and currency are never seeded with invented values.
- **Diamond Recharge Orders:** searches by order number or Player ID, shows payment and delivery states, records allowed lifecycle transitions, and exposes the order activity history with administrator and note.
- **Games / Audit Log:** read-only game catalogs and recent audit records from the existing APIs.

The admin UI does not claim real payment verification or delivery. Diamond provider adapters are intentionally unconfigured and do not initiate external transactions. Package creation and order status updates are internal records only.

## Owner Dashboard integration

[`owner-dashboard.php`](../owner-dashboard.php) adds a separate owner-only management surface. It manages account profile fields, roles other than `owner`, active/suspended state, ledger-backed wallet adjustments, and runtime-backed game settings. Every Owner API checks both the `owner` role and `access_owner_dashboard`, and changes require CSRF validation. Account suspension revokes active sessions; self changes and changes to Owner accounts are denied. Existing community, rewards, recharge, and Coins workflows remain in this permission-checked admin console rather than being duplicated.

Administrative attendance reporting and general CMS/media management remain unavailable. The dashboard does not offer direct wallet balance edits. Configure and verify provider credentials only in the hosting environment after formal authorization. See [`phase-10-owner-dashboard.md`](phase-10-owner-dashboard.md) for deployment steps and limitations.

## Verification

Run the integration suite and syntax checks:

```sh
php tests/auth-api.php
find api tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -l events-admin.php
node --check events-admin.js
git diff --check
```

The HTTP integration suite uses a temporary SQLite database. It covers session and CSRF enforcement, administrative access, Rewards, event suggestion/review/voting/results/rewards, and the diamond order lifecycle including duplicate prevention and audit history. Production deployment still requires MySQL migration and HTTPS verification.
