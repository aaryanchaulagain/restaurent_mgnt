# Staging deployment report (Phase 7B)

Record outcomes here after each staging rehearsal. **Do not** paste credentials, private URLs with tokens, or customer PII.

**Report date:** _YYYY-MM-DD_  
**Git commit rehearsed:** _short SHA_  
**Operator:** _name_

---

## Status summary

| Area | Status |
|------|--------|
| Source-code preparation | complete |
| Local validation | complete (see CI/local test results) |
| Staging infrastructure deployment | manually pending |
| Database migration rehearsal | manually pending |
| Queue validation | source-code complete; worker on host manually pending |
| Scheduler validation | source-code complete; cron on host manually pending |
| Mail validation | manually pending (sandbox) |
| Payment test-mode validation | source-code checks complete; Stripe Dashboard webhook manually pending |
| Webhook validation | manually pending |
| Persistent storage validation | compose volume prepared; host drill manually pending |
| Rollback rehearsal | script ready; host drill manually pending |
| Restore rehearsal | documented; host drill manually pending |

---

## Architecture (intended)

- Frontend: Next.js 22 SSR/hybrid behind HTTPS  
- Backend: Laravel PHP 8.2 API  
- Database: isolated staging Postgres/MySQL  
- Cache/queue: Redis with staging prefix/DB  
- Scheduler: host cron `schedule:run`  
- Mail: sandbox SMTP  
- Storage: persistent volume / staging bucket  
- Payments: Stripe test mode + staging webhook  

Actual hostnames used: _fill after provision — public staging hosts only_

---

## Deployment

| Item | Notes |
|------|-------|
| Scripts | `scripts/deploy-staging.sh`, `verify-staging.sh`, `rollback-staging.sh` |
| Release ID | `APP_RELEASE_SHA` / health `release` |
| Health | `/api/health/live`, `/api/health/ready` |
| Smoke | `app:smoke-test` + integrity suite |

### Migration rehearsal log

| Step | Result |
|------|--------|
| Pre-backup | pending |
| Pre-counts | pending |
| `migrate --force` | pending |
| Post-counts match | pending |
| Integrity | pending |

### Rollback rehearsal log

| Step | Result |
|------|--------|
| Previous SHA recorded | pending |
| Application rollback | pending |
| Health/smoke after rollback | pending |
| Redeploy current | pending |

### Restore rehearsal log

| Step | Result |
|------|--------|
| Backup taken | pending |
| Isolated restore | pending |
| Counts/integrity | pending |
| Environment destroyed | pending |

---

## Security notes

- Staging env templates use placeholders only  
- Live Stripe keys rejected by readiness/scripts  
- No production data import  
- CORS/cookies/HTTPS required on real staging hosts  

---

## Unverified manual steps

1. Provision staging DNS + TLS  
2. Create staging DB/Redis/mail/storage  
3. Install queue worker + cron  
4. Configure Stripe test webhook endpoint  
5. Execute migration/backup/restore/rollback on the host  
6. Run customer/partner/payment manual flows  

---

## Sign-off

Staging is **not** production-ready until migration, queue, scheduler, mail, webhook, storage, rollback, and restore are demonstrated **outside** production and recorded above.
