# Suvakamana — API Route Plan

Base URL: `/api/v1`  
Auth: Laravel Sanctum (Bearer token / SPA cookie)  
Response envelope (shared):

```json
{
  "success": true,
  "message": "OK",
  "data": {},
  "meta": {},
  "errors": null
}
```

---

## Phase 0 (implemented now)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/health` | Public | Liveness |
| GET | `/api/v1/health` | Public | Versioned health + DB/Redis checks |

---

## Auth (`/api/v1/auth`) — Phase 2

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/register` | Public | Customer registration |
| POST | `/login` | Public | Login (all roles) |
| POST | `/logout` | Auth | Logout current token |
| POST | `/logout-all` | Auth | Revoke all sessions |
| POST | `/forgot-password` | Public | Send reset link |
| POST | `/reset-password` | Public | Reset with token |
| POST | `/email/verify/{id}/{hash}` | Signed | Verify email |
| POST | `/mfa/setup` | Auth | Begin MFA |
| POST | `/mfa/verify` | Auth | Confirm MFA |
| GET | `/me` | Auth | Current user + roles |

---

## Public marketplace (`/api/v1`) — Phases 5–6

| Method | Path | Description |
|--------|------|-------------|
| GET | `/cuisines` | Cuisine categories |
| GET | `/restaurants` | Search/filter/sort |
| GET | `/restaurants/{slug}` | Restaurant detail |
| GET | `/restaurants/{slug}/menu` | Menu + categories |
| GET | `/restaurants/{slug}/reviews` | Reviews |
| GET | `/offers` | Active public offers |
| GET | `/featured` | Featured restaurants |

---

## Cart & checkout (`/api/v1/cart`, `/checkout`) — Phase 6

| Method | Path | Description |
|--------|------|-------------|
| GET | `/cart` | Current cart |
| POST | `/cart/items` | Add item (one-restaurant rule) |
| PATCH | `/cart/items/{id}` | Update qty/modifiers |
| DELETE | `/cart/items/{id}` | Remove item |
| DELETE | `/cart` | Clear cart |
| POST | `/cart/coupon` | Apply coupon |
| POST | `/checkout/quote` | Backend price recalculation |
| POST | `/checkout/place` | Create order (unpaid/pending) |

---

## Customer (`/api/v1/customer`) — Phases 5–7

| Method | Path | Description |
|--------|------|-------------|
| GET/PATCH | `/profile` | Profile |
| CRUD | `/addresses` | Saved addresses |
| GET | `/orders` | Order history |
| GET | `/orders/{orderNumber}` | Order detail + tracking |
| POST | `/orders/{orderNumber}/reorder` | Reorder |
| GET | `/orders/{orderNumber}/receipt` | Receipt |
| POST | `/reviews` | Leave review |
| GET/POST/DELETE | `/favourites` | Favourites |
| CRUD | `/support/tickets` | Support |

---

## Restaurant portal (`/api/v1/restaurant`) — Phases 3–4, 7, 9

All routes: auth + restaurant membership + `restaurant_id` scope.

| Method | Path | Description |
|--------|------|-------------|
| POST | `/applications` | Partner application |
| GET | `/me` | Current restaurant context |
| PATCH | `/profile` | Update profile |
| POST | `/media/logo` | Upload logo |
| POST | `/media/cover` | Upload cover |
| CRUD | `/hours` | Opening hours |
| GET/PATCH | `/settings` | Delivery/payment settings |
| CRUD | `/menu/categories` | Categories |
| CRUD | `/menu/items` | Items |
| POST | `/menu/items/{id}/duplicate` | Duplicate |
| PATCH | `/menu/items/bulk-availability` | Bulk availability |
| CRUD | `/menu/modifier-groups` | Modifiers |
| CRUD | `/offers` | Offers |
| GET | `/orders` | Filter by status |
| POST | `/orders/{id}/accept` | Accept |
| POST | `/orders/{id}/reject` | Reject |
| PATCH | `/orders/{id}/status` | Status transition |
| GET | `/finance/summary` | Earnings summary |
| GET | `/finance/settlements` | Settlements |
| GET | `/staff` | Staff list |
| POST/PATCH/DELETE | `/staff` | Manage staff |
| GET/POST | `/reviews` | Reviews + responses |

---

## Super admin (`/api/v1/admin`) — Phases 2–3, 8–12

All routes: auth + `super_admin`.

| Method | Path | Description |
|--------|------|-------------|
| GET | `/dashboard` | Platform KPIs |
| GET | `/restaurants` | All restaurants |
| GET | `/restaurants/applications` | Pending applications |
| POST | `/restaurants/{id}/approve` | Approve |
| POST | `/restaurants/{id}/reject` | Reject + reason |
| POST | `/restaurants/{id}/suspend` | Suspend + reason |
| POST | `/restaurants/{id}/reactivate` | Reactivate |
| PATCH | `/restaurants/{id}/commission` | Set commission |
| GET | `/orders` | All orders |
| GET | `/finance/revenue` | Platform revenue |
| GET | `/finance/settlements` | All settlements |
| POST | `/finance/settlements/{id}/adjust` | Adjustment + audit |
| GET | `/customers` | Customers |
| GET | `/disputes` | Disputes |
| GET | `/audit-logs` | Audit trail |
| GET/PATCH | `/settings` | Platform settings |
| CRUD | `/banners` | Banners |
| CRUD | `/coupons` | Global coupons |

---

## Payments & webhooks — Phase 8

| Method | Path | Description |
|--------|------|-------------|
| POST | `/payments/intent` | Create payment intent |
| POST | `/webhooks/stripe` | Stripe webhook (signature verified) |
| POST | `/webhooks/uber-direct` | Delivery webhook |
| POST | `/refunds` | Initiate refund (admin/policy) |

---

## Delivery — Phase 10

| Method | Path | Description |
|--------|------|-------------|
| POST | `/delivery/quote` | Get quote |
| POST | `/delivery` | Create delivery |
| POST | `/delivery/{id}/cancel` | Cancel |
| GET | `/delivery/{id}` | Status |

---

## Realtime (Reverb/Pusher) — Phase 7+

| Channel | Purpose |
|---------|---------|
| `restaurant.{id}.orders` | New/updated orders |
| `order.{orderNumber}` | Customer tracking |
| `admin.platform` | Security / ops alerts |

---

## Conventions

1. Never accept client-calculated `total_cents` as authoritative.
2. Restaurant routes resolve restaurant from membership, not from untrusted body alone.
3. Idempotency-Key header required for payment and order placement.
4. Rate-limit auth and webhook endpoints.
5. Pagination: `?page=&per_page=` with `meta.pagination`.
