# API Specifications

## 1. API style

The future implementation should expose JSON APIs using PHP 8.3 and PDO. All endpoints must enforce authentication and authorization and must return consistent JSON responses.

## 2. Response envelope

```json
{
  "success": true,
  "data": {},
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-11-07T00:00:00Z"
  },
  "errors": []
}
```

On failure:

```json
{
  "success": false,
  "data": null,
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-11-07T00:00:00Z"
  },
  "errors": [
    {
      "code": "VALIDATION_ERROR",
      "message": "Please provide a valid UID."
    }
  ]
}
```

## 3. Authentication endpoints

### POST /api/auth/register

Request:

```json
{
  "username": "neenplayer",
  "email": "player@example.com",
  "password": "StrongPassword123!",
  "display_name": "Neen Player"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "user_id": 123,
    "wallet_created": true,
    "wallet_id": 456
  }
}
```

### POST /api/auth/login

Request:

```json
{
  "email": "player@example.com",
  "password": "StrongPassword123!"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 123,
      "email": "player@example.com"
    },
    "session_expires_at": "2026-11-07T12:00:00Z"
  }
}
```

### POST /api/auth/logout

Required: authenticated session.

### GET /api/auth/me

Returns current authenticated user summary.

## 4. Wallet endpoints

### GET /api/wallet

Returns current wallet state.

Response example:

```json
{
  "success": true,
  "data": {
    "user_id": 123,
    "coin_balance": 1250,
    "diamond_balance": 0,
    "currency_code": "EGP",
    "updated_at": "2026-11-07T10:10:00Z"
  }
}
```

### GET /api/wallet/transactions

Query params:

- `limit`
- `offset`
- `type`
- `category`

### POST /api/wallet/adjustment

Admin-only endpoint.

Request:

```json
{
  "user_id": 123,
  "amount": 100,
  "reason": "manual credit",
  "idempotency_key": "manual-credit-001"
}
```

## 5. Game endpoints

### GET /api/games

Returns catalog of available games.

### POST /api/games/:slug/session

Starts a game attempt session.

Request:

```json
{
  "attempt_type": "free",
  "game_slug": "memory-match"
}
```

Response:

```json
{
  "success": true,
  "data": {
    "session_id": 999,
    "attempt_type": "free",
    "attempts_remaining_today": 2,
    "paid_attempts_available": 0
  }
}
```

### POST /api/games/:slug/submit

Request includes server-validated result state.

```json
{
  "session_id": 999,
  "result": {
    "score": 150,
    "won": true,
    "matches": 8
  },
  "idempotency_key": "memory-match-win-001"
}
```

Server rules:

- validate session ownership
- validate current status
- validate result against actual gameplay rules
- grant fixed 50 Coins only once
- prevent duplicate reward grant
- record all state changes in transaction

## 6. Rewards endpoints

### GET /api/rewards/campaigns

### GET /api/rewards/available

### POST /api/rewards/claim

Request:

```json
{
  "reward_id": 77,
  "idempotency_key": "reward-claim-77-001"
}
```

Rules:

- validate campaign status
- validate reward eligibility
- prevent duplicate claim per user and reward
- only one approved claim may exist per reward identity

## 7. Diamond endpoints

### GET /api/diamonds/packages

Returns active packages by country.

### POST /api/diamonds/orders

Request:

```json
{
  "country_code": "EG",
  "package_id": 10,
  "payment_method": "vodafone-cash",
  "player_uid": "123456789",
  "contact_phone": "+966500000000"
}
```

Server behavior:

- validate package availability
- create order row in `pending_review`
- do not mark as fulfilled or paid automatically
- return `order_id`

### POST /api/diamonds/orders/:id/proof

File upload proof submission.

### PATCH /api/diamonds/orders/:id/status

Admin-only review.

Possible statuses:

- `approved`
- `rejected`
- `paid`
- `fulfilled`
- `cancelled`

## 8. Admin endpoints

### GET /api/admin/pending-orders

### GET /api/admin/reward-claims

### GET /api/admin/audit-log

### PATCH /api/admin/game-settings

Admin-only configuration management for per-game pricing and attempt rules.

## 9. Validation rules

- all inputs must be server validated
- no frontend balance trust
- duplicate requests must use same idempotency key
- all writes must be logged
- all order, wallet, and reward changes must use database transactions

## 10. Error codes

- `AUTH_REQUIRED`
- `FORBIDDEN`
- `VALIDATION_ERROR`
- `RESOURCE_NOT_FOUND`
- `DUPLICATE_REQUEST`
- `INSUFFICIENT_BALANCE`
- `ATTEMPT_LIMIT_EXCEEDED`
- `GAME_NOT_AVAILABLE`
- `REWARD_ALREADY_CLAIMED`
- `PAYMENT_REVIEW_REQUIRED`
- `TECHNICAL_COMPENSATION_REQUIRED`

## 11. Security note

The API must reject requests that are incomplete, suspicious, or missing required authorization. Client-side manipulation is never accepted as authoritative state.
