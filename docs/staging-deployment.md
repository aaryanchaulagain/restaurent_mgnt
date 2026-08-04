# Staging deployment guide (Phase 7B)

Isolated staging preparation for Khana / Suvakamana. **Not production.**

Checkpoint base: Phase 7A commit (`c9cd425` or later Phase 7B commit).

---

## Status legend

| Label | Meaning |
|-------|---------|
| **source-code complete** | Scripts/docs/checks exist in the repo |
| **locally validated** | Commands/tests ran on a developer machine |
| **staging infrastructure validated** | Ran against a real staging host/URL |
| **manually pending** | Requires human/infra action outside Cursor |

---

## Recommended staging topology

Smallest reliable topology matching this repository:

| Component | Recommendation |
|-----------|----------------|
| HTTPS reverse proxy | nginx/Caddy/CDN terminating TLS |
| Frontend | Next.js `npm run build` + `npm run start` (SSR/hybrid; Node 22) |
| Backend | Laravel on PHP 8.2+ (FPM preferred; Compose uses `artisan serve` for demos) |
| Database | **Separate** Postgres 16 or MySQL 8 (`suvakamana_staging`) |
| Redis | Dedicated DB index + `REDIS_PREFIX` / `CACHE_PREFIX` |
| Queue worker | `queue:work --queue=notifications,default` via Supervisor/systemd |
| Scheduler | Cron: `* * * * * php /path/to/backend/artisan schedule:run` |
| Media | Named volume or S3 **staging** bucket/prefix |
| Mail | Sandbox SMTP (Mailpit/Mailtrap/SES sandbox) |
| Payments | Stripe **test** keys + staging webhook URL only |

Suggested hostnames (placeholders):

- `https://staging.example.com`
- `https://api-staging.example.com`

---

## Environment isolation checklist

Staging **must** use separate:

- [ ] Domain / subdomain
- [ ] Database + credentials
- [ ] Redis DB index and/or prefix
- [ ] Cache prefix
- [ ] APP_KEY
- [ ] FRONTEND_URL / APP_URL / Sanctum domains
- [ ] Stripe **test** secret, publishable, webhook secret
- [ ] Mail transport / from address
- [ ] Object storage bucket or prefix

Templates (placeholders only):

- `backend/.env.staging.example`
- `frontend/.env.staging.example`

Never commit real staging `.env` files.

---

## Version pinning

| Runtime | Pin |
|---------|-----|
| PHP | `^8.2` (`composer.json`); Docker/CI use 8.2 |
| Composer | 2.x |
| Node | **22** (`.nvmrc`, `frontend/.nvmrc`, `frontend/.node-version`) |
| Lockfiles | `composer.lock`, `frontend/package-lock.json` |

PHP extensions: `pdo_pgsql` or `pdo_mysql`, `zip`, Redis client via predis (no php-redis required), mbstring/openssl/tokenizer/xml (Laravel defaults).

---

## Release identification

Set at deploy:

```bash
export APP_RELEASE_SHA="$(git rev-parse HEAD)"
# Frontend build:
export NEXT_PUBLIC_APP_RELEASE_SHA="${APP_RELEASE_SHA:0:7}"
```

Health responses expose `version` + short `release` only (no paths/credentials).

---

## Deploy scripts

```bash
chmod +x scripts/*.sh
export APP_RELEASE_SHA="$(git rev-parse HEAD)"
./scripts/deploy-staging.sh
./scripts/verify-staging.sh
# Optional HTTP checks:
STAGING_API_BASE=https://api-staging.example.com ./scripts/verify-staging.sh
```

Rollback (application only):

```bash
PREVIOUS_SHA=<known-good> ./scripts/rollback-staging.sh
./scripts/verify-staging.sh
```

Scripts refuse `APP_ENV=production` and `sk_live_` in backend `.env`.

Compose overlay (optional):

```bash
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --build
```

Supervisor/systemd are **not** installed by this repo — configure on the host.

---

## Backend release steps

1. Checkout exact Git commit  
2. `composer install --no-dev --optimize-autoloader`  
3. Load staging env from secrets manager  
4. `php artisan app:production-readiness --env=staging`  
5. Backup staging DB (+ media)  
6. Maintenance mode only if needed  
7. `php artisan migrate --force` (**never** `migrate:fresh`)  
8. `config:cache` / `route:cache` / `view:cache`  
9. `storage:link` + permissions  
10. `queue:restart`  
11. `php artisan up`  
12. Health + `./scripts/verify-staging.sh`

---

## Frontend release steps

1. Same Git commit  
2. `npm ci`  
3. Staging `NEXT_PUBLIC_*` only (test publishable key)  
4. `npm test -- --run` (or trust CI)  
5. `npm run build`  
6. Deploy immutable build / restart Node  
7. Confirm UI hits staging API  

---

## Seeding rules

```bash
# Opt-in only — refuses production
php artisan staging:seed-minimal --force
php artisan staging:seed-minimal --force --with-admin   # needs STAGING_ADMIN_*
```

Do **not** run `php artisan db:seed` on staging unless a staging-safe seeder is explicitly approved.  
Do not auto-create Suvakamana/demo partners.

---

## Queue validation

```bash
php artisan queue:operational-check
php artisan queue:staging-probe --wait=15
```

Probe job payload is a random token only (no PII/payments). Refuses production.

---

## Scheduler validation

```bash
php artisan schedule:list
```

Required: `orders:expire-unaccepted`, `payments:expire-pending`, `payments:reconcile`, `payments:retry-webhooks`, `inventory:release-expired-reservations`.

Cron (placeholder path):

```cron
* * * * * cd /var/www/suvakamana/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## Mail validation

```bash
php artisan mail:operational-check --to=approved-staging@example.com
```

Only approved staging addresses. Sandbox SMTP. No production lists.

Manual flows: invitation, verification, password reset, order notification rendering.

---

## Payment test-mode validation

- Keys: `sk_test_` / `pk_test_` / staging `whsec_` only  
- Readiness rejects mixed test/live and live keys in staging  
- Webhook: `https://api-staging.example.com/api/v1/webhooks/stripe`  
- Validate signature, idempotent duplicate, failure/retry, reservation lifecycle  
- **No live charges**

```bash
php artisan payments:operational-check
```

---

## Media persistence

- Persist `storage/app` via volume or object storage  
- Upload staging-safe logo/menu images  
- Confirm public vs private disk behaviour  
- Redeploy application code without wiping the volume  
- Include media in backup/restore drill  

---

## Post-release checks

```bash
curl -fsS "$STAGING_API_BASE/api/health/live"
curl -fsS "$STAGING_API_BASE/api/health/ready"
php artisan app:production-readiness --env=staging
php artisan app:smoke-test
# + integrity + queue/payments/schedule commands (see verify-staging.sh)
```

Classification: **blocking** (readiness FAIL / health down) · **warning** (integrity findings) · **info**.

---

## Staging user-flow checklist (manual)

### Customer
Register → verify via staging mailbox → browse → recommend branch → cart lock → quote → cash/test order → view order

### Partner
Owner login → create branch → invite manager → accept → branch-scoped catalogue/stock → process order

### Cross-branch
Manager A ≠ Branch B · cart cannot mix branches · sibling cannot see other orders

### Payment (test)
Test card → signed webhook → duplicate idempotent → failure/retry → reservation lifecycle

### Super-admin
View businesses/branches/reports · historical archived-partner order · no impersonation · no secrets in UI

---

## Migration rehearsal

Before: backup, readiness, integrity, row counts, `migrate:status`  
Apply: `php artisan migrate --force`  
After: status, counts, integrity, smoke  

Prefer **application rollback** without DB rollback for additive migrations.  
Test `migrate:rollback` only on an **isolated copy**.

Critical tables: users, businesses, branches, restaurants, menu items, carts, orders, payments, inventory reservations, settlements.

---

## Backup / restore rehearsal

1. Backup staging DB + media  
2. Restore into **separate** DB/storage namespace  
3. Non-prod secrets  
4. Readiness + integrity + count compare  
5. Destroy restore environment  

Never restore over active staging. Mark host steps **manually pending** until executed.

---

## Rollback rehearsal

1. Record current SHA  
2. `PREVIOUS_SHA=... ./scripts/rollback-staging.sh`  
3. Keep additive migrations  
4. Restart workers / caches  
5. Verify  
6. Redeploy current release  

Record outcomes in `docs/staging-deployment-report.md` (no credentials).

---

## Monitoring baseline (manual thresholds)

| Signal | Suggest alert |
|--------|----------------|
| Liveness fail | immediate |
| Readiness fail | immediate |
| HTTP 5xx | >1% / 5m |
| Failed jobs | >0 for 15m |
| Oldest queue job | >5m |
| Scheduler silence | >5m without cron |
| Unprocessed webhooks | >0 aging >15m |
| Disk / media | <15% free |

No paid monitoring provider in Phase 7B.

---

## CI/CD safety

- Backend: composer lock install, migrate, tests  
- Frontend: npm ci, tests, build  
- No automatic production deploy  
- Staging deploy requires human approval + secrets from platform vault  

---

## Infrastructure tasks Cursor cannot complete

- Provision DNS/TLS certificates  
- Create real staging VMs/DBs/Redis  
- Install Supervisor/systemd on a host  
- Register Stripe test webhook against a public URL  
- Run off-site backup jobs  
- Approve sandbox mailbox delivery  

These remain **manually pending** until ops executes them.
