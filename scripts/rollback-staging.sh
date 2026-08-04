#!/usr/bin/env bash
# Application rollback helper for staging (keeps DB migrations as-is for additive changes).
# Usage:
#   PREVIOUS_SHA=<git-sha> ./scripts/rollback-staging.sh
# Does not run migrate:rollback. Does not touch production.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="${BACKEND_DIR:-$ROOT_DIR/backend}"
FRONTEND_DIR="${FRONTEND_DIR:-$ROOT_DIR/frontend}"
PREVIOUS_SHA="${PREVIOUS_SHA:-}"

if [[ -z "$PREVIOUS_SHA" ]]; then
  echo "Set PREVIOUS_SHA to the known-good Git commit." >&2
  exit 1
fi

echo "==> Staging application rollback to ${PREVIOUS_SHA:0:7}"
echo "This checks out application code only. Database schema is NOT rolled back."

cd "$ROOT_DIR"
git fetch --all --tags || true
git checkout "$PREVIOUS_SHA"

cd "$BACKEND_DIR"
composer install --no-dev --optimize-autoloader --no-interaction
export APP_RELEASE_SHA="$PREVIOUS_SHA"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

if [[ -d "$FRONTEND_DIR" ]]; then
  cd "$FRONTEND_DIR"
  npm ci
  export NEXT_PUBLIC_APP_RELEASE_SHA="${PREVIOUS_SHA:0:7}"
  npm run build
  echo "Restart frontend process manager to load the previous build."
fi

echo "==> Rollback code deployed. Run ./scripts/verify-staging.sh"
echo "To return to current release, checkout the newer SHA and re-run deploy-staging.sh"
