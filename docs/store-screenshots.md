# Store screenshots — fastlane automation

Automated capture of App Store + Google Play screenshots from the **real app**
running on a simulator/emulator, using **fastlane `snapshot` (iOS)** and
**`screengrab` (Android)**. The harness owns the tedious parts — the device
matrix, locales, status bar, output folders, and (optionally) upload. You own
one thing it can't guess: the in-app navigation, because this is a Capacitor
app and the UI is an Angular SPA inside a WebView (see the WebView note below).

> These lanes run on **macOS (iOS)** and on a machine with the **Android SDK +
> an emulator**. They were authored on Windows and can't be executed here — run
> them from your Mac / dev box.

## What it produces (matches store requirements)

The app is **iPhone-only + portrait**, so the required set is small:

| Store | Asset | Size | Where fastlane writes it |
|---|---|---|---|
| App Store | iPhone 6.9" screenshots | **1290 × 2796** | `ios/App/fastlane/screenshots/<lang>/` |
| Google Play | Phone screenshots | **1080 × 2400** (emulator-dependent) | `android/fastlane/metadata/android/<locale>/images/phoneScreenshots/` |

Locales configured: **en-US** + **ar** (Arabic renders RTL). Drop the Arabic
entry in `Snapfile` / `Screengrabfile` if you're launching English-only.

**Still manual (Play requires these, not screenshots):** a **feature graphic
1024 × 500** and the **app icon 512 × 512**. I can generate both as exportable
Artifacts — just ask.

## One-time setup

```bash
cd apps/mobile
bundle install          # installs fastlane from the Gemfile
```

### iOS (needs Xcode)
1. **Add a UI-test target**: Xcode ▸ File ▸ New ▸ Target ▸ *UI Testing Bundle*,
   name it **`AppUITests`**. Point "Target to be Tested" at **App**.
2. **Add the snapshot helper**: from `apps/mobile/ios/App` run
   `bundle exec fastlane snapshot init`, then drag the generated
   **`SnapshotHelper.swift`** into the `AppUITests` target.
3. **Add the test**: add the already-provided
   `AppUITests/ScreenshotUITests.swift` to the `AppUITests` target.
4. **Share the scheme**: Xcode ▸ Product ▸ Scheme ▸ Manage Schemes ▸ tick
   *Shared* for **App**, and make sure the scheme's **Test** action includes
   `AppUITests`.

### Android (no extra setup)
The `screengrab` + `uiautomator` test dependencies are already in
`android/app/build.gradle`. Just have a **portrait phone emulator** running
(e.g. Pixel 8 → 1080 × 2400) before you run the lane.

### Demo account (for logged-in, populated screens)
Most screens require auth + data, so create a throwaway **"screenshots" account**
with nice curated data (a few styles, cart items, a gift card). Pass its creds
at run time — **never commit them**:
- **iOS**: Xcode ▸ Edit Scheme ▸ Test ▸ Arguments ▸ Environment Variables →
  `SNAP_EMAIL`, `SNAP_PASSWORD`.
- **Android**: add `-e SNAP_EMAIL ... -e SNAP_PASSWORD ...` to the screengrab
  run (or hard-code in a local, git-ignored copy of the test).

Leave them unset to capture only guest-visible screens.

## Run it

```bash
# iOS  →  ios/App/fastlane/screenshots/<lang>/*.png  (1290 x 2796)
cd apps/mobile/ios/App
bundle exec fastlane screenshots

# Android (emulator must be running)  →  metadata/android/<locale>/images/phoneScreenshots/
cd apps/mobile/android
bundle exec fastlane screenshots
```

## The WebView reality (read this before the first run)

fastlane drives **native** UI automation, but our screens live in a WebView.
The provided tests navigate by **visible text / accessibility label**
(`app.webViews.buttons["Explore"]` on iOS; `By.textContains("Explore")` on
Android), which works for WebView content — **but the exact labels come from the
live app**, so expect to tune the tap targets and the login step on the first
run:
- **iOS**: use Xcode's **Record UI Test** button while the sim runs, or a
  breakpoint + `po app.webViews.firstMatch.debugDescription` to see real labels.
- **Android**: `adb shell uiautomator dump` (or Android Studio's Layout
  Inspector) to read the on-screen text.

The bottom-tab labels map to the app's i18n (`home / explore / cart / sketch
(=Styles) / gift / profile`) — adjust the strings in the test to whatever is
visibly rendered in each locale.

If the WebView tapping proves flaky, the fallback is deep-link + native capture:
`xcrun simctl openurl booted <url>` / `adb shell am start -d <url>` to route the
Angular app, then `xcrun simctl io booted screenshot out.png` /
`adb exec-out screencap -p > out.png`. That needs a registered URL scheme; ask
and I'll wire one up.

## Upload (optional)

Both platforms have an `upload_screens` lane that pushes only screenshots (no
binary, no metadata):

```bash
cd apps/mobile/ios/App && bundle exec fastlane upload_screens   # -> App Store Connect (deliver)
cd apps/mobile/android  && bundle exec fastlane upload_screens   # -> Google Play internal (supply)
```
First set up auth: an **App Store Connect API key** for `deliver` (fill in
`ios/App/fastlane/Appfile`), and a **Play service-account JSON** for `supply`
(referenced in `android/fastlane/Appfile`). Until then, just upload the PNGs by
hand in App Store Connect / Play Console.

## Files added

```
apps/mobile/Gemfile                                         # fastlane
apps/mobile/ios/App/fastlane/{Appfile,Snapfile,Fastfile}    # iOS snapshot config
apps/mobile/ios/App/AppUITests/ScreenshotUITests.swift      # iOS capture test
apps/mobile/android/fastlane/{Appfile,Screengrabfile,Fastfile}
apps/mobile/android/app/src/androidTest/java/ae/threebayti/app/ScreenshotTest.kt
apps/mobile/android/app/build.gradle                        # + screengrab/uiautomator test deps
docs/store-screenshots.md                                   # this file
```
