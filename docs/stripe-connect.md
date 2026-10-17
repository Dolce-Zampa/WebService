# Stripe Connect Express

Connect is an optional payment module. The legacy platform Stripe Checkout
remains active unless both settings below are enabled:

```dotenv
STRIPE_PAYMENT_FLOW=connect
STRIPE_CONNECT_ENABLED=true
```

All endpoints require a seller Bearer token. The API derives the manufacturer
from that token; clients must never supply a manufacturer or Stripe account ID.

## Create the Express account

`POST /api/seller/stripe-connect/accounts`

Creates one Stripe Express connected account for the authenticated
manufacturer. The account is created with the server-configured country and
the `transfers` capability. Repeating the request is safe: it returns the
already associated account and `created: false`.

```json
{
  "success": true,
  "data": {
    "created": true,
    "account": {
      "connected": true,
      "account_id": "acct_...",
      "details_submitted": false,
      "payouts_enabled": false,
      "transfers_enabled": false,
      "ready_for_transfers": false,
      "requirements_due": []
    }
  }
}
```

## Start or resume onboarding

`POST /api/seller/stripe-connect/onboarding`

Optional JSON body fields `return_url` and `refresh_url` override the two
server defaults. The response contains a single-use `onboarding_url`; return
it only to the authenticated seller and do not send it by email.

## Read account status

`GET /api/seller/stripe-connect/status`

Refreshes the account state from Stripe. A redirect to `return_url` does not
by itself mean onboarding is complete: use `ready_for_transfers` before
allowing a seller to receive transfers.
