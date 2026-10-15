# OTA system — deploy checklist

Full rollout of the self-hosted OTA system. See [OTA.md](OTA.md) for how it works
and [apps/mobile/OTA-SIGNING.md](apps/mobile/OTA-SIGNING.md) for signing.

**Commits:** `c4c5375` (endpoint+migration), `483da17` (publish CLI), `6f3db32`
(client re-enable + revert anti-OTA safeguard), `d38c13b` (server hosting + admin
API), `32db991` (portal UI), `7140eb0` (signing), `cdcd659` (release script),
`5ccfb6f`/this (docs). No new Composer packages; the mobile plugin is already in
the lockfile.

**Order matters:** API → Portal → (signing setup) → Mobile store build → first
bundle. Do **not** publish a bundle until the API and a store build carrying the
plugin are live.

Server facts: app root `/www/wwwroot/3bayti`, PHP CLI `/www/server/php/83/bin/php`,
reload `/etc/init.d/php-fpm-83 reload`, API host `https://api-v3.3bayti.ae`.

---

## 0. Pre-flight
- [ ] Everything pushed; deploy host on the same commit:
```bash
git log --oneline -1
```

## Phase 1 — API (server) · `c4c5375` `483da17` `d38c13b` `7140eb0`
Creates `ota_bundles`, the public `/v3/ota/updates` endpoint, admin CRUD, and
server-side storage. New entity + controllers (regenerate proxies/DI) and a new
table (run the migration).

- [ ] **Pre-deploy check (OTA hardening pass), BEFORE pulling.** The endpoint
  now serves the **highest-version** active bundle per app/platform/channel
  (it used to serve the newest by `created_at`), and this applies to the rows
  already in the table the moment the new code is live. List the active rows:
```bash
/www/server/php/83/bin/php /www/wwwroot/3bayti/apps/api/bin/console dbal:run-sql "SELECT id, app_id, platform, channel, version, min_native_version, (session_key IS NOT NULL) AS signed, created_at FROM ota_bundles WHERE is_active ORDER BY app_id, platform, channel, created_at"
```
  For each app/platform/channel group:
  - the row with the numerically **highest** version (among rows sharing a
    min-native) must be the one devices should be on, normally the newest
    `created_at`. If a stale row has a HIGHER version than the current release
    (e.g. an old row re-activated), **deactivate it first** in the portal,
    otherwise every device below it downloads it right after deploy and stays
    pinned to it;
  - any active **ios** row with `signed = false` can never install (the iOS
    plugin requires an RSA-encrypted checksum because the app embeds a public
    key): deactivate it; devices keep failing to download it otherwise.

- [ ] Pull, migrate, rebuild caches/proxies, reload:
```bash
cd /www/wwwroot/3bayti/apps/api && git pull && rm -rf var/cache/di/* && /www/server/php/83/bin/php bin/console migrations:migrate -n && /www/server/php/83/bin/php bin/console orm:clear-cache:metadata && /www/server/php/83/bin/php bin/console orm:clear-cache:query && /www/server/php/83/bin/php bin/console orm:generate-proxies && chown -R www:www var/ && /etc/init.d/php-fpm-83 reload
```
- [ ] Confirm the table exists (`dbal:run-sql` — verified command name):
```bash
/www/server/php/83/bin/php /www/wwwroot/3bayti/apps/api/bin/console dbal:run-sql "SELECT count(*) FROM ota_bundles"
```
- [ ] Verify the endpoint is live (nothing published yet → no update):
```bash
curl -s -X POST https://api-v3.3bayti.ae/v3/ota/updates -H 'Content-Type: application/json' -d '{"platform":"android","app_id":"com.threebayti.app","version_name":"0.0.1","version_build":"1.6.0"}'
```
Expect `{"kind":"up_to_date","error":"no_new_version_available","message":"No update","version":"","url":""}`
(the `kind` tells the plugin this is "up to date", not a failed download).
- [ ] `var/uploads/` writable by `www` (already true for images; the `ota/` subdir
  is created on first upload). Apache already serves `/uploads` — no new route
  needed, but see the body-size limits below.
- [ ] Raise the upload limits (see [Upload limits](#upload-limits)), else bundle
  uploads fail with "Bundle too large" / a 413.

### Upload limits
Bundles are uploaded through PHP (`POST /v3/admin/ota/bundles`) and capped at
**50 MB** by `UploadOtaBundleController::MAX_BYTES` (today's bundle is ~15 MB).
Every layer in front of it must allow at least that:

| Layer | Setting | Value |
|---|---|---|
| PHP (aaPanel → PHP 8.3 → Configuration, or `/www/server/php/83/etc/php.ini`) | `upload_max_filesize` | `50M` |
| | `post_max_size` | `52M` (multipart headroom) |
| | `memory_limit` | ≥ `256M` (the bundle is read into memory once) |
| Apache vhost | `LimitRequestBody` | ≥ `54525952` (52 MB) **only** for the OTA upload route, see below |
| Docker image | `apps/api/docker/php.ini` + `location = /v3/admin/ota/bundles` in `docker/default.conf` | already `50M`/`52M`/`52m` (52m on that route only) |

**Keep the large body limit scoped to the upload route.** PHP's
`post_max_size` is global, and unauthenticated JSON endpoints (e.g.
`POST /v3/ota/updates`, login) decode their whole body, which costs several
times its size in memory: a 52 MB body there can exhaust a PHP-FPM worker. So
cap request bodies at the web server everywhere else. In the aaPanel site's
Apache config (Site → Config file), for example:
```apache
# Server-wide: the largest NON-OTA body you accept (check other upload
# endpoints first; 10 MB shown).
LimitRequestBody 10485760
<Location "/v3/admin/ota/bundles">
    LimitRequestBody 54525952
</Location>
```
(The docker nginx config already does this: the 1m default everywhere, 52m on
`location = /v3/admin/ota/bundles`.)

After changing PHP settings: `/etc/init.d/php-fpm-83 reload`. Check with
`/www/server/php/83/bin/php -i | grep -E 'upload_max_filesize|post_max_size'`
(the CLI reads the same php.ini in aaPanel). A body over `post_max_size` is
dropped by PHP before the app runs; the API then names the PHP setting to
raise ("Upload exceeds the server's PHP limit (post_max_size=8M)…"), whereas
"Bundle too large (max 50 MB)" means the bundle itself is over the cap.

## Phase 2 — Portal (Cloudflare Pages) · `32db991` `7140eb0`
- [ ] Trigger/confirm the Pages build for `apps/portal` (Root `apps/portal`, build
  `pnpm install && pnpm run build`, output `dist/abayti/browser`).
- [ ] Verify: sign in as a `settings.edit` admin → sidebar **OTA updates** →
  `/ota` loads with an empty list + upload form.

## Phase 3 — Signing setup (one-time, before the first production store build) · [OTA-SIGNING.md](apps/mobile/OTA-SIGNING.md)
Required before opening **production** OTA (unsigned OTA on a checkout app is a
supply-chain risk).

- [ ] Generate keys (writes the public key into `capacitor.config.ts`, creates
  `.capgo_key_v2` + `.capgo_key_v2.pub`). Already done for the shipped shells
  (`2e13bc2d`); only re-run to rotate the key (then ship a new store build):
```bash
cd apps/mobile && npx @capgo/cli@8.68.1 key create
```
- [ ] Commit the `capacitor.config.ts` change. **Never commit `.capgo_key_v2`**
  (git-ignored) — store it in your secrets manager.

## Phase 4 — Mobile store build · `6f3db32` `7140eb0`
Ships the OTA-capable shell (plugin + `notifyAppReady()` + embedded public key).
Only devices on this build (or later) can receive OTA — the bootstrap.

- [ ] Install (adds `@capgo/capacitor-updater`, applies the inappbrowser patch) +
  sync native:
```bash
cd apps/mobile && pnpm install && npx cap sync
```
- [ ] Bump the native version (Android `versionCode`/`versionName`; iOS
  `MARKETING_VERSION`/`CURRENT_PROJECT_VERSION`).
- [ ] Build & submit: Android signed AAB → Play Console; iOS (Mac) Archive → App
  Store Connect. Note the shipped `versionName` — it's the `--min-native` for any
  bundle needing this shell.

## Phase 5 — First bundle + end-to-end verify
Only after Phases 1–2 are live and a Phase-4 build is on a test device.

- [ ] Publish a bundle **through the portal** (the standard path): build + zip +
  encrypt locally, then upload the encrypted `.zip` in **OTA updates** with the
  Session key + Checksum. Full steps in [OTA.md → Releasing an update](OTA.md#releasing-an-update).
  ```bash
  cd apps/mobile && npm run build
  npx @capgo/cli@8.68.1 bundle zip com.threebayti.app --path www --name com.threebayti.app_<bundleVersion>.zip --json
  #   -> { "bundle": "<pkg version>", "filename": "<zip>", "checksum": "<plain sha256>" }
  npx @capgo/cli@8.68.1 bundle encrypt <filename> <plain-sha256>             # -> ivSessionKey + encrypted checksum
  # then Portal → OTA updates → upload the <filename>_encrypted.zip, platform/version/min-native,
  #   Session key = ivSessionKey, Checksum = the encrypted checksum
  ```
  (Scripted CI alternative, not the standard path, signs by default: `OTA_ADMIN_TOKEN=<token> npm run ota:release -- --platform android --version <bundleVersion> --min-native <shipped native version>`.)
- [ ] Confirm the endpoint serves it to an older bundle:
```bash
curl -s -X POST https://api-v3.3bayti.ae/v3/ota/updates -H 'Content-Type: application/json' -d '{"platform":"android","app_id":"com.threebayti.app","version_name":"0.0.1","version_build":"<shipped native version>"}'
```
Expect `{"version":"<bundleVersion>","url":"https://api-v3.3bayti.ae/uploads/ota/com.threebayti.app/production/android/<bundleVersion>.zip","checksum":"…","session_key":"…"[,"comment":"<notes>","notes":"<notes>"]}`
(no `message` key: the plugin rejects an update response that has one).
- [ ] On a test device on the new store build: background → foreground → relaunch;
  confirm it picks up the bundle (signed: decrypts + boots, no rollback).

## Ongoing — releasing an update
JS/CSS-only, no store round-trip. **Upload through the portal** every time:
build → `bundle zip` → `bundle encrypt` → Portal **OTA updates** (upload the
`*_encrypted.zip` + Session key + Checksum). Bump the version each release
(`1.6.2`, `1.6.3`, …; numeric only, no `-beta` suffixes) and publish to
both platforms (platform **Both**, or one upload each). Full steps:
[OTA.md → Releasing an update](OTA.md#releasing-an-update). Native
code/permissions still require a new store build + a `min_native` bump.

(CI alternative, not the standard path: `npm run ota:release -- …`, signed by default.)

## Rollback
- Portal → OTA updates → **Roll back to this** on the last good bundle (per
  platform). It re-publishes that file under the next version and deactivates
  the rest, so devices already on the bad bundle move back. See
  [OTA.md → Compatibility & rollback](OTA.md#compatibility--rollback).
- **Deactivate** alone only stops devices that have not downloaded the bad
  bundle yet; it does not move installed devices back.
- A bundle that fails to boot auto-reverts on-device (10s `appReadyTimeout`). A
  new store release always supersedes OTA.

## Follow-ups (need a native store build or code outside the OTA module)
- **`autoDeleteFailed: false`** in `capacitor.config.ts` → `CapacitorUpdater`
  (native config, takes effect with the next store build). The app already
  refuses to offer or auto-restart into a version that failed to boot, but with
  the plugin default (`true`) a failed bundle is deleted rather than kept in
  ERROR state, so the plugin's own background path can re-download it and
  install it on the next background (one 10s crash + rollback per cycle).
  Keeping it in ERROR makes the plugin skip it too.
- **Payment flows outside checkout** (gift-card purchase, bespoke
  customization payment, pending-order "Complete payment") open the Noon
  payment web view without `CheckoutActivityService.begin()`, so the update
  sheet's countdown and the background-install hold don't cover them. Wrap each
  payment web view + its status poll in `begin()`/`end()`.
- The checkout page releases its guard only in `ngOnDestroy`, which Ionic
  doesn't run on forward navigation (checkout → /process → /success); release
  it when the payment window actually ends.
