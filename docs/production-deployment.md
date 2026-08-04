# Production deployment & operations runbook (Phase 7A)

This document hardens **deployment and recovery** for the existing Khana / Suvakamana stack.
It does **not** change marketplace business behaviour.

**Checkpoint:** use a known-good commit (e.g. after Phase 6B / this Phase 7A work) before any production change.

---

## 1. Intended production topology

| Tier | Role |
|------|------|
| Frontend | Next.js (Node) behind HTTPS |
| Backend | Laravel API (PHP-FPM / Octane / container) behind HTTPS |
| Database | MySQL 8+ or PostgreSQL 14+ (managed preferred) |
| Cache / queue | Redis (recommended) |
| Object storage | S3-compatible for media that must survive redeploys |
| Mail | Transactional provider (SMTP/API) with SPF/DKIM/DMARC |
| Workers | `queue:work` process manager (Supervisor/systemd — **not** installed by this repo) |
| Scheduler | OS cron calling `php artisan schedule:run` every minute |
| Payments | Stripe (secrets on backend only); webhook HTTPS endpoint |

DNS: separate or path-based hosts for web + API; TLS certificates required.

---

## 2. Required environment variables

See `backend/.env.example` and `frontend/.env.example`.

**Never** place secrets in `NEXT_PUBLIC_*`.

Production must set at least:

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `APP_URL` (https)
- `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`
- Database credentials
- `QUEUE_CONNECTION` ≠ `sync`
- Real `MAIL_*`
- `SESSION_SECURE_COOKIE=true`
- `TRUSTED_PROXIES` when behind a load balancer / CDN
- Stripe keys + `STRIPE_WEBHOOK_SECRET` when card payments are enabled

Validate:

```bash
cd backend
php artisan app:production-readiness
```

Exit code `1` = critical failures. Secrets are never printed.

---

## 3. DNS and HTTPS prerequisites

1. Point frontend and API hostnames to the load balancer / origin.
2. Terminate TLS (or terminate at the app — prefer edge TLS).
3. Configure `TRUSTED_PROXIES` so Laravel sees client IP and HTTPS correctly.
4. Ensure Stripe webhook URL is publicly reachable over HTTPS.

---

## 4. Backend deployment steps (happy path)

```bash
# On the application host (paths are placeholders)
cd /var/www/suvakamana/backend

# 1) Backup database + media first (see Backup)
# 2) Optional maintenance
php artisan down --render="errors::503" --retry=60

# 3) Deploy code (git pull / artifact) to a new release directory
# 4) composer install --no-dev --optimize-autoloader
# 5) Preflight
php artisan app:production-readiness
php artisan migrate:status

# 6) Migrate only after backup approval
php artisan migrate --force

# 7) Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link   # if missing

# 8) Restart workers so they load new code
php artisan queue:restart

# 9) Bring up
php artisan up

# 10) Verify
curl -fsS https://api.example.com/api/health/live
curl -fsS https://api.example.com/api/health/ready
php artisan app:smoke-test
```

**Never** run `migrate:fresh` in production.

---

## 5. Frontend deployment steps

```bash
cd /var/www/suvakamana/frontend
# Set env from secrets manager (not committed files)
npm ci
npm run build
# Restart Node process / swap immutable build artifact
```

Confirm `NEXT_PUBLIC_API_URL` points at the production API and only publishable Stripe keys are public.

---

## 6. Database backup

**Before every migration or risky deploy.**

MySQL (credentials via defaults-extra-file, not CLI args):

```bash
mysqldump --defaults-extra-file=/root/.my.cnf --single-transaction --routines --triggers DB_NAME > backup.sql
```

PostgreSQL:

```bash
pg_dump -Fc DB_NAME > backup.dump
```

Encrypt and store off-site. Retention: define with ops (suggested ≥ 14 daily + weekly).

Verify readiness (does not dump):

```bash
php artisan backup:readiness
```

---

## 7. Media / file backup

Back up:

- `storage/app` (private partner documents, etc.)
- Public media / S3 bucket objects

Private onboarding documents must stay on a **private** disk — never rely on public URLs for them.

---

## 8. Maintenance mode decision

Use `php artisan down` for migrations that lock tables or for multi-step releases.
Skip maintenance for pure static frontend deploys when API is compatible.

---

## 9. Migration preflight checklist

- [ ] Database backup completed and verified size/checksum
- [ ] Media backup completed (if schema touches files)
- [ ] `php artisan migrate:status` reviewed
- [ ] Review SQL when supported: `php artisan migrate --pretend`
- [ ] App version / git SHA recorded
- [ ] Rollback / restore plan identified
- [ ] Payment/webhook reconciliation owner available

---

## 10. Queue workers

Recommended (adapt queue names if you customise):

```bash
php artisan queue:work --queue=notifications,default --sleep=3 --tries=3 --timeout=120
```

Check:

```bash
php artisan queue:operational-check
```

Failed jobs: inspect via `php artisan queue:failed` — **do not** dump payloads containing tokens into tickets.

Order placement and inventory reservation remain **synchronous** — do not move them to queues.

---

## 11. Scheduler (cron)

```cron
* * * * * cd /var/www/suvakamana/backend && php artisan schedule:run >> /dev/null 2>&1
```

Required scheduled commands:

- `orders:expire-unaccepted` (every minute)
- `payments:expire-pending` (every minute)
- `payments:reconcile` (every fifteen minutes)
- `payments:retry-webhooks` (every five minutes)
- `inventory:release-expired-reservations` (every minute)

Verify: `php artisan schedule:list`

---

## 12. Webhooks

1. Stripe Dashboard → endpoint `https://api.example.com/api/v1/webhooks/stripe`
2. Set `STRIPE_WEBHOOK_SECRET`
3. Signature verification + idempotent event processing (existing)
4. Monitor: `php artisan payments:operational-check`
5. Retry failed: `php artisan payments:retry-webhooks` (scheduled)

Do **not** rate-limit webhooks in a way that rejects valid signed retries.

---

## 13. Health checks

| Endpoint | Purpose |
|----------|---------|
| `GET /api/health/live` | Process up (no dependencies) |
| `GET /api/health/ready` | DB + cache + storage |
| `GET /up` | Laravel framework health |

Public responses are minimal (no credentials, hosts, or stack traces). Rate limited.

---

## 14. Smoke tests (non-destructive)

```bash
php artisan app:smoke-test
```

Must not create orders, payments, emails, or inventory changes.

Operational integrity (read-only):

```bash
php artisan orders:tenant-integrity
php artisan reporting:integrity
php artisan cart:branch-integrity
php artisan inventory:reservation-integrity
php artisan branch:location-integrity
```

---

## 15. Mail

```bash
php artisan mail:operational-check
php artisan mail:operational-check --to=ops-approved@example.com
```

Only send to an approved address. Configure SPF/DKIM/DMARC on the sending domain.

---

## 16. Rollback procedures

### Application rollback

1. Deploy previous known-good release/commit.
2. `php artisan queue:restart`
3. Rebuild caches: `config:cache`, `route:cache`, `view:cache`
4. Verify `/api/health/live` and `/api/health/ready`

Application rollback **≠** database rollback.

### Migration rollback

Only when the specific migration’s `down()` is confirmed safe and no irreversible financial writes depend on the new schema.
Never blindly `migrate:rollback` after live payments.

### Data restore

1. Create an **isolated** restore environment.
2. Restore DB + media.
3. Use non-production secrets.
4. Run integrity + smoke commands.
5. Reconcile Stripe events carefully — avoid double-charging or incorrect replays.
6. Destroy the isolated environment after the drill.

**Never** test restore by overwriting live production.

### Frontend rollback

Deploy previous build artifact; verify API compatibility.

---

## 17. Restore drill (summary)

1. Isolated environment  
2. Restore database  
3. Restore media  
4. Non-prod secrets  
5. Migrations only if appropriate  
6. Integrity commands  
7. Smoke tests  
8. Verify critical counts/records  
9. Tear down safely  

---

## 18. Logging and retention

- Sensitive fields redacted via `SensitiveDataRedactor` / audit scrubbing.
- Do not log full bodies for login, registration, password reset, invitation accept, payment methods, webhooks.
- Rotate logs (suggested 14–30 days); ship to a central store if available.
- Correlation: `X-Request-Id` response header + `request_id` on API error envelopes.

---

## 19. Security headers / CORS / cookies

- Frontend sends baseline headers + **CSP Report-Only** (tighten after verifying Stripe/images).
- CORS allowlist = `FRONTEND_URL` only in production; credentials enabled; **no** wildcard origins.
- `SESSION_SECURE_COOKIE=true` in production HTTPS.

---

## 20. Post-deployment monitoring

- Health ready/live probes
- Queue failed job count
- Unprocessed webhook count
- Scheduler heartbeat (cron actually running)
- Error rate / 5xx
- Mail bounce rate
- Disk / object storage capacity

### Incident contacts (placeholders)

- On-call engineer: _TBD_
- Payments owner: _TBD_
- Infra / DB: _TBD_

---

## 21. What Phase 7A does **not** do

- Provision cloud infrastructure
- Activate Stripe live mode for you
- Install Supervisor/systemd
- Deploy to production
- Automatic refunds / settlement redesign
- Exports, forecasting, impersonation, or new marketplace features
