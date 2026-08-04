#!/usr/bin/env bash
# Post-deploy verification for staging. Classifies blocking vs warning.
# Optional: STAGING_API_BASE=https://api-staging.example.com
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="${BACKEND_DIR:-$ROOT_DIR/backend}"
API_BASE="${STAGING_API_BASE:-}"
BLOCKING=0
WARNINGS=0

cd "$BACKEND_DIR"

classify() {
  local name="$1"
  local code="$2"
  if [[ "$code" -eq 0 ]]; then
    echo "[PASS] $name"
  else
    echo "[WARN] $name (exit $code)"
    WARNINGS=$((WARNINGS + 1))
  fi
}

echo "==> Staging verify"

php artisan app:production-readiness --env=staging
ready_code=$?
if [[ "$ready_code" -ne 0 ]]; then
  echo "[BLOCK] app:production-readiness"
  BLOCKING=$((BLOCKING + 1))
else
  echo "[PASS] app:production-readiness"
fi

set +e
php artisan app:smoke-test
classify "app:smoke-test" $?

php artisan queue:operational-check
classify "queue:operational-check" $?

php artisan payments:operational-check
classify "payments:operational-check" $?

php artisan backup:readiness
classify "backup:readiness" $?

php artisan schedule:list >/tmp/staging-schedule.list
classify "schedule:list" $?
grep -q "orders:expire-unaccepted" /tmp/staging-schedule.list || { echo "[BLOCK] missing orders:expire-unaccepted"; BLOCKING=$((BLOCKING + 1)); }
grep -q "inventory:release-expired-reservations" /tmp/staging-schedule.list || { echo "[BLOCK] missing inventory:release-expired-reservations"; BLOCKING=$((BLOCKING + 1)); }

php artisan orders:tenant-integrity
classify "orders:tenant-integrity" $?

php artisan reporting:integrity
classify "reporting:integrity" $?

php artisan cart:branch-integrity
classify "cart:branch-integrity" $?

php artisan inventory:reservation-integrity
classify "inventory:reservation-integrity" $?

php artisan branch:location-integrity
classify "branch:location-integrity" $?
set -e

if [[ -n "$API_BASE" ]]; then
  set +e
  curl -fsS "$API_BASE/api/health/live" >/tmp/staging-live.json
  live=$?
  curl -fsS "$API_BASE/api/health/ready" >/tmp/staging-ready.json
  ready=$?
  set -e
  if [[ "$live" -ne 0 || "$ready" -ne 0 ]]; then
    echo "[BLOCK] health endpoints unreachable at STAGING_API_BASE"
    BLOCKING=$((BLOCKING + 1))
  else
    echo "[PASS] health live/ready"
    if grep -qiE 'password|secret|sk_live|whsec_|SQLSTATE|/var/www' /tmp/staging-live.json /tmp/staging-ready.json; then
      echo "[BLOCK] health response contains sensitive markers"
      BLOCKING=$((BLOCKING + 1))
    fi
  fi
else
  echo "[INFO] STAGING_API_BASE unset — skipped HTTP health curls (manual pending)"
fi

echo "==> Summary blocking=$BLOCKING warnings=$WARNINGS"
if [[ "$BLOCKING" -gt 0 ]]; then
  exit 1
fi
exit 0
