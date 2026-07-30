# Stripe local webhook testing

Use the Stripe CLI to forward events to the local API while developing Phase 6 payments.

## 1. Install and log in

```bash
stripe login
```

## 2. Forward webhooks

Point the CLI at the Laravel webhook endpoint (CSRF is excluded for this path):

```bash
stripe listen --forward-to http://127.0.0.1:8000/api/v1/webhooks/stripe
```

Copy the printed webhook signing secret into your local `.env` (never commit it):

```env
STRIPE_WEBHOOK_SECRET=whsec_...
```

Also set test API keys:

```env
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
```

## 3. Trigger useful events

With `stripe listen` running in another terminal:

```bash
stripe trigger payment_intent.succeeded
stripe trigger payment_intent.payment_failed
stripe trigger charge.refunded
stripe trigger charge.dispute.created
stripe trigger account.updated
```

For PaymentIntents tied to a real order, complete checkout in the app (or Dashboard) so the PaymentIntent ID matches a local `payments.external_payment_intent_id` row.

## 4. Verify locally

```bash
cd backend
php artisan route:list --path=webhooks/stripe
php artisan payments:retry-webhooks
php artisan payments:reconcile
php artisan payments:expire-pending
```

Check `payment_webhook_events` for `processing_status` (`processed`, `ignored`, or `failed`) and related payment/order IDs.

## Notes

- Do not put CLI webhook secrets in Git.
- Do not log full signed payloads or `client_secret` values.
- Webhooks are the source of truth for paid / refunded final states.
