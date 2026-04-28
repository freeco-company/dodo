# Dodo Deployment — Linode Same-Box Layout

> Operator handbook for deploying dodo (backend + ai-service + frontend) onto
> the existing Linode 4GB box (`139.162.121.187`) that already runs Pandora
> Core Identity (`id.js-store.com.tw`), the mothership Laravel, and the
> WordPress staging site.
>
> **Per ADR-007 §2.5**: stay on Linode until the upgrade triggers (§5) hit.
> Do **not** introduce Docker / Kubernetes / managed services in this iteration.

---

## Architecture (six services, one box)

```
                            ┌─────────────────────────────────────────┐
                            │          Linode 4GB · same box          │
                            │                                          │
   internet ──┬─ :443 ──▶ nginx                                          │
              │            ├─ id.js-store.com.tw          ──▶ platform Laravel (PHP-FPM)
              │            ├─ js-store.com.tw             ──▶ mothership Laravel (PHP-FPM)
              │            ├─ pandorasdo.freeco.cc        ──▶ WordPress
              │            ├─ dodo.js-store.com.tw  ★new  ──▶ dodo-backend (PHP-FPM)
              │            └─ app.dodo.js-store.com.tw ★new ─▶ static /var/www/dodo/frontend/public
              │
              │    (loopback only, no public ingress)
              │   127.0.0.1:8002  ──▶ dodo-ai-service ★new (uvicorn, systemd)
              │   127.0.0.1:8003  ──▶ py-service ★new       (uvicorn, systemd)
              │
              └── shared services
                  ├─ MariaDB 10.11/12  (mothership / platform / dodo / wp schemas)
                  ├─ Redis 7           (DB indexes 0=mothership 1=platform 2=dodo 3=dodo-queue)
                  └─ PostgreSQL ★new   (only py-service)
```

### Why same-box?

- < 10 real customers across mothership + platform today (HANDOFF §B Step F).
- ADR-007 §2.5 explicitly defers cloud migration until: DAU > 1,000 / RPS p95 > 30 /
  RAM > 80% for 3 days / paid members > 100 (see [§Upgrade triggers](#upgrade-triggers)).
- New cost: ~NT$0/month (same Linode plan handles +3 services).

### Process boundaries

| Concern              | Owner                                                          |
| -------------------- | -------------------------------------------------------------- |
| Crash isolation      | systemd `Restart=`, separate users (`www-data` / `dodo`)        |
| Port collisions      | Each service owns one loopback port (8001/8002/8003); only :80/:443 are public |
| DB blast radius      | Dedicated MariaDB schema + user per service; `GRANT` scope-limited |
| Memory pressure      | uvicorn `--workers 2` per Python service. Drop to 1 if RAM > 80% |

---

## DNS records the operator must add

Configure at your DNS provider (Cloudflare / Linode DNS / wherever
`js-store.com.tw` is hosted):

| Record               | Type | Value             | TTL  |
| -------------------- | ---- | ----------------- | ---- |
| `dodo.js-store.com.tw`     | A    | `139.162.121.187` | 300  |
| `app.dodo.js-store.com.tw` | A    | `139.162.121.187` | 300  |

`conv.js-store.com.tw` (py-service public) is **not** required — py-service
stays loopback by default.

After DNS propagates (`dig +short dodo.js-store.com.tw` returns the right IP),
proceed with cert issuance.

---

## Step 1 — Bootstrap the host (one-time)

SSH in and prepare directories + dedicated user.

```bash
ssh root@139.162.121.187

# Filesystem layout
mkdir -p /var/www/dodo/{backend,ai-service,frontend/public,secrets}
mkdir -p /var/log/dodo
mkdir -p /var/www/certbot         # webroot for Let's Encrypt HTTP-01

# Dedicated unprivileged user for Python services
useradd -r -m -d /var/lib/dodo -s /usr/sbin/nologin dodo
chown -R dodo:dodo /var/log/dodo /var/lib/dodo /var/www/dodo/ai-service
chown -R www-data:www-data /var/www/dodo/backend
chmod 750 /var/www/dodo/secrets

# Install runtime deps if not already present
apt-get update
apt-get install -y \
    php8.3-fpm php8.3-mysql php8.3-redis php8.3-curl php8.3-mbstring \
    php8.3-xml php8.3-bcmath php8.3-gd php8.3-intl \
    redis-server \
    postgresql postgresql-client \
    certbot

# uv for Python (already installed for platform; verify)
uv --version || curl -LsSf https://astral.sh/uv/install.sh | sh
```

Create MariaDB schema + user:

```sql
CREATE DATABASE dodo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dodo'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PW';
GRANT ALL PRIVILEGES ON dodo.* TO 'dodo'@'localhost';
FLUSH PRIVILEGES;
```

Create Postgres schema + user (py-service):

```bash
sudo -u postgres psql <<'EOF'
CREATE USER pyservice WITH PASSWORD 'CHANGE_ME_STRONG_PW';
CREATE DATABASE pandora_conversion OWNER pyservice;
EOF
```

---

## Step 2 — nginx + TLS

```bash
# From your local machine, in the dodo repo:
scp deploy/nginx/dodo-backend.conf      root@139.162.121.187:/etc/nginx/sites-available/
scp deploy/nginx/dodo-frontend.conf     root@139.162.121.187:/etc/nginx/sites-available/
scp deploy/nginx/dodo-ai-service.conf   root@139.162.121.187:/etc/nginx/sites-available/
scp deploy/nginx/py-service.conf        root@139.162.121.187:/etc/nginx/sites-available/

ssh root@139.162.121.187 <<'EOF'
# Issue certs (HTTP-01 webroot, same pattern as id.js-store.com.tw)
certbot certonly --webroot -w /var/www/certbot \
    -d dodo.js-store.com.tw \
    -d app.dodo.js-store.com.tw \
    --non-interactive --agree-tos -m ops@js-store.com.tw

# Enable the public vhosts
ln -sf ../sites-available/dodo-backend.conf  /etc/nginx/sites-enabled/
ln -sf ../sites-available/dodo-frontend.conf /etc/nginx/sites-enabled/
# dodo-ai-service.conf and py-service.conf default to loopback-only listeners;
# enabling them is OPTIONAL (only if you want curl localhost:8082/healthz):
# ln -sf ../sites-available/dodo-ai-service.conf /etc/nginx/sites-enabled/
# ln -sf ../sites-available/py-service.conf      /etc/nginx/sites-enabled/

nginx -t && systemctl reload nginx
EOF
```

Certbot's auto-renew cron (`/etc/cron.d/certbot`) is already installed by the
Debian package; verify with `systemctl list-timers | grep certbot`.

---

## Step 3 — systemd units

```bash
scp deploy/systemd/*.service deploy/systemd/*.timer \
    root@139.162.121.187:/etc/systemd/system/

ssh root@139.162.121.187 <<'EOF'
systemctl daemon-reload
systemctl enable --now dodo-backend-queue.service
systemctl enable --now dodo-backend-schedule.timer
systemctl enable --now dodo-ai-service.service
# py-service: enable AFTER you've cloned the py-service repo to /var/www/py-service
# systemctl enable --now py-service.service

systemctl status dodo-backend-queue.service --no-pager
systemctl status dodo-ai-service.service --no-pager
systemctl list-timers | grep dodo
EOF
```

---

## Step 4 — env files

On the Linode box (NOT in git):

```bash
ssh root@139.162.121.187

# Backend
cp /tmp/dodo-backend.env.example /var/www/dodo/backend/.env   # uploaded via scp
chown www-data:www-data /var/www/dodo/backend/.env
chmod 640 /var/www/dodo/backend/.env
$EDITOR /var/www/dodo/backend/.env       # fill REQUIRED keys

# Generate APP_KEY in place
sudo -u www-data php /var/www/dodo/backend/artisan key:generate --force

# AI service
cp /tmp/dodo-ai-service.env.example /var/www/dodo/ai-service/.env
chown dodo:dodo /var/www/dodo/ai-service/.env
chmod 640 /var/www/dodo/ai-service/.env
$EDITOR /var/www/dodo/ai-service/.env

# py-service (when ready)
cp /tmp/py-service.env.example /var/www/py-service/.env
chown dodo:dodo /var/www/py-service/.env
chmod 640 /var/www/py-service/.env
```

### REQUIRED keys (must be real before production traffic)

| File                | Keys                                                                                                                     |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `dodo-backend.env`  | `APP_KEY`, `DB_PASSWORD`, `DODO_ADMIN_TOKEN`, `PANDORA_CORE_WEBHOOK_SECRET`, `DODO_AI_SERVICE_SHARED_SECRET`, `PANDORA_CONVERSION_SHARED_SECRET` |
| `dodo-backend.env`  | (for real billing) `IAP_APPLE_SHARED_SECRET`, `IAP_GOOGLE_SERVICE_ACCOUNT_JSON`, `ECPAY_MERCHANT_ID/HASH_KEY/HASH_IV`     |
| `dodo-ai-service.env` | `INTERNAL_SHARED_SECRET` (must match backend `DODO_AI_SERVICE_SHARED_SECRET`), `LARAVEL_INTERNAL_SHARED_SECRET`        |
| `py-service.env`    | `DATABASE_URL`, `INTERNAL_SHARED_SECRET`, `MOTHERSHIP_BASE_URL`, `MOTHERSHIP_INTERNAL_SECRET`                            |

### STUB-OK keys (can stay blank for first deploy)

`ANTHROPIC_API_KEY`, `POSTHOG_API_KEY`, `FCM_SERVICE_ACCOUNT_JSON`, mail
provider keys, `IAP_STUB_MODE=true`, `FCM_DRY_RUN=true`. The app degrades to
fake/no-op behaviour — useful for cap-sync smoke tests before go-live.

---

## Step 5 — first deploy

From your local machine:

```bash
# Optional: dry-run first to see exactly what will happen
bash deploy/scripts/deploy-dodo.sh --dry-run

# Real deploy
bash deploy/scripts/deploy-dodo.sh
```

The script will, in order:

1. `rsync` `backend/` → `/var/www/dodo/backend/` (excluding vendor / .env / storage)
2. SSH and run: `artisan down` → `composer install --no-dev` → `artisan migrate --force` → cache configs → `queue:restart` → `php8.3-fpm reload` → `dodo-backend-queue` restart → `artisan up`
3. `rsync` `ai-service/` → `/var/www/dodo/ai-service/`
4. SSH and run: `uv sync --frozen` → `systemctl restart dodo-ai-service` → `curl /healthz`
5. `rsync` `frontend/public/` → `/var/www/dodo/frontend/public/` (nginx auto-serves; nothing to restart)

---

## Step 6 — smoke tests

```bash
# From anywhere on the public internet
curl -fsS https://dodo.js-store.com.tw/api/health        # expect {"status":"ok",...}
curl -fsS https://app.dodo.js-store.com.tw/ | head        # expect <!doctype html>...
curl -fsS https://id.js-store.com.tw/api/v1/auth/public-key | head    # platform sanity

# From the Linode box
ssh root@139.162.121.187 'curl -fsS http://127.0.0.1:8002/healthz'    # ai-service
ssh root@139.162.121.187 'curl -fsS http://127.0.0.1:8003/healthz'    # py-service (after deployed)
```

Open Filament admin in browser:

```
https://dodo.js-store.com.tw/admin/funnel
```

(Login with the admin user created via tinker / seeder.)

---

## Step 7 — logs

| Service          | Path                                          |
| ---------------- | --------------------------------------------- |
| dodo-backend (Laravel) | `/var/www/dodo/backend/storage/logs/laravel-YYYY-MM-DD.log` |
| dodo-backend queue worker | `/var/log/dodo/backend-queue.log`           |
| dodo-backend scheduler | `/var/log/dodo/backend-schedule.log`         |
| dodo-ai-service  | `/var/log/dodo/ai-service.log`                |
| py-service       | `/var/log/dodo/py-service.log`                |
| nginx (per vhost) | `/var/log/nginx/dodo-*.{access,error}.log`   |
| PHP-FPM          | `/var/log/php8.3-fpm.log`                     |
| systemd journal  | `journalctl -u dodo-ai-service -n 200 -f`    |

Tail everything in one go (operator helper):

```bash
ssh root@139.162.121.187 \
    "tail -F /var/log/dodo/*.log /var/log/nginx/dodo-*.error.log"
```

---

## Step 8 — healthcheck cron (optional but recommended)

```bash
ssh root@139.162.121.187 <<'EOF'
cp /tmp/healthcheck.sh /usr/local/bin/dodo-healthcheck
chmod +x /usr/local/bin/dodo-healthcheck
mkdir -p /etc/dodo
cat > /etc/dodo/healthcheck.env <<'ENV'
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/REPLACE/ME
ENV
chmod 600 /etc/dodo/healthcheck.env

# Cron: every 5 minutes
( crontab -l 2>/dev/null; echo "*/5 * * * * /usr/local/bin/dodo-healthcheck >>/var/log/dodo/healthcheck.log 2>&1" ) | crontab -
EOF
```

---

## Backup

The platform deploy already runs daily `mysqldump` (HANDOFF §B). Extend it to
include the `dodo` schema:

```bash
# /etc/cron.daily/db-backup (already exists for mothership + platform).
# Add `dodo` to the dump targets:
mysqldump --single-transaction --routines dodo | gzip > /var/backups/db/dodo-$(date +%F).sql.gz
```

For Postgres / py-service:

```bash
# /etc/cron.daily/pg-backup (new)
sudo -u postgres pg_dump pandora_conversion | gzip > /var/backups/db/pandora_conversion-$(date +%F).sql.gz
find /var/backups/db -name 'pandora_conversion-*.sql.gz' -mtime +14 -delete
```

**Test the restore at least once.** Untested backups are not backups.

---

## Upgrade triggers (when to leave Linode — ADR-007 §5)

Re-evaluate ADR-006 (cloud migration) when **any** of:

| Trigger                                      | Action                                  |
| -------------------------------------------- | --------------------------------------- |
| Pandora Core DAU > 1,000                     | Move identity to AWS / GCP              |
| Third consumer service onboarded             | Re-evaluate event bus (Redis Stream)    |
| Pandora Core RPS p95 > 30 sustained 1 week   | Cloud + read replica                    |
| Linode RAM > 80% for 3 consecutive days      | Resize to 8GB (~$48/mo) or split DB host |
| Webhook delivery failure rate > 1% / week    | Add event bus as secondary channel      |
| Paid members > 100                           | Schedule external pentest               |
| Any data leak / security incident            | Immediate cloud + pentest               |

Monitor monthly via the Filament `/admin/funnel` dashboard + RAM/CPU graphs.

---

## TODOs / Deferred decisions

| #   | Item                                  | Notes                                                                |
| --- | ------------------------------------- | -------------------------------------------------------------------- |
| 1   | Sentry DSN                            | Add to backend + ai-service + py-service `.env` once Sentry org is provisioned |
| 2   | Prometheus + Grafana                  | Defer until upgrade trigger; for now, healthcheck.sh + Discord is enough |
| 3   | brotli compression                    | Requires `nginx-extras` package; gzip is on by default                |
| 4   | CSP tightening                        | Filament admin uses inline JS; audit + restrict after launch          |
| 5   | LARAVEL_INTERNAL_SHARED_SECRET key name | ai-service env names the outbound callback secret; confirm matches backend's expected header (see ADR-002 §3) |
| 6   | py-service repo path                  | Lives in separate repo; replicate `deploy/` into that repo when ready |
| 7   | CI/CD pipeline                        | Currently manual `bash deploy-dodo.sh`; add GitHub Actions workflow when team grows |
| 8   | Blue-green / canary                   | Single-box, single-instance — true zero-downtime deferred until cloud move |

---

## Rollback playbook (Laravel migration gone wrong)

```bash
ssh root@139.162.121.187 <<'EOF'
cd /var/www/dodo/backend
php artisan down --refresh=15
php artisan migrate:rollback --step=1   # roll back the last batch
# If app code itself is the problem, re-deploy from previous git tag:
#   on local: git checkout <prev-tag> && bash deploy/scripts/deploy-dodo.sh --skip-migrate
php artisan up
EOF
```

For ai-service / py-service: roll back the git tag and re-deploy, then
`systemctl restart`. systemd's `Restart=always` does not undo bad code — it
only recovers from crashes.
