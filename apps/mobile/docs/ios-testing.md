# iOS testing from a fresh clone

How to build and run the **3bayti** mobile app on iOS (Simulator or a real device)
starting from a clean `git clone`. macOS + Xcode only — you cannot build iOS on
Windows/Linux.

The committed iOS project already contains everything native (`apps/mobile/ios/App`,
`GoogleService-Info.plist`, the `Podfile`), so you do **not** run `npx cap add ios`.
The web build output (`www/`) and CocoaPods (`Pods/`) are git-ignored, so you build
those locally.

> The app talks to the **production** backend `https://api-v3.3bayti.ae` (hardcoded in
> `src/app/core/http/mobile-network-adapter.ts`). There is **no** local API or `.env`
> to configure — a fresh build hits prod, so you're testing against live data.

---

## 1. Prerequisites (one-time)

| Tool | Version | Install |
|---|---|---|
| macOS + **Xcode** | latest | App Store, then open once to accept the license |
| Xcode Command Line Tools | — | `xcode-select --install` |
| **Node** | 22+ (repo pins **24.12.0** in `apps/mobile/.nvmrc`) | `nvm install 24.12.0` (recommend [nvm](https://github.com/nvm-sh/nvm)) |
| **pnpm** | **9.15.0** | `corepack enable && corepack prepare pnpm@9.15.0 --activate` |
| **CocoaPods** | latest | `brew install cocoapods` (or `sudo gem install cocoapods`) |
| Apple ID / Developer account | — | only needed to run on a **real device** (Simulator needs none) |

Verify: `xcodebuild -version`, `node -v` (≥22), `pnpm -v` (9.15.0), `pod --version`.

---

## 2. Clone + install

```bash
git clone <REPO_URL> 3bayti
cd 3bayti

# use the repo's Node version
cd apps/mobile && nvm use && cd ../..      # reads apps/mobile/.nvmrc (24.12.0)

# install the whole pnpm workspace from the repo root (NOT inside apps/mobile)
pnpm install
```

---

## 3. Build the web assets → sync to iOS

```bash
# 1) Build the Angular app into apps/mobile/www (production build)
pnpm --filter @3bayti/mobile build

# 2) Copy www into the native project + install Pods
cd apps/mobile
npx cap sync ios

# 3) Open the project in Xcode
npx cap open ios            # opens ios/App/App.xcworkspace
```

> Always open **`App.xcworkspace`**, never `App.xcodeproj` (Pods won't be linked).

---

## 4. Run

### On the Simulator (fastest, no signing)
1. In Xcode's top bar pick a simulator (e.g. **iPhone 16**).
2. Press **▶ Run**. The app builds, launches in the Simulator, and connects to
   `api-v3.3bayti.ae`.

> Push notifications and a few native features only work on a **real device**, not
> the Simulator — everything else (browse, auth, cart, checkout webview, gift cards,
> tickets) works in the Simulator.

### On a real device (requires signing)
1. Plug in the iPhone, trust the computer, and select it as the run destination.
2. In Xcode: select the **App** target → **Signing & Capabilities**:
   - Check **Automatically manage signing**.
   - Set **Team** to your Apple ID / Developer team.
   - If the bundle id `ae.threebayti.app` is already claimed by another account,
     change it to something unique (e.g. `ae.threebayti.app.test`).
3. Press **▶ Run**. On first launch, approve the developer profile on the phone:
   **Settings → General → VPN & Device Management → trust the developer**.

---

## 5. Rebuild loop (after pulling/changing code)

Native changes (icons, splash, plugins) and web changes both need a re-sync:

```bash
# from repo root
rm -rf apps/mobile/.angular/cache          # only if web changes don't show up
pnpm --filter @3bayti/mobile build
cd apps/mobile && npx cap sync ios
# then ▶ Run again in Xcode
```

For **splash/icon** changes specifically, also delete the app from the
Simulator/device before re-running — iOS caches the launch screen aggressively.

---

## 6. Distribute to testers via TestFlight (optional)

To put a build in front of testers who don't have the repo:

1. Bump the build number (Xcode → target → General → **Build**).
2. Set the run destination to **Any iOS Device (arm64)**.
3. **Product → Archive**.
4. In the Organizer: **Distribute App → App Store Connect → Upload**.
5. In [App Store Connect](https://appstoreconnect.apple.com) → TestFlight, add the
   build to a tester group. (Requires a paid Apple Developer account and the app
   record to exist.)

---

## 7. Troubleshooting

| Symptom | Fix |
|---|---|
| `npx cap sync ios` fails on Pods | `cd apps/mobile/ios/App && pod install --repo-update` |
| "Unable to find www" / blank app | run `pnpm --filter @3bayti/mobile build` **before** `cap sync` |
| Web changes don't appear | `rm -rf apps/mobile/.angular/cache`, rebuild, re-sync |
| Splash/icon stale | delete the app from Simulator/device, rebuild, re-run |
| Signing error on device | set a Team + a unique bundle id (step 4) |
| Weird build errors after a big change | Xcode → **Product → Clean Build Folder**, or `rm -rf apps/mobile/ios/App/Pods apps/mobile/ios/App/build && npx cap sync ios` |
| `pod` not found | `brew install cocoapods` |

---

## Quick reference (TL;DR)

```bash
git clone <REPO_URL> 3bayti && cd 3bayti
cd apps/mobile && nvm use && cd ../..
pnpm install
pnpm --filter @3bayti/mobile build
cd apps/mobile && npx cap sync ios && npx cap open ios
# Xcode: pick a simulator → ▶ Run
```

- App id: `ae.threebayti.app` · name: `3bayti` · webDir: `www`
- Backend (prod, hardcoded): `https://api-v3.3bayti.ae`
- Capacitor 8 · Ionic 8 · Angular 21
