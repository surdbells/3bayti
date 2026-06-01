# 3bayti v3 — Full Deployment Guide (Contabo + Cloudflare)

Complete step-by-step runbook for deploying the 3bayti v3 stack on a fresh
Contabo VPS with Cloudflare as the CDN/DNS layer.

**Estimated time:** 2–3 hours end-to-end on a fresh server.

---

## Architecture overview

```
Mobile app (Ionic/Capacitor)
  └── HTTPS → api-v3.3bayti.ae (Cloudflare orange cloud)
                 └── Contabo VPS (aaPanel + Apache + PHP 8.3)
                       ├── apps/api/         ← v3 PHP/Slim API
                       ├── var/uploads/      ← product images (Flysystem local)
                       ├── PostgreSQL 16      ← primary database
                       └── Redis 7            ← sessions + queue

Web storefront (3bayti.ae)
  └── Cloudflare Workers (serverless SSR, auto-deployed from main)

Vendor portal (app.3bayti.ae)
  └── Cloudflare Pages (SPA, auto-deployed from main)
```

**DNS zones (all on Cloudflare):**

| Record | Points to | Proxy |
|---|---|---|
| `api-v3.3bayti.ae` | Contabo server IP | 🟠 Orange cloud (proxied) |
| `3bayti.ae` | Cloudflare Workers | 🟠 Orange cloud |
| `app.3bayti.ae` | Cloudflare Pages | 🟠 Orange cloud |

---

## Part A — Contabo VPS setup

### A1. Order and access the VPS

Order a **Cloud VPS M** or larger from [contabo.com](https://contabo.com):

| Setting | Value |
|---|---|
| **OS image** | Ubuntu 24.04 LTS |
| **RAM** | 8 GB minimum (PostgreSQL + Redis + PHP-FPM + Apache idle at ~2.5 GB) |
| **Storage** | 200 GB SSD minimum (images will grow) |
| **Location** | EU (Germany/UK) — lowest latency to UAE |

Contabo emails you the root password and IP address. Note the IP — it is
referenced as `<SERVER_IP>` throughout this document.

**First login:**
```bash
ssh root@<SERVER_IP>
# Accept the fingerprint, then immediately change the root password:
passwd
```

---

### A2. Initial server hardening

```bash
# Update packages
apt update && apt upgrade -y

# Set timezone
timedatectl set-timezone Asia/Dubai

# Disable password SSH auth (use key-based only after adding your key)
mkdir -p ~/.ssh
# Paste your PUBLIC key:
echo "ssh-rsa AAAA...your-public-key... comment" >> ~/.ssh/authorized_keys
chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys

# Harden SSH
sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl reload sshd

# UFW firewall — allow only what's needed
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp        # SSH
ufw allow 80/tcp        # HTTP (Let's Encrypt challenge + redirect)
ufw allow 443/tcp       # HTTPS
ufw allow 8888/tcp      # aaPanel admin (restrict to your IP after setup)
ufw enable
```

> **Contabo note:** Contabo VPS instances come with UFW inactive by default.
> Unlike DigitalOcean, there is no separate cloud firewall UI — UFW is your
> only firewall layer. Make sure port 22 is open BEFORE enabling UFW.

---

### A3. Install aaPanel

```bash
URL=https://www.aapanel.com/script/install_7.0_en.sh
if [ -f /usr/bin/curl ]; then
    curl -ksSO "$URL"
else
    wget --no-check-certificate -O install_7.0_en.sh "$URL"
fi
bash install_7.0_en.sh aapanel
```

At the end of the install you will see output like:
```
aaPanel Internet Address: http://<SERVER_IP>:8888/random-path
username: aapanel
password: <random>
```

Save these credentials. Open `http://<SERVER_IP>:8888` in your browser.

> **Restrict aaPanel to your IP only:**
> ```bash
> ufw delete allow 8888/tcp
> ufw allow from <your-home-ip> to any port 8888
> ```

---

### A4. Install the stack via aaPanel

Log into the aaPanel web UI (`http://<SERVER_IP>:8888`).

**4a. LAMP stack**

On the first-login prompt select **LAMP** (not LNMP — we want Apache, not Nginx).

Components to install:

| Component | Version |
|---|---|
| Apache | 2.4 |
| MySQL | **Skip** (we use PostgreSQL) |
| PHP | 8.3 |
| phpMyAdmin | Skip |

**4b. PHP 8.3 extensions**

aaPanel → App Store → PHP 8.3 → Extensions. Install:

- `pdo_pgsql`
- `redis`
- `opcache`
- `intl`
- `mbstring`
- `zip`
- `gd`
- `fileinfo`
- `sodium`

**4c. Redis**

aaPanel → App Store → Redis → Install (latest stable, bind to 127.0.0.1).

**4d. PostgreSQL 16**

aaPanel doesn't ship a Postgres app on all versions. Install via apt:
```bash
apt install -y postgresql-16 postgresql-client-16

# Start and enable on boot
systemctl enable postgresql
systemctl start postgresql

# Verify
psql --version
# postgresql 16.x
```

---

### A5. Create the PostgreSQL database

```bash
# Switch to postgres user
sudo -u postgres psql

-- Inside psql:
CREATE USER bayti_v3 WITH PASSWORD 'your-strong-password-here';
CREATE DATABASE bayti_v3 OWNER bayti_v3 ENCODING 'UTF8';
GRANT ALL PRIVILEGES ON DATABASE bayti_v3 TO bayti_v3;
\q
```

Test the connection:
```bash
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "SELECT version();"
# Should print PostgreSQL 16.x version string
```

PostgreSQL config — allow local connections:
```bash
nano /etc/postgresql/16/main/pg_hba.conf
# Ensure this line exists (should already be there):
# host  all  all  127.0.0.1/32  md5
systemctl reload postgresql
```

---

### A6. Create the website in aaPanel

aaPanel → **Website** → **Add site**:

| Field | Value |
|---|---|
| Domain | `api-v3.3bayti.ae` |
| Root directory | `/www/wwwroot/3bayti/apps/api/public` |
| PHP version | 8.3 |
| Database | None (PostgreSQL is external) |
| SSL | Skip for now (Step A17) |

---

### A7. Create the SSH deploy key

This key lets GitHub Actions deploy to the server without a password.

```bash
# On the SERVER:
ssh-keygen -t ed25519 -C "github-actions-deploy" -f /root/.ssh/3bayti_deploy -N ""

# Add the public key to authorized_keys
cat /root/.ssh/3bayti_deploy.pub >> /root/.ssh/authorized_keys

# Print the private key — you will paste this into GitHub Secrets
cat /root/.ssh/3bayti_deploy
# -----BEGIN OPENSSH PRIVATE KEY-----
# ...
# -----END OPENSSH PRIVATE KEY-----
```

Keep the private key output — you will need it in Step A19.

---

### A8. Clone the repository

```bash
mkdir -p /www/wwwroot
cd /www/wwwroot

# Clone via HTTPS (or SSH if you have a deploy key set up on GitHub)
git clone https://github.com/surdbells/3bayti.git 3bayti
cd 3bayti

# Checkout main
git checkout main
```

---

### A9. Create the production .env

```bash
cp /www/wwwroot/3bayti/apps/api/.env.example \
   /www/wwwroot/3bayti/apps/api/.env

nano /www/wwwroot/3bayti/apps/api/.env
```

Fill in ALL values. Template:

```ini
APP_ENV=production
APP_VERSION=initial
APP_URL=https://api-v3.3bayti.ae
WEB_APP_URL=https://3bayti.ae
APP_SECRET=<run: openssl rand -hex 32>

# Uploaded files public URL (Apache Alias in Step A14)
UPLOADS_PUBLIC_URL=https://api-v3.3bayti.ae/uploads

DB_DRIVER=pdo_pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bayti_v3
DB_USERNAME=bayti_v3
DB_PASSWORD=<your-pg-password-from-Step-A5>
DB_CHARSET=utf8
DB_SSLMODE=disable

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0

JWT_SECRET=<run: openssl rand -hex 32>
JWT_ACCESS_TOKEN_TTL=900
JWT_REFRESH_TOKEN_TTL=604800
JWT_ISSUER=3bayti-api

# All browser origins allowed to call this API
CORS_ALLOWED_ORIGINS=https://3bayti.ae,https://staging.3bayti.ae,https://app.3bayti.ae,http://localhost:8100,http://localhost:4200

# SMS (MessageCentral)
SMS_PROVIDER=messagecentral
MESSAGECENTRAL_CUSTOMER_ID=<your value>
MESSAGECENTRAL_KEY=<your value>
MESSAGECENTRAL_EMAIL=<your value>
MESSAGECENTRAL_COUNTRY=971
MESSAGECENTRAL_BASE_URL=https://cpaas.messagecentral.com

# Transactional email (ZeptoMail)
ZEPTO_MAIL_API_TOKEN=<your value>
ZEPTO_MAIL_FROM_EMAIL=noreply@3bayti.ae
ZEPTO_MAIL_FROM_NAME="3bayti"
ADMIN_NOTIFICATION_EMAILS=<comma-separated admin emails>
MAIL_PROVIDER=zeptomail

# Firebase Cloud Messaging (push notifications)
FCM_PROJECT_ID=<your Firebase project ID>
FCM_CLIENT_EMAIL=<service account email>
FCM_PRIVATE_KEY=<service account private key — include \n line breaks>
PUSH_PROVIDER=fcm

# Noon Payments — use LIVE keys for production
NOON_API_BASE=https://api.noonpayments.com
NOON_BUSINESS_IDENTIFIER=<your value>
NOON_APP_IDENTIFIER=<your value>
NOON_APP_KEY=<your value>
NOON_API_KEY=<your value>
NOON_WEBHOOK_SECRET=<your value>
NOON_VERIFY_SIGNATURE=true

LOG_LEVEL=warning
```

Generate secrets:
```bash
# For APP_SECRET and JWT_SECRET:
openssl rand -hex 32
```

> **Noon keys:** Log into your Noon Business dashboard → Settings → API Keys.
> Use the **LIVE** environment keys (not sandbox). Set `NOON_API_BASE` to
> `https://api.noonpayments.com` (no `-test`).

---

### A10. Install Composer dependencies

```bash
cd /www/wwwroot/3bayti/apps/api

# Install Composer if not already present
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install dependencies (no dev, no interaction)
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader
```

---

### A11. Fix aaPanel PHP CLI restrictions

aaPanel's PHP CLI has `disable_functions` that blocks several functions
needed by the migration scripts and deploy hook.

```bash
# Find the CLI php.ini
php -i | grep "Loaded Configuration File"
# Usually: /www/server/php/83/etc/php-cli.ini

nano /www/server/php/83/etc/php-cli.ini
# Find the disable_functions line and remove: proc_open,proc_close,putenv,exec,passthru,system,popen
# The line should end up empty or only contain truly dangerous functions you don't need.
# Save and exit.

# Verify
php -r "proc_open('echo test', [], \$pipes);" && echo "proc_open OK"
```

---

### A12. Fix the open_basedir restriction

aaPanel restricts PHP file access to the webroot by default. The API
needs to read `.env` and write to `var/`.

```bash
# Edit the site .user.ini
nano /www/wwwroot/3bayti/apps/api/public/.user.ini
```

Set:
```ini
open_basedir=/www/wwwroot/3bayti/apps/api/:/tmp/:/proc/
```

```bash
# Reload PHP-FPM to apply
/etc/init.d/php-fpm-83 reload
# or: pkill -USR2 php-fpm
```

---

### A13. Run the database migration

```bash
cd /www/wwwroot/3bayti/apps/api

php bin/migrate.php
# Expected output: applying each migration file in order
# Final line: "Migration complete"

# Verify tables exist
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "\dt" | head -20
```

---

### A14. Configure the Apache vhost

The aaPanel-generated vhost is in:
```
/www/server/panel/vhost/apache/api-v3.3bayti.ae.conf
```

Edit it (aaPanel → Website → `api-v3.3bayti.ae` → Config) and replace with:

```apacheconf
<VirtualHost *:80>
    ServerName api-v3.3bayti.ae
    DocumentRoot /www/wwwroot/3bayti/apps/api/public

    # Redirect all HTTP → HTTPS (uncomment after TLS is set up in Step A17)
    # RewriteEngine On
    # RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

    <Directory /www/wwwroot/3bayti/apps/api/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # PHP-FPM via aaPanel standard socket
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/tmp/php-cgi-83.sock|fcgi://localhost"
    </FilesMatch>

    # Static file serving for uploaded images.
    # Flysystem writes to apps/api/var/uploads/; Apache serves it under /uploads/.
    # Cloudflare image transforms wrap this URL:
    #   /cdn-cgi/image/width=480,quality=82,format=auto/uploads/products/...
    Alias /uploads /www/wwwroot/3bayti/apps/api/var/uploads

    <Directory /www/wwwroot/3bayti/apps/api/var/uploads>
        Options -Indexes
        AllowOverride None
        Require all granted
        <FilesMatch "\.(jpg|jpeg|png|webp|gif)$">
            Header always set Cache-Control "public, max-age=31536000, immutable"
        </FilesMatch>
    </Directory>

    # CORS — required for Cloudflare Workers + Pages + mobile
    Header always set Access-Control-Allow-Origin "%{ORIGIN}e" "expr=-n '%{ORIGIN}e'"
    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"
    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With"
    Header always set Access-Control-Allow-Credentials "true"
    Header always set Access-Control-Max-Age "86400"

    RewriteEngine On

    # Pass Authorization header to PHP (aaPanel strips it by default)
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle OPTIONS preflight
    RewriteCond %{REQUEST_METHOD} OPTIONS
    RewriteRule .* - [R=204,L]

    # Route everything to the front controller (except /uploads/)
    RewriteCond %{REQUEST_URI} !^/uploads/
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ /index.php [L]

    ErrorLog  /www/wwwlogs/api-v3.3bayti.ae.error.log
    CustomLog /www/wwwlogs/api-v3.3bayti.ae.access.log combined
</VirtualHost>
```

Enable modules and reload:
```bash
a2enmod rewrite headers proxy proxy_fcgi
apachectl graceful
```

---

### A15. Set directory ownership

```bash
chown -R www:www /www/wwwroot/3bayti
chmod -R 755 /www/wwwroot/3bayti
# uploads dir must be writable for image upload endpoint
mkdir -p /www/wwwroot/3bayti/apps/api/var/uploads
chown -R www:www /www/wwwroot/3bayti/apps/api/var
chmod -R 755 /www/wwwroot/3bayti/apps/api/var
# var/cache needs write for PHP-DI compiled container
mkdir -p /www/wwwroot/3bayti/apps/api/var/cache/di
chown -R www:www /www/wwwroot/3bayti/apps/api/var/cache
```

---

### A16. DNS — point domain to Contabo

In **Cloudflare DNS** for `3bayti.ae`:

| Type | Name | Content | Proxy |
|---|---|---|---|
| A | `api-v3` | `<SERVER_IP>` | 🟠 **Proxied** |

> **Orange cloud is required** for Cloudflare image transforms (Step B3) to work.
> Wait 2–5 minutes for DNS to propagate before continuing.

Test (should return the API, not a Cloudflare error):
```bash
curl -i http://api-v3.3bayti.ae/v3/health
# Expected: HTTP/1.1 200 {"status":"ok","db":"connected",...}
```

---

### A17. TLS via aaPanel Let's Encrypt

**Before enabling CF proxy orange cloud**, temporarily set the DNS record
to **gray cloud (DNS only)** so Let's Encrypt can reach the server directly
for HTTP-01 validation.

```bash
# In aaPanel: Website → api-v3.3bayti.ae → SSL → Let's Encrypt
# Domain: api-v3.3bayti.ae
# Click "Apply"
# Wait ~30 seconds for certificate issuance
```

After the cert is issued:
1. Switch the Cloudflare DNS record back to **orange cloud (proxied)**
2. In the Apache vhost (Step A14), uncomment the HTTP→HTTPS redirect lines
3. `apachectl graceful`

Test:
```bash
curl -i https://api-v3.3bayti.ae/v3/health
# Expected: HTTP/2 200 {"status":"ok","db":"connected",...}
```

> **Renewing:** aaPanel renews Let's Encrypt certs automatically every 60 days.
> No action needed.

---

### A18. Write the auto-deploy script

```bash
cat > /usr/local/bin/3bayti-deploy.sh << 'DEPLOY_EOF'
#!/usr/bin/env bash
set -euo pipefail

REPO="/www/wwwroot/3bayti"
LOG="/var/log/3bayti-deploy.log"
SHA=$(git -C "$REPO" rev-parse --short HEAD 2>/dev/null || echo "unknown")

echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) — deploy started (${SHA})" | tee -a "$LOG"
cd "$REPO"

# 1. Pull latest code
git pull --ff-only origin main 2>&1 | tee -a "$LOG"
SHA=$(git rev-parse --short HEAD)

# 2. Install/update Composer dependencies
cd apps/api
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader 2>&1 | tee -a "$LOG"

# 3. Clear PHP-DI compiled container cache
rm -rf var/cache/di/*
echo "  php-di cache cleared" | tee -a "$LOG"

# 4. Run migrations (idempotent — no-op if nothing new)
php bin/migrate.php 2>&1 | tee -a "$LOG"

# 5. Stamp the version in .env
if grep -q "^APP_VERSION=" .env; then
  sed -i "s/^APP_VERSION=.*/APP_VERSION=$SHA/" .env
else
  echo "APP_VERSION=$SHA" >> .env
fi

echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) — deploy complete (${SHA})" | tee -a "$LOG"
DEPLOY_EOF

chmod +x /usr/local/bin/3bayti-deploy.sh
```

Test:
```bash
/usr/local/bin/3bayti-deploy.sh
# Should run without errors, print "deploy complete"
```

---

### A19. Configure GitHub Actions secrets

In your GitHub repo → **Settings** → **Secrets and variables** → **Actions**:

#### Repository secrets

| Secret name | Value |
|---|---|
| `DEPLOY_HOST` | `<SERVER_IP>` (the Contabo VPS IP) |
| `DEPLOY_USER` | `root` |
| `DEPLOY_SSH_KEY` | Full contents of `/root/.ssh/3bayti_deploy` private key |
| `API_SMOKE_URL` | `https://api-v3.3bayti.ae` |
| `CLOUDFLARE_API_TOKEN` | See Part B below |
| `CLOUDFLARE_ACCOUNT_ID` | See Part B below |

#### Repository variables

| Variable name | Value |
|---|---|
| `ENABLE_AUTO_DEPLOY` | `true` |

After setting secrets, trigger a test deploy:
```bash
# Push any trivial change to main (e.g. update APP_VERSION in .env)
# Then watch: GitHub → Actions → "API — build & deploy" → deploy job
```

Expected: deploy job SSH's into the server, runs `3bayti-deploy.sh`, and
the smoke test hits `https://api-v3.3bayti.ae/v3/health` → 200.

---

### A20. Set up scheduled cron jobs

In aaPanel → **Cron**:

| Job | Command | Schedule |
|---|---|---|
| Gift card expiry nudge | `php /www/wwwroot/3bayti/apps/api/bin/gift-card-expiry-nudge.php` | `0 4 * * *` (04:00 UTC = 08:00 UAE) |
| pg_dump backup | `pg_dump -U bayti_v3 -h 127.0.0.1 bayti_v3 | gzip > /backups/bayti_v3_$(date +%Y%m%d_%H%M).sql.gz` | Every 6 hours |

Create the backups directory:
```bash
mkdir -p /backups
chown root:root /backups
chmod 700 /backups
```

---

## Part B — Cloudflare setup

### B1. Create a Cloudflare API token

Go to [dash.cloudflare.com](https://dash.cloudflare.com) → My Profile → API Tokens → **Create Token**.

Use the **Edit Cloudflare Workers** template, then customise:

| Permission | Resource |
|---|---|
| Account — Workers Scripts: Edit | Your account |
| Account — Workers KV Storage: Edit | Your account |
| Zone — Workers Routes: Edit | `3bayti.ae` |
| Zone — DNS: Edit | `3bayti.ae` |

Copy the token — paste it into GitHub secret `CLOUDFLARE_API_TOKEN`.

Your **Account ID** is visible in the Cloudflare dashboard sidebar — paste it
into GitHub secret `CLOUDFLARE_ACCOUNT_ID`.

---

### B2. Deploy the web storefront (Workers)

The CI/CD pipeline deploys `apps/web` automatically on every push to `main`
via `wrangler deploy`. The first deploy must be done manually once:

```bash
# On your LOCAL machine (not the server) — from the repo root:
cd apps/web

# Build
pnpm run build

# Deploy (requires CLOUDFLARE_API_TOKEN in your local env, or run wrangler login)
pnpm exec wrangler deploy
```

**Set the custom domain** (one-time):

In Cloudflare Workers → `3bayti-web` → **Settings** → **Domains & Routes**:
- Add custom domain: `3bayti.ae`
- Also add: `www.3bayti.ae` → redirect to `3bayti.ae`

From this point forward every push to `main` auto-deploys via GitHub Actions.

---

### B3. Deploy the vendor portal (Cloudflare Pages)

```bash
# On your LOCAL machine:
cd apps/portal

# Build
pnpm run build

# Deploy to Cloudflare Pages
pnpm exec wrangler pages deploy dist/portal --project-name 3bayti-portal
```

**Custom domain (one-time):**

Cloudflare Pages → `3bayti-portal` → **Custom domains** → Add:
- `app.3bayti.ae`

---

### B4. Enable Cloudflare Image Resizing

Image transforms (`/cdn-cgi/image/...`) require the Image Resizing feature.

Cloudflare dashboard → `3bayti.ae` zone → **Speed** → **Optimization** →
**Image Resizing** → **Enable**.

This is on the **Pro plan** or above. If on the free plan, image transforms
will fail silently (images still load at full size — just not resized).

Verify it works after images are migrated (Part C):
```bash
curl -I "https://api-v3.3bayti.ae/cdn-cgi/image/width=400,quality=80,format=auto/https://api-v3.3bayti.ae/uploads/products/test/test.jpg" \
  -H "Accept: image/avif,image/webp,*/*"
# Expected: content-type: image/webp, cf-cache-status: MISS (first hit)
```

---

## Part C — Data migration

### C1. Legacy user/vendor/product delta sync

The initial migration (M3.1) captured a snapshot. Run the delta sync to pick
up new users, vendors, and products created since then.

**Add MySQL credentials** to `.env` on the server:
```ini
LEGACY_MYSQL_HOST=<old-server-ip>
LEGACY_MYSQL_PORT=3306
LEGACY_MYSQL_USER=migrate_reader
LEGACY_MYSQL_PASS=<read-only password>
LEGACY_MYSQL_DB=<legacy db name>
```

Create a read-only MySQL user on the old server if needed:
```sql
CREATE USER 'migrate_reader'@'<contabo-server-ip>' IDENTIFIED BY 'strong-password';
GRANT SELECT ON `legacy_db`.* TO 'migrate_reader'@'<contabo-server-ip>';
FLUSH PRIVILEGES;
```

Run the delta sync:
```bash
cd /www/wwwroot/3bayti/apps/api
php bin/migrate-from-legacy/migrate-all.php 2>&1 | tee /tmp/delta-$(date +%Y%m%d).log
```

### C2. Migrate order history

```bash
php bin/migrate-from-legacy/migrate-all.php --include-orders \
  2>&1 | tee /tmp/orders-$(date +%Y%m%d).log
```

See `docs/deploy/delta-and-orders-migration.md` for the full migration
verification checklist (7 steps including status mapping verification).

### C3. Migrate product images

```bash
cd /www/wwwroot/3bayti/apps/api

# Dry run first
php bin/migrate-from-legacy/migrate-images.php --dry-run

# Option A: HTTP fetch from old server
php bin/migrate-from-legacy/migrate-images.php \
  2>&1 | tee /tmp/images-$(date +%Y%m%d).log

# Option B: Direct disk copy (faster — rsync first, then:)
rsync -avz root@<old-server>:/www/wwwroot/legacy/vendors/products/ /tmp/legacy-images/
php bin/migrate-from-legacy/migrate-images.php \
  --ssh-copy=/tmp/legacy-images \
  2>&1 | tee /tmp/images-$(date +%Y%m%d).log
```

Verify:
```bash
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT COUNT(*) AS legacy_urls_remaining
  FROM products
  WHERE primary_image_url LIKE 'https://api.3bayti.ae/%';
"
# Target: 0
```

---

## Part D — DNS cutover

**Run Part C (data migration) before cutting DNS.** The final delta sync
should happen immediately before cutover to minimise the data gap.

### D1. Final pre-cutover sync

Run during low-traffic hours (02:00–04:00 UAE time):
```bash
php bin/migrate-from-legacy/migrate-all.php --include-orders
```

Any orders placed between this sync and DNS propagation (<5 min with TTL=1)
need manual transfer. Note the last legacy order ID before cutting over.

### D2. DNS TTL — lower before cutover

24 hours before cutover, in Cloudflare DNS:
- Change the **old** `api.3bayti.ae` A record TTL to **1 minute**
- Change the **old** `3bayti.ae` A/CNAME records TTL to **1 minute**

### D3. Cut DNS

In Cloudflare DNS:
1. Update `api-v3.3bayti.ae` → `<SERVER_IP>` (proxied) — **already done in A16**
2. Point `3bayti.ae` and `www.3bayti.ae` → Cloudflare Workers (already done in B2)
3. Point `app.3bayti.ae` → Cloudflare Pages (already done in B3)

### D4. Verify after cutover

```bash
# API health
curl https://api-v3.3bayti.ae/v3/health
# {"status":"ok","db":"connected",...}

# Web storefront loads
curl -I https://3bayti.ae
# HTTP/2 200

# Portal loads
curl -I https://app.3bayti.ae
# HTTP/2 200

# CORS from mobile origin
curl -I -X OPTIONS https://api-v3.3bayti.ae/v3/products \
  -H "Origin: http://localhost:8100" \
  -H "Access-Control-Request-Method: GET"
# Access-Control-Allow-Origin: http://localhost:8100
```

---

## Part E — Post-deployment checklist

Run through every item before declaring the deployment complete.

### E1. API

- [ ] `https://api-v3.3bayti.ae/v3/health` → `{"status":"ok","db":"connected"}`
- [ ] `https://api-v3.3bayti.ae/v3/health/ready` → all checks pass
- [ ] TLS cert valid (check expiry): `echo | openssl s_client -connect api-v3.3bayti.ae:443 2>/dev/null | openssl x509 -noout -dates`
- [ ] `GET /v3/products?limit=5` returns products (data migration succeeded)
- [ ] `GET /v3/gift-cards/themes` returns 6 themes
- [ ] Noon webhook URL configured: `https://api-v3.3bayti.ae/v3/checkout/webhook/noon`

### E2. Cloudflare

- [ ] `api-v3.3bayti.ae` DNS is proxied (orange cloud)
- [ ] Image Resizing enabled (Cloudflare Speed → Optimization)
- [ ] `CF-Cache-Status: HIT` on second request to any `/uploads/` image
- [ ] CF image transform returns `image/webp` not `image/jpeg`

### E3. GitHub Actions

- [ ] Push to `main` → API deploy job runs and passes
- [ ] Push to `main` → Web deploy job runs and passes (Workers updated)
- [ ] Smoke test in deploy job: `curl https://api-v3.3bayti.ae/v3/health` → 200

### E4. Mobile app

- [ ] Login with a test account works (JWT issued)
- [ ] Product catalog loads (images served from new CDN)
- [ ] Cart + checkout flow completes (Noon payment gateway live)
- [ ] Gift card purchase: create → pay → activated in wallet
- [ ] Push notification received on test device (FCM working)

### E5. Security

- [ ] Redis bound to `127.0.0.1` only: `grep "^bind" /etc/redis/redis.conf`
- [ ] PostgreSQL not accessible from internet: `pg_hba.conf` has only local/127.0.0.1 entries
- [ ] aaPanel port 8888 restricted to your IP only (Step A2)
- [ ] `.env` not web-accessible: `curl https://api-v3.3bayti.ae/.env` → 403/404
- [ ] `var/` not web-accessible: `curl https://api-v3.3bayti.ae/var/` → 403/404

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `curl: (6) Could not resolve host` | DNS not propagated | Wait 5 min; check Cloudflare DNS |
| HTTP 500 empty body | `open_basedir` blocking | Step A12 — check `.user.ini` |
| HTTP 401 on all requests | Authorization header stripped by Apache | Step A14 — `SetEnvIf Authorization` line |
| `No provider found` PHP-DI error | Compiled cache stale | `rm -rf var/cache/di/*` |
| `proc_open` disabled | PHP CLI `disable_functions` | Step A11 |
| `pdo_pgsql not found` | Extension not installed | aaPanel → PHP 8.3 → Extensions |
| Migration fails: connection refused | PostgreSQL not listening | `systemctl status postgresql` |
| Image transforms return JPEG | Orange cloud not enabled | Cloudflare DNS → set proxy to orange |
| Image transforms 530 error | Image Resizing not enabled | Cloudflare Speed → Optimization |
| `POST /v3/upload` 403 | `open_basedir` blocks `var/uploads/` | Confirm `.user.ini` covers `apps/api/` |
| Gift card payment fails | Noon LIVE keys not set | Check `NOON_API_BASE` = `api.noonpayments.com` (no `-test`) |
| Push notifications not delivered | FCM private key formatting | Ensure `\n` in `FCM_PRIVATE_KEY` are literal newlines, not escaped |
| Auto-deploy not triggering | `ENABLE_AUTO_DEPLOY` not set | GitHub → Settings → Variables → set to `true` |
| Workers deploy fails | `CLOUDFLARE_API_TOKEN` permissions | Ensure token has Workers Scripts: Edit |
