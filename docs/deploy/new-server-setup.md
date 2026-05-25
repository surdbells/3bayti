# 3bayti — New DigitalOcean Server Setup (aaPanel, v3 stack)

Complete setup runbook for a fresh DigitalOcean droplet running the full
3bayti v3 stack: API (Slim 4 + PHP 8.3 + PostgreSQL 16 + Redis 7) served
by Apache via aaPanel.

Estimated time: 90–120 minutes end-to-end on a fresh droplet.

---

## 0. What you will have at the end

| Service | URL / socket |
|---|---|
| v3 API | `https://api-v3.3bayti.ae` |
| Image uploads (Flysystem local) | `https://api-v3.3bayti.ae/uploads/` (Apache Alias → `var/uploads/`) |
| Cloudflare image transforms | `https://api-v3.3bayti.ae/cdn-cgi/image/width=480,.../uploads/...` |
| aaPanel admin | `http://<droplet-ip>:8888` |
| PostgreSQL 16 | `127.0.0.1:5432` |
| Redis 7 | `127.0.0.1:6379` |
| PHP-FPM 8.3 | `/tmp/php-cgi-83.sock` |
| GitHub deploy | SSH key on box + secrets in repo |

---

## 1. Create the Droplet

In the DigitalOcean dashboard (or via `doctl`):

| Setting | Value |
|---|---|
| **Image** | Ubuntu 24.04 LTS x64 |
| **Size** | 4 vCPU / 8 GB RAM / 160 GB SSD (minimum — `s-4vcpu-8gb`) |
| **Region** | Frankfurt (fra1) — closest to UAE |
| **VPC** | Default or create `3bayti-vpc` |
| **Backups** | Enable weekly backups |
| **SSH key** | Add your own public key so you can log in |
| **Hostname** | `3bayti-v3` |

> **Why 8 GB?** PostgreSQL + Redis + PHP-FPM + Apache + aaPanel together idle at ~2.5 GB.
> Under moderate load (50 concurrent requests) you need ~4 GB headroom.
> 4 GB droplets have caused OOM kills in practice on similar stacks.

Via `doctl`:
```bash
doctl compute droplet create 3bayti-v3 \
  --region fra1 \
  --size s-4vcpu-8gb \
  --image ubuntu-24-04-x64 \
  --ssh-keys <your-key-id> \
  --enable-backups \
  --wait
```

Note the droplet IP. It is referenced as `<DROPLET_IP>` throughout this runbook.

---

## 2. Initial server hardening

```bash
ssh root@<DROPLET_IP>

# Update system
apt update && apt upgrade -y

# Set timezone to UAE
timedatectl set-timezone Asia/Dubai

# Increase file descriptor limits (needed for PHP-FPM + PostgreSQL)
cat >> /etc/security/limits.conf <<'EOF'
* soft nofile 65536
* hard nofile 65536
root soft nofile 65536
root hard nofile 65536
EOF

# Swap (aaPanel installer needs this; 4 GB swap on an 8 GB server is fine)
fallocate -l 4G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab

# Confirm
free -h
```

---

## 3. Install aaPanel

```bash
# Official aaPanel installer (Ubuntu 24.04)
URL=https://www.aapanel.com/script/install_7.0_en.sh
wget -O /tmp/aapanel-install.sh "$URL"
bash /tmp/aapanel-install.sh

# The installer prints the panel URL + credentials at the end.
# SAVE THEM — they look like:
#   aaPanel Internet Address:  http://<DROPLET_IP>:8888/xxxxx
#   username: aabbccdd
#   password: 12345678
# Change the password immediately after first login.
```

> **aaPanel uses port 8888.** Add a firewall rule to allow 8888 only
> from your own IP (not 0.0.0.0/0). The panel exposes a root shell —
> never leave it open to the world.

```bash
# DigitalOcean firewall (or ufw):
ufw allow from <YOUR_IP> to any port 8888
ufw allow 22    # SSH
ufw allow 80    # HTTP
ufw allow 443   # HTTPS
ufw --force enable
```

---

## 4. Install the stack via aaPanel

Log into aaPanel at `http://<DROPLET_IP>:8888/<panel-path>`.

### 4a. LAMP stack prompt

aaPanel shows a "recommended software" prompt on first login.
Select **LAMP** (Linux + Apache + MySQL + PHP). We will replace MySQL
with PostgreSQL and adjust PHP version — but selecting LAMP installs
Apache, which is what we need.

- Apache: **2.4** ✓
- PHP: **8.3** ✓
- MySQL: install it (we'll ignore it; PostgreSQL goes alongside)
- phpMyAdmin: skip

Click Install. Takes 5–10 minutes.

### 4b. Install PostgreSQL 16

aaPanel → **App Store** → search `PostgreSQL` → install **PostgreSQL 16**.

After install:
```bash
# Verify
/www/server/pgsql/bin/psql --version
# Expected: psql (PostgreSQL) 16.x

# aaPanel manages the data directory at /www/server/pgsql/data
# and the service via systemctl / its own panel
```

### 4c. Install Redis 7

aaPanel → **App Store** → search `Redis` → install **Redis 7**.

```bash
redis-cli ping
# Expected: PONG
```

### 4d. Verify PHP 8.3 extensions

```bash
php8.3 -m | sort | grep -E "curl|json|mbstring|openssl|pdo|pdo_pgsql|redis|bcmath|fileinfo"
# Must see: curl, json, mbstring, openssl, pdo, pdo_pgsql, redis, bcmath, fileinfo
```

If `pdo_pgsql` is missing:

```bash
# aaPanel → PHP 8.3 → Extensions → install "pdo_pgsql" and "pgsql"
# Or via CLI:
/www/server/php/83/bin/pecl install pdo_pgsql
echo "extension=pdo_pgsql.so" >> /www/server/php/83/etc/php.ini
/etc/init.d/php-fpm-83 restart
```

If `redis` extension is missing:

```bash
# aaPanel → PHP 8.3 → Extensions → install "redis"
```

---

## 5. Create the PostgreSQL database

```bash
# Switch to postgres user
su - postgres -s /bin/bash -c "/www/server/pgsql/bin/psql"

-- Inside psql:
CREATE ROLE bayti_v3 WITH LOGIN PASSWORD 'CHANGE_THIS_STRONG_PASSWORD';
CREATE DATABASE bayti_v3 OWNER bayti_v3 ENCODING 'UTF8';
GRANT ALL PRIVILEGES ON DATABASE bayti_v3 TO bayti_v3;
\q
```

```bash
# Test connection
/www/server/pgsql/bin/psql \
  -U bayti_v3 -h 127.0.0.1 -d bayti_v3 \
  -c "SELECT current_database(), current_user, version();"
# Expected: one row, no error
```

> **Save the password** — you will put it in `.env` in Step 9.

---

## 6. Create the website in aaPanel

aaPanel → **Website** → **Add Site**

| Field | Value |
|---|---|
| **Domain** | `api-v3.3bayti.ae` |
| **Root directory** | `/www/wwwroot/3bayti/apps/api/public` |
| **PHP version** | `8.3` |
| **Database** | None (we use PostgreSQL, not MySQL) |
| **SSL** | Leave blank — configure in Step 11 |

Click **Submit**.

aaPanel creates:
- `/www/wwwroot/3bayti/apps/api/public/` (initially empty)
- `/www/server/panel/vhost/apache/api-v3.3bayti.ae.conf`

---

## 7. Create the SSH deploy key

```bash
# On the server — generate a dedicated deploy key
ssh-keygen -t ed25519 \
  -C "3bayti-deploy@$(hostname)" \
  -f /root/.ssh/3bayti_deploy \
  -N ""

cat /root/.ssh/3bayti_deploy.pub
# COPY THIS OUTPUT — add it to GitHub as a deploy key
```

In GitHub → **New repo** → **Settings** → **Deploy keys** → **Add deploy key**:
- Title: `aaPanel production deploy`
- Key: paste the public key
- Allow write access: **NO** (read-only — we only pull)

```bash
# Restrict the key in authorized_keys to ONLY run the deploy script:
cat >> /root/.ssh/authorized_keys <<'AUTHEOF'
command="/usr/local/bin/3bayti-deploy.sh",no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 AAAA... 3bayti-deploy@hostname
AUTHEOF
# Replace the "ssh-ed25519 AAAA..." with the actual public key content above.
```

---

## 8. Clone the repository

```bash
# Test SSH access to GitHub first
ssh -T git@github.com
# Expected: "Hi <repo>! You've successfully authenticated..."

# Clone into the expected location
mkdir -p /www/wwwroot
git clone git@github.com:YOUR_ORG/YOUR_NEW_REPO.git /www/wwwroot/3bayti
# Replace YOUR_ORG/YOUR_NEW_REPO with the new repo URL

# Verify
ls /www/wwwroot/3bayti/apps/api/public/index.php
# Must exist
```

---

## 9. Create the production .env

```bash
cp /www/wwwroot/3bayti/apps/api/.env.example \
   /www/wwwroot/3bayti/apps/api/.env

nano /www/wwwroot/3bayti/apps/api/.env
```

Fill in every value. Minimum required:

```ini
APP_ENV=production
APP_VERSION=initial
APP_URL=https://api-v3.3bayti.ae
WEB_APP_URL=https://3bayti.ae
APP_SECRET=<64-char-random-string>

# Public base URL for uploaded files.
# Apache serves apps/api/var/uploads/ under /uploads/ (Step 14 vhost Alias).
# The front-end wraps this with /cdn-cgi/image/... for CF image transforms.
UPLOADS_PUBLIC_URL=https://api-v3.3bayti.ae/uploads

DB_DRIVER=pdo_pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bayti_v3
DB_USERNAME=bayti_v3
DB_PASSWORD=<the password you set in Step 5>
DB_CHARSET=utf8
DB_SSLMODE=disable

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0

JWT_SECRET=<64-char-random-string>
JWT_ACCESS_TOKEN_TTL=900
JWT_REFRESH_TOKEN_TTL=604800
JWT_ISSUER=3bayti-api

# CORS — all browser origins that call this API
CORS_ALLOWED_ORIGINS=https://staging.3bayti.ae,https://3bayti.ae,https://app.3bayti.ae,http://localhost:8100,http://localhost:4200

SMS_PROVIDER=messagecentral
MESSAGECENTRAL_CUSTOMER_ID=<your value>
MESSAGECENTRAL_KEY=<your value>
MESSAGECENTRAL_EMAIL=<your value>
MESSAGECENTRAL_COUNTRY=971
MESSAGECENTRAL_BASE_URL=https://cpaas.messagecentral.com

ZEPTO_MAIL_API_TOKEN=<your value>
ZEPTO_MAIL_FROM_EMAIL=noreply@3bayti.ae
ZEPTO_MAIL_FROM_NAME="3bayti"

FCM_PROJECT_ID=<your value>
FCM_CLIENT_EMAIL=<your value>
FCM_PRIVATE_KEY=<your value>
PUSH_PROVIDER=fcm
```

Generate random secrets:
```bash
# For APP_SECRET and JWT_SECRET:
openssl rand -hex 32
# Run twice; paste one value for each.
```

---

## 10. Install Composer dependencies

```bash
cd /www/wwwroot/3bayti/apps/api

# aaPanel ships an older Composer — update it first
composer self-update

# Install production dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# Verify the autoloader was created
ls vendor/autoload.php
```

---

## 11. Fix aaPanel PHP CLI restrictions

aaPanel's PHP CLI `disable_functions` blocks `putenv` and `proc_open`.
The migration script and Composer scripts need them.

```bash
# Find the PHP CLI ini
/www/server/php/83/bin/php --ini | grep "Loaded Configuration"
# Typically: /www/server/php/83/etc/php-cli.ini

# Edit it
nano /www/server/php/83/etc/php-cli.ini

# Find the disable_functions line and REMOVE putenv and proc_open from it:
# Before: disable_functions = passthru,exec,system,putenv,chroot,...,proc_open,...
# After:  disable_functions = passthru,exec,system,chroot,...
# (keep everything except putenv and proc_open)
```

---

## 12. Fix the open_basedir restriction

aaPanel injects a `.user.ini` that only allows `public/` and `/tmp/`.
PHP can't reach `vendor/`, `src/`, or `config/` with this restriction.

```bash
USERINI="/www/wwwroot/3bayti/apps/api/public/.user.ini"
chattr -i "$USERINI"          # remove immutable flag

cat > "$USERINI" <<'EOF'
; aaPanel-managed PHP config — expanded for 3bayti API.
; open_basedir covers the full apps/api/ tree so vendor/, src/,
; config/, var/, and the monorepo root packages/ are all readable.
open_basedir=/www/wwwroot/3bayti/apps/api/:/tmp/
EOF

chmod 644 "$USERINI"
chattr +i "$USERINI"          # restore immutable — aaPanel won't clobber it
```

---

## 13. Run the database migration

```bash
cd /www/wwwroot/3bayti/apps/api

# Dry-run first — shows what will be applied
php bin/migrate.php --dry-run

# Apply all migrations
php bin/migrate.php

# Expected output:
#   [migrate] Migrating up to Version20260523000001
#   [migrate] ++ migrating Version20260523000001
#   [migrate]    -> ALTER TABLE vendors ADD COLUMN notification_prefs ...
#   [migrate]    -> CREATE TABLE support_tickets ...
#   [migrate]    -> CREATE TABLE support_ticket_messages ...
#   [migrate]    -> CREATE TABLE product_collections ...
#   [migrate] Successfully applied X migration(s).

# After migration, verify doctrine proxies were generated
ls var/cache/doctrine/proxies/ | head -5
```

---

## 14. Configure the Apache vhost

aaPanel created the vhost at:
```
/www/server/panel/vhost/apache/api-v3.3bayti.ae.conf
```

Edit it (aaPanel → Website → `api-v3.3bayti.ae` → Config, or `nano`):

```apache
<VirtualHost *:80>
    ServerName api-v3.3bayti.ae
    DocumentRoot /www/wwwroot/3bayti/apps/api/public

    # Slim front-controller rewrite
    <Directory /www/wwwroot/3bayti/apps/api/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # CRITICAL: pass Authorization header to PHP-FPM
    # aaPanel's default vhost omits this — without it every
    # authenticated request returns HTTP 401.
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

    # CORS — handled here at the Apache layer
    # Add/remove origins to match CORS_ALLOWED_ORIGINS in .env
    <IfModule mod_headers.c>
        SetEnvIf Origin "^https?://(localhost:8100|localhost:4200|staging\.3bayti\.ae|3bayti\.ae|app\.3bayti\.ae|vendor\.3bayti\.ae)$" CORS_ORIGIN=$0

        Header always set Access-Control-Allow-Origin    "%{CORS_ORIGIN}e" env=CORS_ORIGIN
        Header always set Access-Control-Allow-Methods   "GET, POST, PUT, PATCH, DELETE, OPTIONS"
        Header always set Access-Control-Allow-Headers   "Authorization, Content-Type, X-Requested-With, X-Request-ID"
        Header always set Access-Control-Allow-Credentials "true"
        Header always set Access-Control-Max-Age         "86400"

        # Short-circuit OPTIONS preflight — return 204 immediately
        RewriteEngine On
        RewriteCond %{REQUEST_METHOD} OPTIONS
        RewriteRule ^ - [R=204,L]
    </IfModule>

    # PHP-FPM via aaPanel standard socket
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/tmp/php-cgi-83.sock|fcgi://localhost"
    </FilesMatch>

    # Static file serving for uploaded images.
    # Flysystem writes to apps/api/var/uploads/; Apache serves that
    # directory under /uploads/ as a public static path.
    # Cloudflare image transforms work by wrapping this URL:
    #   /cdn-cgi/image/width=400,quality=80,format=auto/uploads/products/...
    # The PHP front-controller is NOT involved for /uploads/ requests —
    # Apache serves the files directly, keeping latency low and PHP-FPM
    # load off the image hot path.
    Alias /uploads /www/wwwroot/3bayti/apps/api/var/uploads

    <Directory /www/wwwroot/3bayti/apps/api/var/uploads>
        Options -Indexes
        AllowOverride None
        Require all granted
        # Long cache for hashed ULID filenames — they never change.
        # Stable filenames (logo.jpg, cover.jpg) get a shorter TTL so
        # re-uploads propagate within 5 minutes via CF cache purge.
        <FilesMatch "\.(jpg|jpeg|png|webp|gif)$">
            Header always set Cache-Control "public, max-age=31536000, immutable"
        </FilesMatch>
    </Directory>

    ErrorLog  /www/wwwlogs/api-v3.3bayti.ae.error.log
    CustomLog /www/wwwlogs/api-v3.3bayti.ae.access.log combined
</VirtualHost>
```

Enable required Apache modules:
```bash
a2enmod headers rewrite proxy proxy_fcgi
/www/server/apache/bin/apachectl configtest
# Expected: Syntax OK

/www/server/apache/bin/apachectl graceful
```

---

## 15. Set directory ownership

```bash
chown -R www:www /www/wwwroot/3bayti/apps/api
chmod -R 755 /www/wwwroot/3bayti/apps/api

# var/ needs write access for PHP-DI cache + Doctrine proxies
chmod -R 775 /www/wwwroot/3bayti/apps/api/var
chown -R www:www /www/wwwroot/3bayti/apps/api/var

# .env must be readable by www but not world-readable
chmod 640 /www/wwwroot/3bayti/apps/api/.env
chown root:www /www/wwwroot/3bayti/apps/api/.env
```

---

## 16. Smoke test over HTTP

```bash
# Health check (no auth required)
curl -i http://api-v3.3bayti.ae/v3/health
# Expected: HTTP 200, {"status":"ok","db":"connected"}

curl -i http://api-v3.3bayti.ae/v3/health/ready
# Expected: HTTP 200, checks.database: ok

# CORS preflight from mobile dev origin
curl -s -I -X OPTIONS \
  -H "Origin: http://localhost:8100" \
  -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: Authorization" \
  http://api-v3.3bayti.ae/v3/health
# Expected: HTTP 204 + access-control-allow-origin: http://localhost:8100

# Public catalog
curl -s http://api-v3.3bayti.ae/v3/products?limit=1 | python3 -m json.tool | head -10
```

---

## 17. TLS via aaPanel Let's Encrypt

> **DNS first:** point `api-v3.3bayti.ae` → `<DROPLET_IP>` as an **A record**
> in Cloudflare, **gray cloud (DNS only, not proxied)**. Let's Encrypt
> must reach the server directly for the HTTP-01 challenge.

```bash
# Verify DNS is resolving to the correct IP before requesting the cert
dig +short api-v3.3bayti.ae
# Must match <DROPLET_IP>
```

In aaPanel → **Website** → `api-v3.3bayti.ae` → **SSL** → **Let's Encrypt**:
1. Domain pre-fills: `api-v3.3bayti.ae`
2. Click **Apply**
3. Wait ~30 seconds

aaPanel adds the `<VirtualHost *:443>` block automatically and enables
HTTP→HTTPS redirect. Verify:

```bash
curl -i https://api-v3.3bayti.ae/v3/health
# Expected: HTTP/2 200, {"status":"ok","db":"connected"}
```

---

## 18. Write the auto-deploy script

```bash
cat > /usr/local/bin/3bayti-deploy.sh <<'DEPLOY_EOF'
#!/usr/bin/env bash
set -euo pipefail

REPO=/www/wwwroot/3bayti
API=$REPO/apps/api
LOG=/var/log/3bayti-deploy.log

echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) — deploy triggered" | tee -a "$LOG"

# 1. Pull latest code
cd "$REPO"
git fetch --quiet origin
git reset --hard origin/main

SHA=$(git rev-parse --short HEAD)
echo "  commit: $SHA" | tee -a "$LOG"

# 2. Install/update Composer dependencies
cd "$API"
composer install \
  --no-dev \
  --optimize-autoloader \
  --no-interaction \
  --quiet

# 3. Clear PHP-DI compiled container cache
#    MUST run before migration — new repositories registered in di.php
#    won't resolve until the compiled cache is rebuilt from fresh source.
rm -rf var/cache/di/*
echo "  php-di cache cleared" | tee -a "$LOG"

# 4. Run migrations (idempotent — no-ops if nothing new)
php bin/migrate.php 2>&1 | tee -a "$LOG"

# 5. Stamp the version in .env so /v3/health reflects the commit
if grep -q "^APP_VERSION=" .env; then
  sed -i "s/^APP_VERSION=.*/APP_VERSION=$SHA/" .env
else
  echo "APP_VERSION=$SHA" >> .env
fi

echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) — deploy complete ($SHA)" | tee -a "$LOG"
DEPLOY_EOF

chmod +x /usr/local/bin/3bayti-deploy.sh
```

Test it:
```bash
/usr/local/bin/3bayti-deploy.sh
# Expected: runs without error, prints commit SHA, migration no-ops
```

---

## 19. Configure GitHub Actions secrets

In GitHub → **Settings** → **Secrets and variables** → **Actions**:

### Secrets (repository secrets)

| Secret name | Value |
|---|---|
| `DEPLOY_HOST` | `<DROPLET_IP>` |
| `DEPLOY_USER` | `root` |
| `DEPLOY_SSH_KEY` | Full contents of `/root/.ssh/3bayti_deploy` (private key) |
| `API_SMOKE_URL` | `https://api-v3.3bayti.ae` (optional — enables post-deploy smoke test) |

### Variables (repository variables)

| Variable name | Value |
|---|---|
| `ENABLE_AUTO_DEPLOY` | `true` |

Test by pushing any change to `main` that touches `apps/api/**` and
watching the **Actions** tab → **API — build & deploy** → **deploy** job.

---

## 20. Restore data from old server (if migrating)

Products, vendors, and users were confirmed migrated. If you need to
move the full database from the old server:

```bash
# On the OLD server — dump
pg_dump -U 3bayti -h 127.0.0.1 -d 3bayti -F c \
  -f /tmp/3bayti-prod-$(date +%Y%m%d).dump

# Copy to new server
scp /tmp/3bayti-prod-*.dump root@<DROPLET_IP>:/tmp/

# On the NEW server — restore
pg_restore \
  -U bayti_v3 \
  -h 127.0.0.1 \
  -d bayti_v3 \
  --no-owner \
  --role=bayti_v3 \
  /tmp/3bayti-prod-*.dump

# Run migrations after restore to apply any new M3.4 schema changes:
cd /www/wwwroot/3bayti/apps/api
php bin/migrate.php
```

> If the old DB name/user was different (e.g. `3bayti` / `3bayti`),
> `pg_restore --no-owner` combined with `--role=bayti_v3` handles the
> ownership remapping.

---

## 21. Fix the mobile CORS issue (localhost:8100)

The Apache vhost in Step 14 already includes `localhost:8100` in the
CORS origin allowlist. Once the new server is live and DNS is updated,
`ionic serve` on your dev machine will no longer get CORS-blocked.

For immediate local testing before DNS cuts over, add the new server IP
to your dev machine's `/etc/hosts`:

```bash
# On your dev machine:
echo "<DROPLET_IP>  api-v3.3bayti.ae" | sudo tee -a /etc/hosts
```

Then `ionic serve` will hit the new server and CORS will be allowed.

---

## 22. Post-setup checklist

- [ ] `https://api-v3.3bayti.ae/v3/health` → `{"status":"ok","db":"connected"}`
- [ ] `https://api-v3.3bayti.ae/v3/health/ready` → `checks.database: ok`
- [ ] TLS cert is Let's Encrypt, valid for 90 days
- [ ] CORS preflight from `localhost:8100` returns HTTP 204 (mobile CORS fixed)
- [ ] CORS preflight from `app.3bayti.ae` returns HTTP 204 (portal works)
- [ ] GitHub Actions **deploy** job runs green on push to main
- [ ] `/v3/health` commit SHA matches the latest `main` commit after a deploy
- [ ] `pg_dump` backup scheduled (aaPanel → Cron or manual — every 6 hours minimum)
- [ ] Redis is bound to `127.0.0.1` only: `grep bind /etc/redis/redis.conf`
- [ ] aaPanel port 8888 is firewalled to your IP only

## 23. Migrate legacy product images to Flysystem

This copies all product images and vendor logos from the legacy server
into local Flysystem storage (`var/uploads/`) and updates the URL columns
in PostgreSQL so Cloudflare image transforms start working.

**Run this after Step 22 passes.** The migration is idempotent — any URL
already starting with `UPLOADS_PUBLIC_URL` is skipped, so re-running is
always safe.

### 23a. Verify var/uploads/ is writable and Apache serves it

```bash
# Confirm the Alias is working (should return 404, not 403 or 500)
curl -I https://api-v3.3bayti.ae/uploads/test
# Expected: HTTP/2 404 (directory exists, file doesn't — correct)

# Confirm the directory exists and is owned by www
ls -la /www/wwwroot/3bayti/apps/api/var/uploads/
# If it doesn't exist yet, Flysystem creates it on first write — that's fine.
```

### 23b. (Option A) Fetch images from the legacy server over HTTP

Use this if the old server is reachable at `https://api.3bayti.ae`. The
migration script fetches each image via HTTP and writes it to Flysystem.

```bash
cd /www/wwwroot/3bayti/apps/api

# Dry-run first — see what would happen without writing anything
php bin/migrate-from-legacy/migrate-images.php --dry-run

# Run the full migration
php bin/migrate-from-legacy/migrate-images.php \
  2>&1 | tee /tmp/image-migration-$(date +%Y%m%d-%H%M).log
```

### 23b. (Option B) Copy directly from legacy disk (faster, no HTTP)

Use this if you can read the old server's file tree on the same machine
(e.g. mounted NFS, rsync copy, or both servers share a volume).

```bash
# First, rsync the legacy image directory to the new server
rsync -avz --progress \
  root@<old-server-ip>:/www/wwwroot/legacy/vendors/products/ \
  /tmp/legacy-product-images/

# Then run the migration pointing at the local copy
php bin/migrate-from-legacy/migrate-images.php \
  --ssh-copy=/tmp/legacy-product-images \
  2>&1 | tee /tmp/image-migration-$(date +%Y%m%d-%H%M).log
```

### 23c. Verify migration

```bash
# Check how many products still have legacy URLs
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT COUNT(*) AS legacy_product_images_remaining
  FROM products
  WHERE primary_image_url LIKE 'https://api.3bayti.ae/%';
"
# Target: 0

# Check vendor logos
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT COUNT(*) AS legacy_vendor_logos_remaining
  FROM vendors
  WHERE logo_url LIKE 'https://api.3bayti.ae/%'
     OR legacy_logo_data_url IS NOT NULL;
"
# Target: 0

# Test a migrated image URL directly
curl -I https://api-v3.3bayti.ae/uploads/products/<vendor-slug>/<file>.jpg
# Expected: HTTP/2 200, content-type: image/jpeg

# Test Cloudflare image transform on the same URL
curl -I "https://api-v3.3bayti.ae/cdn-cgi/image/width=480,quality=82,fit=cover,format=auto/https://api-v3.3bayti.ae/uploads/products/<vendor-slug>/<file>.jpg"
# Expected: HTTP/2 200, content-type: image/webp (or avif)
# CF-Cache-Status: HIT (on second request — first request populates the cache)
```

### 23d. Individual retry flags

```bash
# Retry a single product (useful for testing)
php bin/migrate-from-legacy/migrate-images.php --product-id=123

# Retry a single vendor
php bin/migrate-from-legacy/migrate-images.php --vendor-id=5

# Migrate only vendor logos (skip products)
php bin/migrate-from-legacy/migrate-images.php --vendors-only

# Cap total files processed (useful for incremental runs)
php bin/migrate-from-legacy/migrate-images.php --limit=500
```

### 23e. Set var/uploads/ permissions after migration

```bash
# Ensure www can write new uploads (product images via portal)
chown -R www:www /www/wwwroot/3bayti/apps/api/var/uploads
chmod -R 755 /www/wwwroot/3bayti/apps/api/var/uploads
```

---

## 24. Cloudflare image transform smoke test

Confirm Cloudflare is actually resizing images (not just passing through).

```bash
# Pick any migrated product image URL from the DB:
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c \
  "SELECT primary_image_url FROM products WHERE primary_image_url LIKE '%/uploads/%' LIMIT 1;"

# Test the transform — check the content-type and CF headers
curl -sI \
  "https://api-v3.3bayti.ae/cdn-cgi/image/width=480,quality=82,fit=cover,format=auto/<IMAGE_URL_FROM_ABOVE>" \
  -H "Accept: image/avif,image/webp,*/*" \
  | grep -iE "content-type|content-length|cf-cache|cf-ray"

# Expected:
#   content-type: image/webp       (or image/avif if browser supports it)
#   cf-cache-status: MISS          (first request — CF is fetching from origin)
#
# Run the same curl again:
#   cf-cache-status: HIT           (served from CF edge — zero origin load)
```

> **If you see `content-type: image/jpeg` and no `cf-cache-status`:** the
> domain is not Cloudflare-proxied (gray cloud). Switch `api-v3.3bayti.ae`
> to orange cloud in Cloudflare DNS. TLS must already be working (Step 17)
> before enabling the proxy.

---

## Troubleshooting quick reference

| Symptom | Cause | Fix |
|---|---|---|
| HTTP 500 empty body on any request | `open_basedir` blocking vendor/ | Step 12 |
| HTTP 401 on all authenticated requests | Apache not passing Authorization header | `SetEnvIf Authorization` in vhost (Step 14) |
| PHP-DI: service not found after new deploy | Compiled container cache stale | `rm -rf var/cache/di/*` then FPM request |
| `proc_open` / `putenv` disabled errors | PHP CLI disable_functions | Step 11 |
| Migration runs fine in FPM, fails in CLI | CLI php.ini differs from FPM | Check `/www/server/php/83/etc/php-cli.ini` vs `php.ini` |
| `pdo_pgsql` not found | Extension not installed | aaPanel → PHP 8.3 → Extensions |
| CORS error in browser despite vhost config | mod_headers not enabled | `a2enmod headers; apachectl graceful` |
| Let's Encrypt fails | DNS still pointing at old server | Gray-cloud the Cloudflare record, wait TTL |
| Deploy job: Permission denied (publickey) | Deploy key not in authorized_keys | Step 7 |
| Deploy script not found | Script not created or wrong path | Step 18 |
| `POST /v3/upload` returns 403 | `open_basedir` blocking `var/uploads/` write | Confirm `.user.ini` open_basedir includes full `apps/api/` path (Step 12) |
| `POST /v3/upload` returns 500 | `var/uploads/` not writable by www | `chown -R www:www var/uploads && chmod -R 755 var/uploads` |
| `/uploads/` returns 403 Forbidden | Apache dir listing disabled | Normal — listing off by design; individual file paths work |
| `/uploads/` returns 404 or 500 | Apache Alias not active | Verify Alias block in vhost (Step 14) and `a2enmod headers; apachectl graceful` |
| CF transform returns JPEG not WebP | Domain not CF-proxied (gray cloud) | Switch `api-v3.3bayti.ae` to orange cloud in Cloudflare DNS |
| CF transform returns Cloudflare 530 error | Image Resizing not enabled | Cloudflare dashboard → Speed → Optimization → Enable Image Resizing |
| Image migration: HTTP fetch failed | Old server unreachable from new IP | Use `--ssh-copy=` with rsync'd directory (Step 23b Option B) |
| Image migration: all rows skipped | `UPLOADS_PUBLIC_URL` wrong or unset | Set `UPLOADS_PUBLIC_URL=https://api-v3.3bayti.ae/uploads` in `.env` |
