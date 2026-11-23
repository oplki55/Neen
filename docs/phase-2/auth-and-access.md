# Authentication and Access Control

## 1. Registration policy

The project approves open registration for any visitor. Registration must be accepted by the backend without manual invitation or host-side gatekeeping.

## 2. Account lifecycle

A user account may have the following states:

- `pending_verification`
- `active`
- `suspended`
- `deleted`

Only `active` users may participate in game attempts, reward claims, or diamond orders.

## 3. Password model

- password is never stored in plaintext
- PHP `password_hash()` is required
- `password_verify()` must be used for validation
- password resets must be protected by secure token workflows

## 4. Session model

Session security requirements:

- server-generated session tokens
- cookies with `HttpOnly`, `Secure`, and `SameSite=Lax` or `Strict`
- session expiry on inactivity or explicit logout
- token rotation on login and privilege escalation
- server-side revocation support

## 5. Session tables

The `user_sessions` table stores:

- user ID
- hashed token value
- user agent metadata
- IP address
- expiry timestamp
- revocation timestamp

## 6. Roles and permissions

The role model supports multiple admin users with independent privileges.

### Core roles

- `user`
- `moderator`
- `game_admin`
- `support_admin`
- `super_admin`

### Permission examples

- `view_wallet`
- `submit_diamond_order`
- `claim_reward`
- `review_payment`
- `manage_game_settings`
- `approve_reward`
- `view_audit_logs`
- `adjust_wallet`

## 7. Multi-admin model

The design allows several admins with independent privileges. In addition, admins may be assigned to specific games through `admin_game_assignments`, which makes a game specialist model possible without a single global admin monolith.

## 8. Permission enforcement

Every protected API endpoint must check:

1. session validity
2. user status
3. role ownership
4. requested permission
5. operation-specific ownership or game assignment

## 9. CSRF and anti-abuse

- CSRF tokens for state-changing endpoints
- rate limiting for login and order attempts
- server-side validation of all input
- no secret keys inside browser-side JavaScript

## 10. Frontend rule

The browser must never be trusted with account identity, authorization decisions, wallet balance, reward approval, or final game outcome.

## 11. Summary

Authentication and access are designed for a secure multi-user backend where admins are role-based and game-specific, while ordinary users can register open and receive a wallet automatically at creation.
