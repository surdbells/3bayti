# Social Sign-In (Google + Apple) — Setup → Testing Runbook

The **code is already merged** (`f1cbe87` API, `dc79a16` web, `c204928` mobile). This runbook is the **console + deploy + test** work that makes it function. Do the parts **in order** — later parts depend on earlier ones (Apple config feeds Firebase; SHA feeds the Android OAuth client; the web config feeds the web build).

**You'll need:** access to the Firebase console (project `bayti-bcc5e`), an Apple Developer account, the prod server (API), the web build/deploy env, and a dev machine with Android Studio + Xcode.

---

## Part 1 — Firebase console (project `bayti-bcc5e`)

### 1.1 Enable the Google provider
1. Firebase console → **Authentication** → **Sign-in method**.
2. **Add new provider** → **Google** → toggle **Enable**.
3. Set the **Project support email** → **Save**. (Firebase auto-creates the Google OAuth client.)

### 1.2 Register the Web app + copy its config
1. Firebase console → **Project settings** (gear) → **General** → **Your apps**.
2. If there's no Web app yet, click **Add app** → **Web** (`</>`), nickname "3bayti web", **Register app**.
3. Copy the `firebaseConfig` values. You'll map them to env vars in Part 4:

   | firebaseConfig field | env var |
   |---|---|
   | `apiKey` | `FIREBASE_API_KEY` |
   | `authDomain` (`bayti-bcc5e.firebaseapp.com`) | `FIREBASE_AUTH_DOMAIN` |
   | `messagingSenderId` | `FIREBASE_MESSAGING_SENDER_ID` |
   | `appId` | `FIREBASE_APP_ID` |
   | `projectId` (`bayti-bcc5e`) | `FIREBASE_PROJECT_ID` (already defaults) |

### 1.3 Authorized domains (web)
1. **Authentication** → **Settings** → **Authorized domains** → **Add domain**.
2. Add `3bayti.ae` (and `www.3bayti.ae`) and any Cloudflare Pages preview domain you test on. (`localhost` and `bayti-bcc5e.firebaseapp.com` are there by default.)

### 1.4 Android SHA fingerprints (for native Google sign-in)
1. Get your fingerprints. Easiest:
   ```bash
   cd apps/mobile/android && ./gradlew signingReport
   ```
   Note the **SHA-1** and **SHA-256** for both the **debug** keystore and your **release**/upload key. (If you use **Play App Signing**, also copy the SHA-1/256 from **Play Console → your app → Test and release → App integrity → App signing**.)
2. Firebase console → **Project settings** → **Your apps** → the **Android** app (`ae.threebayti.app`) → **Add fingerprint** → paste each SHA-1 and SHA-256 → **Save**.
3. **Re-download `google-services.json`** (now it contains the Google OAuth client) and **replace** `apps/mobile/android/app/google-services.json`. (Do this before the mobile `cap sync` in Part 5.)

### 1.5 Enable the Apple provider
> Do Part 2 first to get the Service ID + key, then come back here.
1. **Authentication** → **Sign-in method** → **Add new provider** → **Apple** → **Enable**.
2. Fill **Services ID** (from 2.2), **Apple team ID**, **Key ID** + the **private key** (.p8 contents) from 2.3 → **Save**.

---

## Part 2 — Apple Developer (required for Apple sign-in)

### 2.1 App ID capability (mobile)
1. [developer.apple.com](https://developer.apple.com) → **Certificates, Identifiers & Profiles** → **Identifiers** → your App ID (`ae.threebayti.app`).
2. Enable **Sign in with Apple** → **Save**. (You'll also add the capability in Xcode in Part 5.)

### 2.2 Services ID (for web Apple sign-in)
1. **Identifiers** → **+** → **Services IDs** → continue. Description "3bayti web", Identifier e.g. `ae.threebayti.web` → **Register**.
2. Edit it → enable **Sign in with Apple** → **Configure**:
   - **Primary App ID:** `ae.threebayti.app`.
   - **Domains and Subdomains:** `bayti-bcc5e.firebaseapp.com`
   - **Return URLs:** `https://bayti-bcc5e.firebaseapp.com/__/auth/handler`
   - Save. **This Services ID identifier is the "Services ID" you paste into Firebase (1.5).**

### 2.3 Sign in with Apple key (.p8)
1. **Keys** → **+** → name "3bayti SIWA", enable **Sign in with Apple** → configure (primary App ID `ae.threebayti.app`) → **Register**.
2. **Download** the `.p8` (one-time) and note the **Key ID**. Also note your **Team ID** (top-right of the portal). These three go into Firebase's Apple provider (1.5).

---

## Part 3 — API deploy

The verifier uses your existing `FCM_PROJECT_ID`; optionally set `FIREBASE_PROJECT_ID=bayti-bcc5e` in `apps/api/.env` to be explicit. No other API env, no new packages.

```bash
cd /www/wwwroot/3bayti && git pull origin main
rm -rf apps/api/var/cache/di/* && cd apps/api
/www/server/php/83/bin/php bin/console migrations:migrate -n      # social_identities + password_hash nullable
/www/server/php/83/bin/php bin/console orm:generate-proxies
chown -R www:www var/ && /etc/init.d/php-fpm-83 reload
```
**Smoke test:** `curl -s -X POST https://api-v3.3bayti.ae/v3/auth/social -H 'Content-Type: application/json' -d '{"id_token":"x"}'` should return a clean 401/422 (token invalid) — **not** a 500. A 500 means the migration/DI didn't apply.

---

## Part 4 — Web deploy

Set the Firebase build env (from 1.2), then build + deploy. For Cloudflare Pages, set these in the **Pages project → Settings → Environment variables** (Production) **or** export them in the shell before `npm run build`:
```bash
export FIREBASE_API_KEY="…"
export FIREBASE_AUTH_DOMAIN="bayti-bcc5e.firebaseapp.com"
export FIREBASE_MESSAGING_SENDER_ID="…"
export FIREBASE_APP_ID="…"
# FIREBASE_PROJECT_ID defaults to bayti-bcc5e
cd /www/wwwroot/3bayti/apps/web && npm run build && npm run deploy
```
This deploys the SPA **and** the new `/auth-proxy/social` Pages function. (If `FIREBASE_API_KEY` is empty the app still boots, but the social buttons will show an "unavailable" error — that's the guard.)

---

## Part 5 — Mobile build (native — store build, not OTA)

1. Make sure you replaced `google-services.json` after adding SHA (1.4 step 3).
2. Install + sync:
   ```powershell
   cd C:\Users\USER\Documents\3bayti
   pnpm install
   cd apps/mobile
   Remove-Item -Recurse -Force .angular\cache -ErrorAction SilentlyContinue
   npm run build ; npx cap sync
   ```
3. **iOS (Xcode):** `npx cap open ios` →
   - Target → **Signing & Capabilities** → **+ Capability** → **Sign in with Apple**.
   - Confirm `GoogleService-Info.plist` is in the target. Add the **Google reversed-client-id URL scheme**: open `GoogleService-Info.plist`, copy `REVERSED_CLIENT_ID`, then Target → **Info** → **URL Types** → **+** → paste it as the URL Scheme (needed for the Google redirect).
   - Build to a **real device** (social sign-in needs a device, not always the simulator).
4. **Android (Android Studio):** `npx cap open android` → build/run on a device. (Google sign-in needs the SHA registered in 1.4.)
5. Ship the resulting builds to **TestFlight / Play internal testing** for real testing.

---

## Part 6 — Testing

Test **web** as soon as Parts 1–4 are done; **mobile** after Part 5.

### Web (https://3bayti.ae)
1. **Google login:** Logout → `/login` → **Continue with Google** → pick an account → lands signed in. (A brand-new account is sent to the **verify-phone** step first — enter a phone, get the OTP, verify → home.)
2. **Apple login:** same on `/login` → **Continue with Apple**.
3. **Register page:** the same buttons work on `/register`.
4. **Auto-link:** sign in with a Google account whose email matches an existing email/password 3bayti account → you land in **that** account (one identity, not a duplicate). Confirm in account settings.
5. **Connected accounts:** Account → settings → **Connected accounts** → **Link** the other provider (Google/Apple); **Unlink** one.
6. **Unlink guard:** for a social-only account (no password, one provider), try to unlink it → blocked with "keep at least one way to sign in."
7. **RTL:** switch to Arabic and re-check the buttons + connected-accounts screen.

### Mobile (device build)
8. Login page → **Continue with Google** / **Apple** → native account picker → signed in (+ phone gate for new accounts).
9. **Settings → Connected accounts** → connect/disconnect.

### What "good" looks like
- No `INTERNAL_ERROR`/500 from `/v3/auth/social`.
- New social user: created, email shown as verified, prompted for phone once.
- Returning social user: straight in.
- Email collision: single merged account.

---

## Known follow-ups (non-blocking)
- A social user with **no** email from the provider gets a `…@social.3bayti.invalid` placeholder address — add a guard in the notification layer before emailing those.
- New social users default `countryCode = 'AE'` (launch market).

## Troubleshooting
- **Web popup closes immediately / `auth/unauthorized-domain`:** the domain isn't in **Authorized domains** (1.3).
- **Web Apple error `invalid_client`:** Services ID / return URL mismatch (2.2) or wrong key in Firebase (1.5).
- **Android Google sign-in fails silently (code 12501/10):** SHA not registered, or `google-services.json` not re-downloaded after adding SHA (1.4).
- **iOS Google sign-in doesn't return:** missing reversed-client-id URL scheme (Part 5 step 3).
- **`/v3/auth/social` 500s:** migrations didn't run or DI cache stale (re-run Part 3).
