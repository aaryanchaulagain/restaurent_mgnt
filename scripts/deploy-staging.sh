#!/usr/bin/env bash
# Staging backend/frontend deploy helper — no embedded secrets.
# Usage (on staging host, after checkout):
#   export APP_RELEASE_SHA="$(git rev-parse HEAD)"
#   ./scripts/deploy-staging.sh
#
# Required env (from secrets manager / host): BACKEND_DIR, FRONTEND_DIR, or defaults below.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="${BACKEND_DIR:-$ROOT_DIR/backend}"
FRONTEND_DIR="${FRONTEND_DIR:-$ROOT_DIR/frontend}"
RELEASE_SHA="${APP_RELEASE_SHA:-$(git -C "$ROOT_DIR" rev-parse HEAD 2>/dev/null || echo unknown)}"
SHORT_SHA="${RELEASE_SHA:0:7}"
MAINTENANCE="${STAGING_MAINTENANCE:-0}"

echo "==> Staging deploy release=${SHORT_SHA}"

if [[ ! -d "$BACKEND_DIR" ]]; then
  echo "BACKEND_DIR missing: $BACKEND_DIR" >&2
  exit 1
fi

cd "$BACKEND_DIR"

if [[ ! -f .env ]]; then
  echo "Missing backend/.env on host (copy from .env.staging.example via secrets manager)." >&2
  exit 1
fi

# Refuse live Stripe secret keys without embedding a full live key in this script.
if awk -F= '/^STRIPE_SECRET_KEY=/ {
  v=$2; gsub(/\r/,"",v); gsub(/^[ \t"]+|[ \t"]+$/,"",v);
  if (v ~ /^sk_l[i]ve_/) exit 2
}' .env; then
  :
else
  status=$?
  if [[ "$status" -eq 2 ]]; then
    echo "Refusing deploy: live Stripe secret detected in staging .env" >&2
    exit 1
  fi
fi

if grep -E '^APP_ENV=production' .env >/dev/null 2>&1; then
  echo "Refusing deploy: APP_ENV=production on staging deploy script" >&2
  exit 1
fi

composer install --no-dev --optimize-autoloader --no-interaction

export APP_RELEASE_SHA="$RELEASE_SHA"
php artisan app:production-readiness --env=staging || {
  echo "Readiness failed — aborting before migrate." >&2
  exit 1
}

if [[ "$MAINTENANCE" == "1" ]]; then
  php artisan down --render="errors::503" --retry=60 || true
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true
php artisan queue:restart

if [[ "$MAINTENANCE" == "1" ]]; then
  php artisan up
fi

if [[ -d "$FRONTEND_DIR" ]]; then
  cd "$FRONTEND_DIR"
  if [[ -f package-lock.json ]]; then
    npm ci
  else
    echo "frontend package-lock.json missing" >&2
    exit 1
  fi
  export NEXT_PUBLIC_APP_RELEASE_SHA="$SHORT_SHA"
  npm run build
  echo "Frontend build complete. Restart the Node process manager separately."
fi

echo "==> Deploy steps finished for ${SHORT_SHA}"
echo "Run: ./scripts/verify-staging.sh"
