import XCTest

// UI test that walks the app and captures one screenshot per store screen.
//
// IMPORTANT — this is a Capacitor app: the entire UI is an Angular SPA inside a
// WKWebView. XCUITest reaches WebView content through `app.webViews`, matching
// on the accessibility label / visible text of each element. Those labels come
// from the live DOM, so the taps below are a STARTING POINT you confirm on the
// first run — use Xcode's "Record UI Test" button, or drop a breakpoint and
// `po app.webViews.firstMatch.debugDescription` to print the real element tree.
// Everything else (device matrix, locales, status bar, output) is fastlane's job.
//
// One-time setup (see docs/store-screenshots.md):
//   1. Xcode ▸ File ▸ New ▸ Target ▸ "UI Testing Bundle", name it "AppUITests".
//   2. In ios/App run `fastlane snapshot init`, then add the generated
//      SnapshotHelper.swift to the AppUITests target.
//   3. Add THIS file to the AppUITests target.
//   4. Screenshots need a logged-in, populated account. Provide a throwaway
//      "screenshots" demo account via the SNAP_EMAIL / SNAP_PASSWORD env vars
//      (Scheme ▸ Edit ▸ Test ▸ Arguments ▸ Environment Variables). NEVER commit
//      real credentials. Leave them empty to shoot only guest-visible screens.
final class ScreenshotUITests: XCTestCase {
    let app = XCUIApplication()

    override func setUpWithError() throws {
        continueAfterFailure = false
        setupSnapshot(app) // provided by SnapshotHelper.swift
        app.launch()
    }

    func testCaptureStoreScreenshots() throws {
        logInIfNeeded()

        // The screens that make the strongest listing. Reorder/trim freely —
        // the first screenshot is the one most people see, so lead with Explore.
        goToExplore();   snapshot("01_Explore")
        goToStyles();    snapshot("02_Styles")
        goToGiftCards(); snapshot("03_GiftCards")
        goToAccount();   snapshot("04_Account")
        goToCart();      snapshot("05_Cart")
    }

    // MARK: - Navigation (tune the labels to the live WebView UI)

    private func web() -> XCUIElement { app.webViews.firstMatch }

    private func tapInWeb(_ label: String, timeout: TimeInterval = 15) {
        let el = web().buttons[label].firstMatch
        if el.waitForExistence(timeout: timeout) {
            el.tap()
            // Let the Angular route settle before the shot.
            Thread.sleep(forTimeInterval: 1.2)
        }
    }

    private func logInIfNeeded() {
        let env = ProcessInfo.processInfo.environment
        guard let email = env["SNAP_EMAIL"], !email.isEmpty,
              let password = env["SNAP_PASSWORD"], !password.isEmpty else { return }

        let emailField = web().textFields.firstMatch
        if emailField.waitForExistence(timeout: 20) {
            emailField.tap(); emailField.typeText(email)
            let pass = web().secureTextFields.firstMatch
            pass.tap(); pass.typeText(password)
            web().buttons["Login"].firstMatch.tap()
        }
        // Wait for the authed shell (a bottom-tab label) to appear.
        _ = web().buttons["Explore"].firstMatch.waitForExistence(timeout: 30)
    }

    // Bottom-tab labels come from the app's i18n (home/explore/cart/gift/profile).
    // Adjust to the visible English strings if they differ.
    private func goToExplore()   { tapInWeb("Explore") }
    private func goToStyles()    { tapInWeb("Sketch") }   // "Styles" tab
    private func goToGiftCards() { tapInWeb("Gift") }
    private func goToAccount()   { tapInWeb("Profile") }
    private func goToCart()      { tapInWeb("Cart") }
}
