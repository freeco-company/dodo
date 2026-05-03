#!/usr/bin/env bash
# =============================================================================
# Dodo deploy script (run from local machine).
#
# Pushes dodo/backend, dodo/ai-service, dodo/frontend to Linode via rsync,
# then runs the post-deploy commands over SSH.
#
# Usage:
#   bash deploy/scripts/deploy-dodo.sh                    # full deploy
#   bash deploy/scripts/deploy-dodo.sh --dry-run          # print rsync/ssh, no execute
#   bash deploy/scripts/deploy-dodo.sh --only backend     # backend only
#   bash deploy/scripts/deploy-dodo.sh --only ai-service  # ai-service only
#   bash deploy/scripts/deploy-dodo.sh --only frontend    # frontend only
#   bash deploy/scripts/deploy-dodo.sh --skip-migrate     # skip artisan migrate
# =============================================================================

set -euo pipefail

# --- Config (override via env) -----------------------------------------------
SSH_HOST="${SSH_HOST:-root@139.162.121.187}"
REMOTE_BASE="${REMOTE_BASE:-/var/www/pandora-meal}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOCAL_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
QUEUE_SVC="${QUEUE_SVC:-pandora-meal-backend-queue.service}"
AI_SVC="${AI_SVC:-pandora-meal-ai-service.service}"

DRY_RUN=0
ONLY=""
SKIP_MIGRATE=0

# --- Args --------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)      DRY_RUN=1; shift ;;
        --only)         ONLY="$2"; shift 2 ;;
        --skip-migrate) SKIP_MIGRATE=1; shift ;;
        -h|--help)
            sed -n '3,18p' "$0"; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
done

run() {
    if [[ $DRY_RUN -eq 1 ]]; then
        printf '[dry-run] %s\n' "$*"
    else
        printf '\n>>> %s\n' "$*"
        eval "$@"
    fi
}

ssh_run() {
    local cmd="$1"
    if [[ $DRY_RUN -eq 1 ]]; then
        printf '[dry-run] ssh %s "%s"\n' "$SSH_HOST" "$cmd"
    else
        printf '\n>>> ssh %s\n    %s\n' "$SSH_HOST" "$cmd"
        ssh "$SSH_HOST" "$cmd"
    fi
}

# Common rsync flags. --delete keeps remote in sync with local; excludes are
# critical to avoid clobbering vendor/.env/storage.
RSYNC_FLAGS=(-avz --delete --human-readable --info=stats1,progress2)
EXCLUDES_BACKEND=(
    --exclude='vendor'
    --exclude='node_modules'
    --exclude='.env'
    --exclude='.env.local'
    --exclude='storage/logs/*'
    --exclude='storage/framework/cache/*'
    --exclude='storage/framework/sessions/*'
    --exclude='storage/framework/views/*'
    --exclude='storage/app/public/*'   # never overwrite uploaded user files
    --exclude='.phpunit.result.cache'
    --exclude='.git'
    --exclude='tests'                  # don't ship tests to prod
)
EXCLUDES_AI=(
    --exclude='.venv'
    --exclude='__pycache__'
    --exclude='.env'
    --exclude='.pytest_cache'
    --exclude='.mypy_cache'
    --exclude='tests'
    --exclude='.git'
)
EXCLUDES_FRONT=(
    --exclude='node_modules'
    --exclude='ios/App/App/public'     # built into native shell only
    --exclude='.git'
)

deploy_backend() {
    echo "=== Deploying dodo-backend ==="
    run "rsync ${RSYNC_FLAGS[*]} ${EXCLUDES_BACKEND[*]} \
        '$LOCAL_ROOT/backend/' '$SSH_HOST:$REMOTE_BASE/backend/'"

    local migrate_cmd=""
    if [[ $SKIP_MIGRATE -eq 0 ]]; then
        migrate_cmd="$PHP_BIN artisan migrate --force && "
    fi

    ssh_run "set -e; \
        cd $REMOTE_BASE/backend && \
        $PHP_BIN artisan down --refresh=15 --retry=60 || true; \
        composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist && \
        rm -f bootstrap/cache/packages.php bootstrap/cache/services.php && \
        $PHP_BIN artisan package:discover --ansi && \
        $migrate_cmd \
        $PHP_BIN artisan config:cache && \
        $PHP_BIN artisan route:cache && \
        $PHP_BIN artisan view:cache && \
        $PHP_BIN artisan event:cache && \
        $PHP_BIN artisan storage:link || true; \
        chown -R www-data:www-data storage bootstrap/cache && \
        $PHP_BIN artisan queue:restart && \
        systemctl reload php8.3-fpm && \
        systemctl restart $QUEUE_SVC && \
        $PHP_BIN artisan up && \
        echo 'backend deployed'"
}

deploy_ai_service() {
    echo "=== Deploying dodo-ai-service ==="
    run "rsync ${RSYNC_FLAGS[*]} ${EXCLUDES_AI[*]} \
        '$LOCAL_ROOT/ai-service/' '$SSH_HOST:$REMOTE_BASE/ai-service/'"

    ssh_run "set -e; \
        cd $REMOTE_BASE/ai-service && \
        systemctl restart $AI_SVC && \
        sleep 2 && \
        curl -fsS http://127.0.0.1:8002/healthz && echo && \
        echo 'ai-service deployed'"
}

deploy_frontend() {
    echo "=== Deploying dodo-frontend (static) ==="
    run "rsync ${RSYNC_FLAGS[*]} ${EXCLUDES_FRONT[*]} \
        '$LOCAL_ROOT/frontend/public/' '$SSH_HOST:$REMOTE_BASE/frontend/public/'"
    ssh_run "echo 'frontend synced (no service to restart; nginx serves static)'"
}

# --- Pre-flight --------------------------------------------------------------
echo "Local root:    $LOCAL_ROOT"
echo "Remote target: $SSH_HOST:$REMOTE_BASE"
echo "Dry run:       $([[ $DRY_RUN -eq 1 ]] && echo yes || echo no)"
echo "Only:          ${ONLY:-all}"
echo

if [[ ! -d "$LOCAL_ROOT/backend" ]]; then
    echo "ERROR: $LOCAL_ROOT/backend does not exist. Are you in the dodo monorepo?" >&2
    exit 1
fi

# --- Run ---------------------------------------------------------------------
case "$ONLY" in
    backend)    deploy_backend ;;
    ai-service) deploy_ai_service ;;
    frontend)   deploy_frontend ;;
    "")         deploy_backend; deploy_ai_service; deploy_frontend ;;
    *) echo "Unknown --only target: $ONLY" >&2; exit 2 ;;
esac

echo
echo "=== Deploy complete ==="
echo "Smoke checks:"
echo "  curl https://dodo.js-store.com.tw/api/health"
echo "  curl https://app.dodo.js-store.com.tw/"
echo "  ssh $SSH_HOST 'curl -fsS http://127.0.0.1:8002/healthz'"
