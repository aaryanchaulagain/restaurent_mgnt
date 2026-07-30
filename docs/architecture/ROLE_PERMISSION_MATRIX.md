# Suvakamana — Role & Permission Matrix

## Roles

| Role slug | Scope | Portal |
|-----------|-------|--------|
| `super_admin` | Platform | Super Admin |
| `restaurant_owner` | Restaurant | Restaurant Admin |
| `restaurant_manager` | Restaurant | Restaurant Admin |
| `kitchen_staff` | Restaurant | Restaurant Admin (limited) |
| `cashier` | Restaurant | Restaurant Admin (limited) |
| `order_operator` | Restaurant | Restaurant Admin (orders) |
| `customer` | Self | Public / Customer |
| `delivery_driver` | Assigned deliveries | Driver app *(optional; deferred)* |

Restaurant-scoped roles always bind via `user_roles.restaurant_id` or `restaurant_users`.

---

## Permission Matrix

Legend: **F** = Full · **R** = Read · **W** = Write · **—** = None · **Own** = own restaurant/self only

### Platform & restaurants

| Permission | Super Admin | Owner | Manager | Kitchen | Cashier | Order Op | Customer |
|------------|:-----------:|:-----:|:-------:|:-------:|:-------:|:--------:|:--------:|
| `platform.settings.manage` | F | — | — | — | — | — | — |
| `platform.banners.manage` | F | — | — | — | — | — | — |
| `platform.coupons.manage` | F | — | — | — | — | — | — |
| `restaurants.applications.review` | F | — | — | — | — | — | — |
| `restaurants.approve` | F | — | — | — | — | — | — |
| `restaurants.reject` | F | — | — | — | — | — | — |
| `restaurants.suspend` | F | — | — | — | — | — | — |
| `restaurants.reactivate` | F | — | — | — | — | — | — |
| `restaurants.commission.manage` | F | — | — | — | — | — | — |
| `restaurants.view_all` | R | — | — | — | — | — | — |
| `restaurant.profile.manage` | R* | F | F | — | — | — | — |
| `restaurant.hours.manage` | — | F | F | — | — | — | — |
| `restaurant.settings.manage` | — | F | F | — | — | — | — |
| `restaurant.staff.manage` | — | F | W | — | — | — | — |
| `restaurant.documents.manage` | R | F | R | — | — | — | — |

\*Super admin may view restaurant profile; edits require audit log (Phase 3+).

### Menu

| Permission | Super Admin | Owner | Manager | Kitchen | Cashier | Order Op | Customer |
|------------|:-----------:|:-----:|:-------:|:-------:|:-------:|:--------:|:--------:|
| `menu.categories.manage` | — | F | F | — | — | — | — |
| `menu.items.manage` | — | F | F | — | — | — | — |
| `menu.availability.toggle` | — | F | F | W | — | — | — |
| `menu.offers.manage` | — | F | F | — | — | — | — |
| `menu.public.browse` | R | R | R | R | R | R | R |

### Orders

| Permission | Super Admin | Owner | Manager | Kitchen | Cashier | Order Op | Customer |
|------------|:-----------:|:-----:|:-------:|:-------:|:-------:|:--------:|:--------:|
| `orders.view_all_platform` | R | — | — | — | — | — | — |
| `orders.view_restaurant` | R | F | F | R | R | F | — |
| `orders.accept` | — | F | F | — | — | F | — |
| `orders.reject` | — | F | F | — | — | F | — |
| `orders.update_status` | — | F | F | W† | — | F | — |
| `orders.view_own` | — | — | — | — | — | — | Own |
| `orders.cancel_own` | — | — | — | — | — | — | Own‡ |
| `orders.refund.manage` | F | — | — | — | — | — | — |

†Kitchen: preparing → ready only. ‡Within policy window.

### Finance

| Permission | Super Admin | Owner | Manager | Kitchen | Cashier | Order Op | Customer |
|------------|:-----------:|:-----:|:-------:|:-------:|:-------:|:--------:|:--------:|
| `finance.platform.view` | R | — | — | — | — | — | — |
| `finance.settlements.manage` | F | — | — | — | — | — | — |
| `finance.restaurant.view` | R | F | R | — | — | — | — |
| `finance.payouts.view` | R | F | R | — | — | — | — |
| `finance.reports.export` | F | F | R | — | — | — | — |

### Reviews & support

| Permission | Super Admin | Owner | Manager | Kitchen | Cashier | Order Op | Customer |
|------------|:-----------:|:-----:|:-------:|:-------:|:-------:|:--------:|:--------:|
| `reviews.moderate` | F | Own | Own | — | — | — | — |
| `reviews.create` | — | — | — | — | — | — | Own |
| `support.tickets.manage` | F | Own | Own | — | — | — | Own |
| `disputes.manage` | F | R | R | — | — | — | Own |

### Security constraints (hard rules)

1. Super admin **never** reads restaurant or customer passwords.
2. Super admin **never** impersonates restaurant owners without explicit audited break-glass (v1: disabled).
3. Restaurant users **never** access another `restaurant_id` — enforced by policies + global scopes.
4. Customers **never** mutate other customers’ carts, orders, or addresses.
5. Card PANs / CVV are **never** stored; only payment-provider tokens/IDs.
6. MFA required for `super_admin` (Phase 2).

---

## Default role → permission bundles

| Role | Bundle |
|------|--------|
| `super_admin` | All `platform.*`, `restaurants.*`, `orders.view_all_platform`, `orders.refund.manage`, `finance.platform.*`, `finance.settlements.manage`, `reviews.moderate`, `support.tickets.manage`, `disputes.manage` |
| `restaurant_owner` | All Own restaurant permissions |
| `restaurant_manager` | Profile, hours, menu, orders, reviews; staff write; finance read |
| `kitchen_staff` | `menu.availability.toggle`, kitchen status updates, order read |
| `cashier` | Order read (POS-oriented; Phase 7+) |
| `order_operator` | Accept/reject/status for restaurant orders |
| `customer` | Browse, cart, checkout, own orders, reviews, support |

Phase 0 prepares the RBAC tables only. Seeding and enforcement land in Phase 2.
