# Suvakamana — Database ERD

**Runtime database:** MySQL / MariaDB with `utf8mb4` / `utf8mb4_unicode_ci` and InnoDB.

All monetary amounts are **integer cents**. Multi-tenant restaurant data is scoped by `restaurant_id`.

## Legend

```text
[PK]  Primary key
[FK]  Foreign key
[UQ]  Unique
[IX]  Indexed
──<   One-to-many
───   One-to-one / many-to-many via pivot
```

---

## 1. Users & Security

```text
users
├── id [PK]
├── email [UQ]
├── password (hashed)
├── name
├── phone
├── email_verified_at
├── phone_verified_at
├── status (active|locked|suspended)
├── last_login_at
├── created_at / updated_at
│
├── roles ──< role_permissions >── permissions
├── user_roles >── roles
├── password_reset_tokens
├── personal_access_tokens (Sanctum)
├── login_attempts
├── user_sessions
├── mfa_methods
└── audit_logs (actor_user_id)
```

```text
roles                permissions
├── id               ├── id
├── slug [UQ]        ├── slug [UQ]
├── name             ├── name
└── guard            └── description

role_permissions
├── role_id [FK]
└── permission_id [FK]

user_roles
├── user_id [FK]
├── role_id [FK]
├── restaurant_id [FK, nullable]   # null = platform-level role
└── [UQ user_id + role_id + restaurant_id]
```

```text
login_attempts
├── id, email, ip_address, user_agent
├── successful (bool)
└── created_at

mfa_methods
├── id, user_id [FK]
├── type (totp|webauthn)
├── secret_encrypted
├── is_primary
└── verified_at

audit_logs
├── id, actor_user_id [FK, nullable]
├── action, subject_type, subject_id
├── restaurant_id [FK, nullable]
├── ip_address, user_agent
├── metadata (jsonb)
└── created_at
```

---

## 2. Restaurants & Onboarding

```text
restaurants
├── id [PK]
├── slug [UQ]
├── name
├── description
├── logo_path, cover_path
├── email, phone
├── address_line1, address_line2, city, state, postal_code, country
├── latitude, longitude
├── status (pending|approved|rejected|suspended|active)
├── application_status
├── rejection_reason
├── is_featured
├── avg_rating, review_count
├── min_order_cents
├── preparation_time_minutes
├── timezone
├── approved_at, suspended_at
└── created_at / updated_at

restaurant_users
├── id
├── restaurant_id [FK]
├── user_id [FK]
├── role_slug (owner|manager|kitchen|cashier|order_operator)
├── is_owner
└── [UQ restaurant_id + user_id]

restaurant_documents
├── id, restaurant_id [FK]
├── type, file_path, status, reviewed_at, reviewed_by

restaurant_hours
├── id, restaurant_id [FK]
├── day_of_week (0-6)
├── opens_at, closes_at
├── is_closed

restaurant_service_areas
├── id, restaurant_id [FK]
├── name, polygon (jsonb) | radius_km
├── delivery_fee_cents, min_order_cents

restaurant_settings
├── restaurant_id [PK/FK]
├── accepts_delivery, accepts_pickup
├── accepts_cash, accepts_card
├── auto_accept_orders
├── notification_preferences (jsonb)

restaurant_commission_settings
├── id, restaurant_id [FK]
├── commission_rate (decimal)
├── fixed_fee_cents
├── effective_from, effective_to
├── is_current
└── set_by_user_id [FK]

commission_history
├── id, restaurant_id [FK]
├── previous_rate, new_rate
├── reason, changed_by [FK]
└── created_at

restaurant_payout_accounts
├── id, restaurant_id [FK]
├── provider (stripe_connect)
├── external_account_id
├── status, onboarding_completed_at
```

**Cuisine link:** `restaurant_cuisines` (restaurant_id, cuisine_category_id)

**Global:** `cuisine_categories`, `platform_banners`, `platform_settings`

---

## 3. Menus

```text
menu_categories
├── id, restaurant_id [FK]
├── name, slug, description, sort_order
├── is_active

menu_items
├── id, restaurant_id [FK], menu_category_id [FK]
├── name, slug, description
├── image_path
├── base_price_cents
├── is_available, is_featured
├── dietary_labels (jsonb)   # vegetarian, vegan, gluten_free, etc.
├── prep_time_minutes
├── sort_order

menu_item_variants
├── id, menu_item_id [FK]
├── name, price_cents, is_default, is_available

modifier_groups
├── id, restaurant_id [FK]
├── name, min_select, max_select, is_required

modifier_options
├── id, modifier_group_id [FK]
├── name, price_cents, is_available, sort_order

menu_item_modifier_groups
├── menu_item_id [FK], modifier_group_id [FK]

allergens / menu_item_allergens
offers
├── id, restaurant_id [FK]
├── title, type, value, starts_at, ends_at, is_active

coupons
├── id
├── code [UQ]
├── scope (platform|restaurant)
├── restaurant_id [FK, nullable]
├── type, value, min_order_cents
├── max_uses, used_count
├── starts_at, ends_at, is_active

menu_availability
├── id, menu_item_id [FK]
├── day_of_week, available_from, available_to
```

---

## 4. Customers & Carts

```text
customer_profiles
├── user_id [PK/FK]
├── display_name, avatar_path
├── default_address_id

customer_addresses
├── id, customer_id [FK → users]
├── label, line1, line2, city, state, postal_code, country
├── latitude, longitude
├── delivery_instructions
├── is_default

carts
├── id [PK]
├── customer_id [FK, nullable]   # null = guest session
├── session_token [UQ, nullable]
├── restaurant_id [FK, nullable] # ONE restaurant per cart
└── updated_at

cart_items
├── id, cart_id [FK]
├── menu_item_id [FK]
├── variant_id [FK, nullable]
├── quantity
├── special_instructions

cart_item_modifiers
├── id, cart_item_id [FK]
├── modifier_option_id [FK]
├── quantity

favourites
├── customer_id, restaurant_id | menu_item_id
```

**Cart rule:** `carts.restaurant_id` enforces single-restaurant carts at the data layer.

---

## 5. Orders

```text
orders
├── id [PK]
├── order_number [UQ]
├── restaurant_id [FK]
├── customer_id [FK]
├── status
├── payment_status
├── fulfilment_type (delivery|pickup)
├── subtotal_cents
├── restaurant_discount_cents
├── platform_discount_cents
├── tax_cents
├── delivery_fee_cents
├── service_fee_cents
├── processing_fee_cents
├── commission_rate          # snapshot
├── commission_amount_cents  # snapshot
├── restaurant_net_amount_cents
├── total_cents
├── currency
├── delivery_address_snapshot (jsonb)
├── placed_at, accepted_at, completed_at, cancelled_at
└── timestamps

order_items
├── id, order_id [FK]
├── menu_item_id [FK, nullable]  # keep null-safe if item deleted
├── item_name_snapshot
├── description_snapshot
├── image_snapshot
├── unit_price_cents
├── quantity
├── variant_snapshot (jsonb)
├── modifier_snapshot (jsonb)
├── allergen_snapshot (jsonb)
├── total_cents

order_item_modifiers
├── id, order_item_id [FK]
├── name_snapshot, price_cents, quantity

order_status_history
├── id, order_id [FK]
├── from_status, to_status
├── changed_by_user_id [FK, nullable]
├── note
└── created_at

order_notes / order_adjustments
```

**Valid status machine** (enforced in domain service):

```text
DRAFT → PENDING_PAYMENT → PAID → AWAITING_RESTAURANT
  → ACCEPTED → PREPARING → READY_FOR_PICKUP
  → DELIVERY_REQUESTED → COURIER_ASSIGNED → PICKED_UP → DELIVERED

Branches: PAYMENT_FAILED, REJECTED, CANCELLED,
          REFUND_PENDING → PARTIALLY_REFUNDED | REFUNDED
```

---

## 6. Payments & Finance

```text
payments
├── id, order_id [FK]
├── provider, external_payment_id [UQ]
├── amount_cents, currency, status
├── idempotency_key [UQ]

payment_attempts / refunds
platform_fees
restaurant_settlements
├── id, restaurant_id [FK]
├── period_start, period_end
├── gross_sales_cents
├── commission_cents
├── fees_cents, refunds_cents, adjustments_cents
├── net_payout_cents
├── status

restaurant_settlement_items → orders
restaurant_payouts
financial_ledger
├── id, entry_type, amount_cents
├── restaurant_id, order_id, settlement_id (nullable FKs)
├── description, metadata

invoices / webhook_events
├── id, provider, event_id [UQ]
├── payload (jsonb), processed_at, status
```

---

## 7. Delivery

```text
delivery_providers
├── id, slug, name, is_active, config (encrypted jsonb)

delivery_quotes
├── id, order_id [FK, nullable], restaurant_id [FK]
├── provider, external_quote_id
├── quote_amount_cents, expires_at
├── pickup_address, dropoff_address (jsonb)

deliveries
├── id, order_id [FK], quote_id [FK, nullable]
├── provider, external_delivery_id
├── delivery_status
├── tracking_url
├── courier_name, courier_phone
├── estimated_pickup_at, estimated_dropoff_at
├── failure_reason

delivery_events / delivery_proofs
```

---

## 8. Reviews, Support, Notifications

```text
restaurant_reviews
├── id, restaurant_id [FK], customer_id [FK], order_id [FK]
├── rating (1-5), body, is_visible

review_responses
├── id, review_id [FK], restaurant_user_id [FK], body

support_tickets / support_messages / disputes

notifications (Laravel notifications table + custom)
notification_deliveries
email_logs / sms_logs
device_tokens
```

---

## Entity Relationship Overview (simplified)

```text
users ──< restaurant_users >── restaurants
restaurants ──< menu_categories ──< menu_items
menu_items ──< variants
menu_items >── modifier_groups ──< modifier_options
restaurants ──< orders ──< order_items
orders ─── payments
orders ─── deliveries
restaurants ──< restaurant_settlements ──< settlement_items
restaurants ── restaurant_commission_settings
customers (users) ──< carts ──< cart_items
customers ──< customer_addresses
orders ──< order_status_history
webhook_events (idempotent ingest)
```

## Indexing Strategy (Phase 0 notes)

Critical indexes created with domain migrations (Phases 2+):

| Table | Indexes |
|-------|---------|
| `orders` | `(restaurant_id, status)`, `(customer_id, placed_at)`, `order_number` |
| `menu_items` | `(restaurant_id, menu_category_id)`, `(restaurant_id, is_available)` |
| `carts` | `(customer_id)`, `session_token`, `restaurant_id` |
| `payments` | `external_payment_id`, `idempotency_key` |
| `webhook_events` | `(provider, event_id)` unique |
| `audit_logs` | `(actor_user_id, created_at)`, `(restaurant_id, created_at)` |

## Phase 0 migration scope

Phase 0 ships only foundation tables required to boot the API:

- Laravel default: `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`
- Sanctum: `personal_access_tokens`
- Stub: `roles`, `permissions`, `role_permissions`, `user_roles` (empty structure for Phase 2)

Full domain migrations begin in Phase 2–3.
