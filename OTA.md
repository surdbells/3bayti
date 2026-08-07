# OTA (over-the-air) mobile updates

3bayti ships JavaScript/CSS updates to the installed mobile app **without an
app-store release**, using `@capgo/capacitor-updater` pointed at **our own
server** (no Capgo Cloud, no external CDN). Bundles are managed from the **portal**
— no shell.

> **OTA ships web assets only.** Anything that needs new native code, a Capacitor
> plugin, or a new permission must go through the app store with a native
> version bump. The `min_native_version` gate keeps an incompatible bundle off
> older shells (see [Compatibility](#compatibility--rollback)).

---

## How it works

```
 App (@capgo/capacitor-updater, autoUpdate)
   │  on resume/cold start → POST /v3/ota/updates
   │     { platform, app_id, version_name(current bundle), version_build(native) }
   ▼
 API  POST /v3/ota/updates  → newest ACTIVE ota_bundles row for {app,platform,channel}
   │                          with min_native ≤ device build, version > device bundle
   │  ← { version, url, checksum[, session_key] }   or   { version:"" }  (no update)
   ▼
 App downloads the .zip from `url` (served statically), verifies checksum
   (+ decrypts with the embedded public key if signed), applies on next cold
   start, calls notifyAppReady() within 10s or auto-rolls back.
```

- **Hosting:** the `.zip` lives on the app server at
  `apps/api/var/uploads/ota/<platform>/<version>.zip`, which Apache serves
  statically at `https://api-v3.3bayti.ae/uploads/ota/…` — downloads never
  stream through PHP.
- **Config:** `apps/mobile/capacitor.config.ts` → `CapacitorUpdater.updateUrl =
  https://api-v3.3bayti.ae/v3/ota/updates` (`autoUpdate`, `directUpdate:false`,
  `resetWhenUpdate:true`, `appReadyTimeout:10000`).

---

## One-time production setup

1. **Enable signing** (required before opening production OTA — see
   [OTA-SIGNING.md](apps/mobile/OTA-SIGNING.md)):
   ```bash
   cd apps/mobile && npx @capgo/cli key create
   ```
   Writes the **public** key into `capacitor.config.ts`; keep `capgo_key`
   (private) secret — it's git-ignored.
2. **Ship one store build** carrying the OTA plugin + public key. Only devices on
   that build (or later) can receive OTA. This is the bootstrap — you can't OTA
   the OTA capability in.

---

## Releasing an update

Every release is JS-only. Two equivalent paths:

### Portal (no shell)
Portal → **OTA updates** (`/ota`, needs `settings.edit`):
1. Build + zip the web bundle:
   ```bash
   cd apps/mobile && npm run build
   npx @capgo/cli bundle zip com.threebayti.app --path www --json
   # signed: also run `npx @capgo/cli bundle encrypt <zip> <sha256>` → ivSessionKey
   #         (= Session key); the Checksum to publish is the plain <sha256> above
   ```
2. Upload the `.zip`, set platform / version / min-native. For a **signed**
   bundle also paste the **Session key** + **Checksum** from `encrypt`
   (unsigned: leave both empty — the server computes SHA256).

### CLI / CI (one command)
```bash
cd apps/mobile
# unsigned
npm run ota:release -- --platform android --version 1.0.7 --min-native 1.6.0
# signed (runs encrypt for you)
npm run ota:release -- --platform ios --version 1.0.7 --sign
```
Auth via `OTA_ADMIN_TOKEN`, or `OTA_ADMIN_EMAIL` + `OTA_ADMIN_PASSWORD`. Flags:
`--channel`, `--app-id`, `--www`, `--skip-build`, `--dry-run`, and
`--session-key`/`--checksum` for a pre-encrypted bundle. See the header of
[`scripts/ota-release.mjs`](apps/mobile/scripts/ota-release.mjs).

> There's also a server-side `bin/console ota:publish …` for registering a
> bundle whose `.zip` you placed/hosted yourself.

Devices pick up the newest **active** bundle on their next resume and apply it on
the next cold start.

---

## Compatibility & rollback

- **`min_native_version`** — set it to the native build that introduced any
  capability the bundle relies on. A device on an older native shell is served
  *no* update rather than a bundle that would crash.
- **Rollback** — in the portal, **Deactivate** (or delete) the bad bundle; the
  endpoint then serves the previous active bundle for that platform/channel. Or:
  ```sql
  UPDATE ota_bundles SET is_active = false WHERE id = <id>;
  ```
- **Auto-rollback** — a bundle that fails to boot (no `notifyAppReady()` within
  10s) reverts on-device automatically.
- **Store updates win** — a new native release drops the OTA bundle
  (`resetWhenUpdate` + Capacitor's `isNewBinary`) so the fresh builtin takes over.

---

## Security

- Always: HTTPS, SHA256 verification, admin-gated endpoints (`settings.edit`),
  locked-down `var/uploads/ota`.
- **Signing (do it for production):** end-to-end encryption / code signing so a
  spoofed endpoint or a compromised file store can't push malicious JS. Full
  workflow in **[apps/mobile/OTA-SIGNING.md](apps/mobile/OTA-SIGNING.md)**. The
  private key must never be committed or shipped.

---

## Where the pieces live

| Piece | Location |
|---|---|
| Client plugin + config | `apps/mobile` (`@capgo/capacitor-updater`, `capacitor.config.ts`, `notifyAppReady()` in `app.component.ts`) |
| Update endpoint (public) | `POST /v3/ota/updates` — `apps/api/src/Http/Controllers/Ota/CheckOtaUpdateController.php` |
| Bundle registry | `ota_bundles` table + `apps/api/src/Domain/Ota/*` |
| Server storage | `OtaBundleStorageService` → `var/uploads/ota/…` (served at `/uploads/ota`) |
| Admin API | `/v3/admin/ota/bundles` (list/upload/activate/delete) — `apps/api/src/Http/Controllers/Admin/Ota/*` |
| Portal admin UI | `apps/portal` `/ota` (`backend/ota/`, `services/ota-admin.service.ts`) |
| Publish CLI | `apps/api/bin/console ota:publish` |
| Release script | `apps/mobile/scripts/ota-release.mjs` (`npm run ota:release`) |
| Signing runbook | `apps/mobile/OTA-SIGNING.md` |

## Deploy notes

Full step-by-step rollout (phases, verification, rollback): **[OTA-DEPLOY.md](OTA-DEPLOY.md)**.

- **API** changes deploy with the usual steps (pull → `rm -rf var/cache/di/*` →
  `orm:generate-proxies` → `chown -R www:www var/` → reload php-fpm). Run the
  migration: `bin/console migrations:migrate -n`.
- **Portal** deploys via its Cloudflare Pages build.
- **Mobile** client changes (enabling/upgrading OTA, `key create`) are native →
  `pnpm install` + `npx cap sync` + a store build.
- Ensure `var/uploads/ota/` is included in the server backup window.
