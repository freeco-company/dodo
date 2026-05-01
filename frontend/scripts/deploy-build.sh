#!/usr/bin/env bash
# Pandora Meal frontend — production build + rsync.
#
# Pre-deploy minify pipeline:
#   - Tailwind compiled (--minify) → public/tailwind.css
#   - app.js → app.min.js (terser, drop_console=true)
#   - style.css → style.min.css (clean-css)
#
# Usage:
#   bash scripts/deploy-build.sh                # build only
#   DEPLOY_HOST=user@host DEPLOY_PATH=/srv/pandora-meal bash scripts/deploy-build.sh  # build + rsync
#
# TODO (user): fill DEPLOY_HOST + DEPLOY_PATH in CI / .env.deploy and remove this banner.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> [1/3] Building Tailwind…"
npm run build:tailwind

echo "==> [2/3] Minifying JS + CSS…"
npm run minify:js
npm run minify:css

echo "==> [3/3] Versioning bundle…"
SHA=$(git rev-parse --short HEAD 2>/dev/null || date +%s)
echo "    bundle sha: ${SHA}"

# Optional rsync — only runs when DEPLOY_HOST is set.
if [[ -n "${DEPLOY_HOST:-}" && -n "${DEPLOY_PATH:-}" ]]; then
  echo "==> Syncing to ${DEPLOY_HOST}:${DEPLOY_PATH}"
  rsync -avz --delete \
    --exclude='dev-smoke.html' \
    --exclude='*.map' \
    public/ "${DEPLOY_HOST}:${DEPLOY_PATH}/public/"
else
  echo "==> DEPLOY_HOST / DEPLOY_PATH unset — skipping rsync."
  echo "    Output ready under public/ (app.min.js, style.min.css, tailwind.css)."
fi

echo "==> Done."
