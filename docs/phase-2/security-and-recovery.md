# Security Architecture, Validation, and Recovery

## 1. Security architecture

The backend must enforce a defense-in-depth model:

- validated requests
- authenticated sessions
- role-based access control
- unique identifiers for operations
- transactional financial writes
- audit logs for sensitive actions
- secure file handling for proofs
- server-only logic for wallet and reward updates

## 2. Input validation

Validation must include:

- type checking
- allowed ranges
- string length checks
- sanitized output for HTML rendering
- safe file validation for uploads
- allowed enum values only
- server-side confirmation of selected packages and game IDs

## 3. SQL security

Implementation must use:

- PDO prepared statements
- parameter binding
- no direct string concatenation for SQL
- strict transaction boundaries

## 4. CSRF and session security

Required items:

- CSRF tokens for all state-changing forms and API requests
- secure cookie flags
- session expiration and invalidation
- careful anti-bruteforce protections for login

## 5. File security

For payment proof uploads:

- allow only approved MIME types
- enforce max size
- generate random file names if needed
- prevent direct public access to stored proof files if possible
- record the file in the database with metadata

## 6. Audit and fraud controls

All sensitive actions must log:

- wallet credits/debits
- reward claims
- order review decisions
- price changes
- compensation decisions
- admin actions
- suspicious or invalid requests

## 7. Data validation and error handling

Standard error handling should include:

- `VALIDATION_ERROR`
- `AUTH_REQUIRED`
- `FORBIDDEN`
- `RESOURCE_NOT_FOUND`
- `DUPLICATE_REQUEST`
- `ATTEMPT_LIMIT_EXCEEDED`
- `INSUFFICIENT_BALANCE`
- `REWARD_ALREADY_CLAIMED`
- `PAYMENT_REVIEW_REQUIRED`

The system should fail safely and never silently continue with a partial success.

## 8. Backup and recovery

### Backup requirements

- daily MySQL backups
- file storage backup for uploads and static assets
- retention policy for audit logs and wallet history
- test restore procedures

### Recovery rule

The recovery process should reconstruct balances from the ledger, not from a single mutable value field. This ensures historical correctness after a restore or a rollback scenario.

## 9. Operational recovery

If an error affects a user’s game win or an attempt is wrongly consumed, the platform must:

- identify the source event
- review ledger and session history
- apply controlled compensation or reversal
- log a reasoned record in `audit_logs`

## 10. Summary

The design protects the project from browser-side balance manipulation, duplicate claims, reward abuse, fake diamond orders, and unreviewed payment requests. Security is built into the core architecture, not only the final deployment layer.
