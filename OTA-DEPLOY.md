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
Expect `{"message":"No update","version":"","url":""}`.
- [ ] `var/uploads/` writable by `www` (already true for images; the `ota/` subdir
  is created on first upload). Apache already serves `/uploads` — **no web-server
  change**.

## Phase 2 — Portal (Cloudflare Pages) · `32db991` `7140eb0`
- [ ] Trigger/confirm the Pages build for `apps/portal` (Root `apps/portal`, build
  `pnpm install && pnpm run build`, output `dist/abayti/browser`).
- [ ] Verify: sign in as a `settings.edit` admin → sidebar **OTA updates** →
  `/ota` loads with an empty list + upload form.

## Phase 3 — Signing setup (one-time, before the first production store build) · [OTA-SIGNING.md](apps/mobile/OTA-SIGNING.md)
Required before opening **production** OTA (unsigned OTA on a checkout app is a
supply-chain risk).

- [ ] Generate keys (writes the public key into `capacitor.config.ts`, creates
  `capgo_key` + `capgo_key.pub`):
```bash
cd apps/mobile && npx @capgo/cli key create
```
- [ ] Commit the `capacitor.config.ts` change. **Never commit `capgo_key`** (git-
  ignored) — store it in your secrets manager.

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
  npx @capgo/cli@latest bundle zip com.threebayti.app --path www --json      # -> plain sha256
  npx @capgo/cli@latest bundle encrypt <zip> <plain-sha256>                  # -> ivSessionKey + encrypted checksum
  # then Portal → OTA updates → upload the encrypted .zip, platform/version/min-native,
  #   Session key = ivSessionKey, Checksum = the encrypted checksum
  ```
  (Scripted CI alternative, not the standard path: `OTA_ADMIN_TOKEN=<token> npm run ota:release -- --platform android --version <bundleVersion> --min-native <shipped native version> --sign`.)
- [ ] Confirm the endpoint serves it to an older bundle:
```bash
curl -s -X POST https://api-v3.3bayti.ae/v3/ota/updates -H 'Content-Type: application/json' -d '{"platform":"android","app_id":"com.threebayti.app","version_name":"0.0.1","version_build":"<shipped native version>"}'
```
Expect `{"version":"<bundleVersion>","url":"https://api-v3.3bayti.ae/uploads/ota/android/<bundleVersion>.zip","checksum":"…"[,"session_key":"…"]}`.
- [ ] On a test device on the new store build: background → foreground → relaunch;
  confirm it picks up the bundle (signed: decrypts + boots, no rollback).

## Ongoing — releasing an update
JS/CSS-only, no store round-trip. **Upload through the portal** every time:
build → `bundle zip` → `bundle encrypt` → Portal **OTA updates** (upload the
encrypted `.zip` + Session key + Checksum). Bump the version each release
(`1.6.2`, `1.6.3`, …) and repeat per platform (android + ios). Full steps:
[OTA.md → Releasing an update](OTA.md#releasing-an-update). Native
code/permissions still require a new store build + a `min_native` bump.

(CI alternative, not the standard path: `npm run ota:release … --sign`.)

## Rollback
- Portal → **Deactivate** the bad bundle (serves the previous active one), or:
```bash
/www/server/php/83/bin/php /www/wwwroot/3bayti/apps/api/bin/console dbal:run-sql "UPDATE ota_bundles SET is_active = false WHERE id = <id>"
```
- A bundle that fails to boot auto-reverts on-device (10s `appReadyTimeout`). A
  new store release always supersedes OTA.
