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
 API  POST /v3/ota/updates  → HIGHEST-VERSION ACTIVE ota_bundles row for {app,platform,channel}
   │                          with min_native ≤ device build, served only if version > device bundle
   │                          (no/invalid version_build → no update: the native gate fails closed)
   │  ← { version, url, checksum, session_key[, comment(notes)] }
   │     or { kind:"up_to_date", error:"no_new_version_available", version:"", url:"" }
   ▼
 App downloads the .zip from `url` (served statically), decrypts it + verifies
   the signed checksum with the embedded public key, then the update sheet
   restarts into it (tap or 6s countdown). The plugin also installs a staged
   bundle whenever the app is backgrounded; during a checkout both are held
   back (setMultiDelay). notifyAppReady() within 10s or it auto-rolls back.
```

- **Plugin contract** (`@capgo/capacitor-updater` 8.51.3, in every shipped
  shell): release notes travel as `comment` (+ `notes`), never `message` (the
  plugin's `getLatest()` rejects an update response carrying one), and "no
  update" carries `kind: "up_to_date"` (without it every check counts as a
  failed download).

- **Hosting:** the `.zip` lives on the app server at
  `apps/api/var/uploads/ota/<app_id>/<channel>/<platform>/<version>.zip`, which
  Apache serves statically at `https://api-v3.3bayti.ae/uploads/ota/…` —
  downloads never stream through PHP. The path carries every column of the
  bundle's unique key, so the same version on two channels/app ids can't
  overwrite each other. Bundles uploaded before this layout stay at
  `ota/<platform>/<version>.zip`; each row stores its own URL, so they keep
  working with no migration.
- **Selection:** among active bundles whose `min_native_version` the device
  satisfies, the **highest version** wins (not the most recently uploaded), so a
  late-registered lower version or a re-activated old row can never shadow a
  newer release.
- **Versions** are numeric only, 1–4 canonical segments (`1.7`, `1.7.1`,
  `1.0.0.4`; no leading zeros like `1.08`, at most 9 digits each).
  Pre-release suffixes (`1.7.1-beta`) are rejected by the portal, the API,
  `ota:publish` and the release script; stage pre-releases on a separate
  channel instead.
- **Signed only** (except android dev channels): every OTA-capable store build
  embeds the public key, and with a key set the iOS plugin rejects any bundle
  without an RSA-encrypted checksum. So the API, portal, `ota:publish` and the
  release script refuse unsigned bundles for iOS/both and for `production`;
  an unsigned plain `.zip` is accepted only for android on a non-production
  channel. Signed uploads must be the `*_encrypted.zip` (the plain zip is
  refused), with the 512-hex encrypted checksum and the ivSessionKey.
- **Upload size:** max **50 MB** per bundle (today's is ~15 MB). PHP and the web
  server must allow it, see [OTA-DEPLOY.md → Upload limits](OTA-DEPLOY.md#upload-limits).
- **Config:** `apps/mobile/capacitor.config.ts` → `CapacitorUpdater.updateUrl =
  https://api-v3.3bayti.ae/v3/ota/updates` (`autoUpdate`, `directUpdate:false`,
  `resetWhenUpdate:true`, `appReadyTimeout:10000`).

---

## One-time production setup

1. **Enable signing** (required before opening production OTA — see
   [OTA-SIGNING.md](apps/mobile/OTA-SIGNING.md)):
   ```bash
   cd apps/mobile && npx @capgo/cli@8.68.1 key create
   ```
   Writes the **public** key into `capacitor.config.ts`; keep `.capgo_key_v2`
   (private) secret — it's git-ignored. (Already done: the shipped shells
   carry the key since `2e13bc2d`.)
2. **Ship one store build** carrying the OTA plugin + public key. Only devices on
   that build (or later) can receive OTA. This is the bootstrap — you can't OTA
   the OTA capability in.

---

## Releasing an update

Every release is JS-only, and **always uploaded through the portal** — build +
zip + encrypt locally, then upload the encrypted bundle in the admin UI. (No
scripted auto-upload; see the footnote for the CI alternative.)

1. **Build** the web bundle:
   ```bash
   cd apps/mobile && npm run build          # -> www
   ```
2. **Zip** it and note the plain SHA256 (use this exact CLI version, it reads
   the private key and its output is what the docs/script expect):
   ```bash
   npx @capgo/cli@8.68.1 bundle zip com.threebayti.app --path www --name com.threebayti.app_<version>.zip --json
   #   -> { "bundle": "<package.json version>", "filename": "<zip>", "checksum": "<plain sha256>" }
   ```
   The zip is **`filename`** (`bundle` is just a version label, not a path).
3. **Encrypt** it. It writes a NEW file, `<zip>_encrypted.zip` (the original zip
   stays plain), and prints two values:
   ```bash
   npx @capgo/cli@8.68.1 bundle encrypt <filename> <plain-sha256>
   #   -> ivSessionKey        (= Session key)
   #   -> encrypted checksum  (= Checksum to publish — NOT the plain sha256)
   ```
4. **Upload** in the portal → **OTA updates** (`/ota`, needs `settings.edit`):
   upload the **`*_encrypted.zip`** (the form and API refuse the plain zip
   for a signed bundle); set platform (android, ios or both) / version (must
   exceed the device's current bundle, e.g. `1.6.1` over builtin `1.6`) /
   min-native; paste **Session key** = ivSessionKey and **Checksum** = the
   encrypted checksum (exactly 512 hex; the form and API reject the 64-hex
   plain sha256 and truncated pastes). (Android dev channel only: an unsigned
   plain `.zip` with both fields empty is accepted — the server computes the
   SHA256. iOS and `production` are signed-only.)

Devices pick up the bundle on their next resume or home-screen visit; the
mandatory update sheet then shows "what's new" and restarts into it (tap, or a
6-second countdown). It never restarts during a checkout; then the bundle
applies on the next background/cold start. Choose **Both** to publish one
upload to android + ios, and bump the version each release (`1.6.2`, `1.6.3`, …).

> **Scripted alternative (CI only — not the standard path):**
> `npm run ota:release -- --platform both --version 1.6.1 --min-native 1.6`
> does build → zip → encrypt → upload in one shot (auth via `OTA_ADMIN_TOKEN`),
> with the pinned CLI. Signing is the default (`--sign` is accepted but no
> longer needed; `--unsigned` is refused except for android on a
> non-production channel). It uploads the encrypted file and fails hard if it
> can't read the encrypted checksum (it never falls back to the plain one or
> the plain zip). There's also `bin/console ota:publish …` (with `--notes`) to
> register a bundle that is already hosted. See
> [`scripts/ota-release.mjs`](apps/mobile/scripts/ota-release.mjs).

---

## Compatibility & rollback

- **`min_native_version`** — set it to the native build that introduced any
  capability the bundle relies on. A device on an older native shell is served
  *no* update rather than a bundle that would crash.
- **Rollback (one click)** — in the portal, click **Roll back to this** on the
  bundle you want devices on. The server re-publishes that bundle's existing
  file under the next unused version (e.g. bad `1.6.8` → rollback served as
  `1.6.9`, labelled "rollback of 1.6.3"), and deactivates every other bundle for
  that platform/channel. No upload, no notes popup. Devices download it on the
  next resume and the update sheet restarts into it (never mid-checkout). Repeat per platform.
  API: `POST /v3/admin/ota/bundles/{id}/rollback` (`settings.edit`).
- **Why Deactivate alone is not a rollback** — the endpoint only serves a
  version strictly newer than the device's current bundle. Deactivating a bad
  bundle stops devices that have *not* downloaded it yet; devices that already
  installed it keep it until something newer is published, which is exactly what
  **Roll back to this** does.
- The next real release must use a version above the rollback's. The upload
  form, the API and `ota:publish` reject a duplicate version, and any version
  that could never be served: one an active bundle shadows (an active bundle
  at least as high whose min-native is not higher). A LOWER version for OLDER
  shells (lower min-native) is still accepted; those shells get it.
- Deleting a bundle keeps its file while a rollback row still serves it, and
  that version can't be re-uploaded while the file is in use (re-uploading
  would replace the bytes under the rollback's checksum). Bump the version.
- **Auto-rollback** — a bundle that fails to boot (no `notifyAppReady()` within
  10s) reverts on-device automatically. The app remembers that version and
  never offers or auto-restarts into it again (nor a version whose download
  failed verification twice), so a crashing bundle can't trap the app in a
  restart loop; ship a fix under a new version. (The plugin itself may still
  re-stage it for the next background until a store build sets
  `autoDeleteFailed: false`; see OTA-DEPLOY.md → Follow-ups.)
- **Store updates win** — a new native release drops the OTA bundle
  (`resetWhenUpdate` + Capacitor's `isNewBinary`) so the fresh builtin takes over.

---

## Security

- Always: HTTPS, SHA256 verification, admin-gated endpoints (`settings.edit`),
  locked-down `var/uploads/ota`.
- **Signing (enforced):** end-to-end encryption / code signing so a spoofed
  endpoint, a compromised file store or a leaked admin/OTA token alone can't
  push malicious JS. The server refuses unsigned bundles for iOS and
  production. Full workflow in
  **[apps/mobile/OTA-SIGNING.md](apps/mobile/OTA-SIGNING.md)**. The private key
  (`.capgo_key_v2`) must never be committed or shipped.

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
- **Mobile** client changes (enabling/upgrading OTA, `key create`,
  `capacitor.config.ts`) are native → `pnpm install` + `npx cap sync` + a
  store build. Changes to the JS (e.g. `OtaUpdateService`) ship as an OTA
  bundle like any other.
- Ensure `var/uploads/ota/` is included in the server backup window.
