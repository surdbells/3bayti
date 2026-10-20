# OTA (over-the-air) mobile updates

3bayti ships JavaScript/CSS updates to the installed mobile app **without an
app-store release**, using `@capgo/capacitor-updater` pointed at **our own
server** (no Capgo Cloud, no external CDN). Bundles are managed from the **portal**
— no shell.

> **OTA ships web assets only.** Anything that needs new native code, a Capacitor
> plugin, or a new permission must go through the app store with a native
> version bump. Each bundle has a **native range** (`min_native_version` to
> `max_native_version`, max defaults to min): it is offered only to app builds
> whose app version is inside it, so it stays off older shells it can't run on
> AND off newer TestFlight / test builds whose own web code it would replace
> (see [Native range](#native-range-and-testflight--test-builds)).

---

## How it works

```
 App (@capgo/capacitor-updater, autoUpdate)
   │  on resume/cold start → POST /v3/ota/updates
   │     { platform, app_id, version_name(current bundle), version_build(native APP version) }
   ▼
 API  POST /v3/ota/updates  → HIGHEST-VERSION ACTIVE ota_bundles row for {app,platform,channel}
   │                          with min_native ≤ version_build ≤ max_native (max defaults to min),
   │                          served only if version > device bundle
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
- **Selection:** among active bundles whose native range contains the
  device's app version, the **highest version** wins (not the most recently
  uploaded), so a late-registered lower version or a re-activated old row can
  never shadow a newer release. Bundles with different ranges are served side
  by side (e.g. production shell 1.8 and a 1.9 shell each get their own).
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
   exceed the device's current bundle; on its built-in web code a device
   reports its app version, so use a version above Max native, e.g. `1.8.4`
   for app `1.8`) / **Min native** (required: the app version of the
   production shell the bundle was built for, as live in the stores, e.g.
   `1.8`) / **Max native** (optional, defaults to Min native; see
   [Native range](#native-range-and-testflight--test-builds));
   paste **Session key** = ivSessionKey and **Checksum** = the
   encrypted checksum (exactly 512 hex; the form and API reject the 64-hex
   plain sha256 and truncated pastes). (Android dev channel only: an unsigned
   plain `.zip` with both fields empty is accepted — the server computes the
   SHA256. iOS and `production` are signed-only.)

Devices pick up the bundle on their next resume or home-screen visit; the
mandatory update sheet then shows "what's new" and restarts into it (tap, or a
6-second countdown). It never restarts during a checkout; then the bundle
applies on the next background/cold start. Choose **Both** to publish one
upload to android + ios (with ONE native range for both: see
[Native range](#native-range-and-testflight--test-builds) when their store
versions differ), and bump the version each release (`1.8.4`, `1.8.5`, …).

> **Scripted alternative (CI only — not the standard path):**
> `npm run ota:release -- --platform both --version 1.8.4 --min-native 1.8`
> (add `--max-native <x.y>` for a range; it defaults to `--min-native`, and
> `--min-native` is required) does build → zip → encrypt → upload in one shot
> (auth via `OTA_ADMIN_TOKEN`), with the pinned CLI. Signing is the default (`--sign` is accepted but no
> longer needed; `--unsigned` is refused except for android on a
> non-production channel). It uploads the encrypted file and fails hard if it
> can't read the encrypted checksum (it never falls back to the plain one or
> the plain zip). There's also `bin/console ota:publish …` (with `--notes`) to
> register a bundle that is already hosted. See
> [`scripts/ota-release.mjs`](apps/mobile/scripts/ota-release.mjs).

---

## Native range and TestFlight / test builds

A bundle is offered to a device only when

```
min_native_version  <=  device version_build  <=  max_native_version
```

both ends inclusive, compared numerically with missing segments as 0 (`1.8`
equals `1.8.0`; `1.8.1` is above a max of `1.8`).

- **Min native is required** (portal, API, `ota:publish`, release script).
  There is no `0.0.0` default any more: set it to the app version of the
  shell the bundle was built for. A range ending at `0` (e.g. `0.0.0`) is
  refused, it matches no installed app.
- **Max native is optional and defaults to Min native** (stored explicitly).
  So by default a bundle reaches exactly one app version. Widen it only when
  the same JS should reach several shells, e.g. Min `1.7` / Max `1.8` for users
  who haven't updated from 1.7. Never let Max include a version a TestFlight
  or test build uses.
- **Rows from before max existed** (stored max empty) behave as max = min,
  so they now reach only their exact Min native version; see
  [OTA-DEPLOY.md](OTA-DEPLOY.md) for the pre-deploy check. A row whose range
  ends at `0` (the old `0.0.0` default) reaches no app at all: the portal
  shows it as "matches no app", and the API refuses to activate it or roll
  back to it until its max is widened (OTA-DEPLOY.md).
- **The bundle version must be above Max native** for fresh installs to take
  it. On its built-in web code (a fresh install, or right after a store
  update) the plugin reports the app's marketing version as its current
  bundle version, and the endpoint only offers a strictly newer one. So a
  bundle for Min `1.8` / Max `1.8` needs version `1.8.1` or higher, one for a
  `1.8.1` test shell needs `1.8.2` or higher, and one for Min `1.7` / Max `1.9`
  must be above `1.9` to reach fresh `1.9` installs. The portal asks before
  publishing a version that isn't, and the release script warns. Versions
  are unique per platform/channel across ranges, so production and test
  bundles share one version sequence.
- **Platform Both uses one range for both platforms.** iOS needs a new
  `CFBundleShortVersionString` for every App Store release while Play only
  needs a new `versionCode`, so the two store versions can drift apart. When
  they differ, upload each platform separately with its own Min native (or
  give a range that covers both production versions and no test build's).

**What `version_build` is.** The installed `@capgo/capacitor-updater`
(8.51.3) sends the app's **marketing version**, not its build number:

| Platform | `version_build` = | Not compared |
|---|---|---|
| iOS | `CFBundleShortVersionString` (Xcode `MARKETING_VERSION`) | `CFBundleVersion` / `CURRENT_PROJECT_VERSION` |
| Android | `versionName` (`android/app/build.gradle`) | `versionCode` |

The value to use as Min native for production bundles is the version
**live in the stores** (Play Console / App Store Connect), not the one in
`build.gradle` / `project.pbxproj`: the repo holds the NEWEST native build,
which is often the TestFlight / Play test build you want to keep apart.

(Source: `CapacitorUpdaterPlugin.swift` sets `versionBuild` from
`Bundle.main.versionName`, which `InternalUtils.swift` reads from
`CFBundleShortVersionString`; `CapacitorUpdaterPlugin.java` sets it from
`PackageInfo.versionName`. The build number travels separately as
`version_code`, which the endpoint ignores. A `CapacitorUpdater.version`
entry in `capacitor.config.ts` would override it; we don't set one, and
shouldn't.)

**Rule for test builds:** a TestFlight build or an Android internal/closed
testing build must carry a **higher marketing version** than the production
shell (production `1.8` → test build `1.8.1` or `1.9`). A higher build number
alone (`1.8` build 6) reports `1.8`, the same as production, so production
bundles (Min = Max = `1.8`) would still replace the test build's own web code.
With `1.8.1` the test build is outside the production range and keeps its own
web code. So when the repo's marketing version equals the live store version
(e.g. both `1.8`, with only the build number bumped), bump `MARKETING_VERSION`
/ `versionName` (e.g. to `1.8.1`) before cutting the next TestFlight / Play
test build; a test build already cut at the store version is not protected.

- To OTA-test a test build, publish a bundle with Min native = its app
  version (e.g. `1.8.1`) and an unused version above it (e.g. `1.8.2`;
  versions are unique per platform/channel, so skip any a production bundle
  already took); production shells (`1.8`) are outside that range. Once that app version ships to the store,
  its users get those bundles too.
- When a new shell (e.g. `1.9`) ships, publish its bundles with Min native
  `1.9`. Bundles for `1.8` keep reaching only users still on `1.8`.

## Compatibility & rollback

- **Native range** — Min native is the shell the bundle was built for (and
  so has every native capability it relies on): an older shell is served *no*
  update rather than a bundle that would crash. Max native keeps it off newer
  shells (above).
- **Rollback (one click)** — in the portal, click **Roll back to this** on the
  bundle you want devices on. The server re-publishes that bundle's existing
  file under the next unused version (e.g. bad `1.6.8` → rollback served as
  `1.6.9`, labelled "rollback of 1.6.3"), and deactivates the active bundles
  for that platform/channel whose native range overlaps the source's.
  Bundles for other app versions only (e.g. the TestFlight `1.8.1` bundle
  while rolling back production `1.8`, or the reverse) stay active. No upload,
  no notes popup. Devices download it on the next resume and the update sheet
  restarts into it (never mid-checkout). Repeat per platform.
  The rollback keeps the source's native range (a row without a max gets
  max = min), so it reaches only those app versions. If a deactivated bundle
  also served app versions outside that range (e.g. a bad `1.7 – 1.9` bundle
  pulled by a `1.8` rollback), those shells are offered only another active
  bundle that covers them, if any; the confirm dialog lists such bundles. So
  pick a source whose range covers the shells you are rolling back. A source
  whose range ends at `0` (legacy `0.0.0` rows) can't be rolled back to (the
  portal disables the button, the API answers 400): widen its max first, see
  [OTA-DEPLOY.md](OTA-DEPLOY.md#native-range-max_native-pre-deploy-check).
  API: `POST /v3/admin/ota/bundles/{id}/rollback` (`settings.edit`).
- **Why Deactivate alone is not a rollback** — the endpoint only serves a
  version strictly newer than the device's current bundle. Deactivating a bad
  bundle stops devices that have *not* downloaded it yet; devices that already
  installed it keep it until something newer is published, which is exactly what
  **Roll back to this** does.
- The next real release must use a version above the rollback's. The upload
  form, the API and `ota:publish` reject a duplicate version, and any version
  that could never be served: one an active bundle shadows (an active bundle
  at least as high whose native range covers the new one's whole range). A
  LOWER version for shells outside that range (an older-shell hotfix below its
  Min, or a test-shell bundle above its Max) is still accepted; those shells
  get it.
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
  migration: `bin/console migrations:migrate -n`. The native-range change
  adds `Version20261021000001` (`ota_bundles.max_native_version`) and narrows
  existing bundles to max = min: run the
  [pre-deploy check](OTA-DEPLOY.md#native-range-max_native-pre-deploy-check)
  first. Deploy the API and the portal together: the old portal pre-fills
  Min native `0.0.0` (which the API now refuses) and has no Max native field.
  Same for an old checkout of the release script (`--min-native` defaulted to
  `0.0.0`).
- **Portal** deploys via its Cloudflare Pages build.
- **Mobile** client changes (enabling/upgrading OTA, `key create`,
  `capacitor.config.ts`) are native → `pnpm install` + `npx cap sync` + a
  store build. Changes to the JS (e.g. `OtaUpdateService`) ship as an OTA
  bundle like any other.
- Ensure `var/uploads/ota/` is included in the server backup window.
