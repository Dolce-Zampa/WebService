# FE Integration - Product Promotions

This document describes how the frontend should integrate with the backend promotion system.

## Authentication
All seller promotion endpoints require seller authentication (same auth used for `/api/seller/*`).

## Endpoints

### 1) Get available packages
`GET /api/seller/promotions/packages`

Optional query params:
- `position`: `homepage` | `category` | `product_page`

Example response:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Boost 7 giorni",
      "duration_days": 7,
      "price": 5,
      "position": "category",
      "active": 1
    }
  ]
}
```

### 2) Get promotable seller products
`GET /api/seller/promotions/products`

Returns only products owned by the authenticated seller and currently promotable.

### 3) Create promotion request
`POST /api/seller/promotions`

Body:
```json
{
  "product_id": 123,
  "package_id": 1
}
```

Notes:
- `price` is always taken from backend package configuration.
- Frontend must never send/override the package price.

### 4) Create Stripe checkout session
`POST /api/seller/promotions/{promotionId}/checkout`

Optional body:
```json
{
  "success_url": "https://your-frontend/success",
  "cancel_url": "https://your-frontend/cancel"
}
```

Response includes:
- `stripe_session_id`
- `payment_url` (redirect user here)

### 5) Get active sponsored products (public)
`GET /api/product-promotions`

Optional query params:
- `position`: `homepage` | `category` | `product_page`
- `limit`: number of sponsored products

Behavior:
- Returns only active and non-expired promotions.
- Rotation is applied server-side (different products can appear across requests).

### 6) Track sponsored click (public)
`POST /api/product-promotions/{promotionId}/click`

Use this when user clicks a sponsored card/link.

## Recommended FE flow
1. Load packages from `GET /api/seller/promotions/packages`.
2. Load promotable products from `GET /api/seller/promotions/products`.
3. Create promotion with `POST /api/seller/promotions`.
4. Create checkout with `POST /api/seller/promotions/{promotionId}/checkout`.
5. Redirect user to `payment_url`.
6. After payment, show a pending/processing state until backend webhook confirms activation.

## Important
- Promotion activation is **webhook-driven** (`checkout.session.completed` on backend).
- Do not mark promotion as active in FE based only on redirect success page.
- Handle temporary pending states gracefully in UI.
