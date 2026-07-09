package com.threebayti.app

import android.content.Intent
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.UiDevice
import androidx.test.uiautomator.Until
import org.junit.Before
import org.junit.ClassRule
import org.junit.Test
import org.junit.runner.RunWith
import tools.fastlane.screengrab.Screengrab
import tools.fastlane.screengrab.UiAutomatorScreenshotStrategy
import tools.fastlane.screengrab.locale.LocaleTestRule

// screengrab drives the REAL app. Because the UI is an Angular SPA in a WebView,
// we navigate with UiAutomator by visible text (By.textContains) rather than
// Espresso view-matchers — that reliably reaches WebView-rendered content.
// Confirm the labels on the first run; fastlane handles the locales/output.
//
// A logged-in, populated account makes for real screenshots — pass a throwaway
// demo account via instrumentation args (the screenshots lane can add
// `-e SNAP_EMAIL ... -e SNAP_PASSWORD ...`). Leave them unset to shoot only
// guest-visible screens. NEVER commit real credentials.
@RunWith(AndroidJUnit4::class)
class ScreenshotTest {

    companion object {
        @get:ClassRule
        @JvmStatic
        val localeTestRule = LocaleTestRule()
    }

    private lateinit var device: UiDevice
    private val pkg = "com.threebayti.app"

    @Before
    fun setUp() {
        Screengrab.setDefaultScreenshotStrategy(UiAutomatorScreenshotStrategy())
        device = UiDevice.getInstance(InstrumentationRegistry.getInstrumentation())

        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val intent = context.packageManager.getLaunchIntentForPackage(pkg)!!
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TASK)
        context.startActivity(intent)
        device.wait(Until.hasObject(By.pkg(pkg).depth(0)), 20_000)
    }

    @Test
    fun captureStoreScreenshots() {
        logInIfNeeded()

        tapByText("Explore"); Screengrab.screenshot("01_Explore")
        tapByText("Sketch");  Screengrab.screenshot("02_Styles")
        tapByText("Gift");    Screengrab.screenshot("03_GiftCards")
        tapByText("Profile"); Screengrab.screenshot("04_Account")
        tapByText("Cart");    Screengrab.screenshot("05_Cart")
    }

    private fun logInIfNeeded() {
        val args = InstrumentationRegistry.getArguments()
        val email = args.getString("SNAP_EMAIL") ?: return
        val password = args.getString("SNAP_PASSWORD") ?: return

        device.wait(Until.findObject(By.clazz("android.widget.EditText")), 20_000)
        val fields = device.findObjects(By.clazz("android.widget.EditText"))
        if (fields.size >= 2) {
            fields[0].text = email
            fields[1].text = password
            tapByText("Login")
        }
        device.wait(Until.hasObject(By.textContains("Explore")), 30_000)
    }

    private fun tapByText(text: String) {
        val obj = device.wait(Until.findObject(By.textContains(text)), 15_000)
        obj?.click()
        device.waitForIdle()
        Thread.sleep(1_200) // let the Angular route settle before the shot
    }
}
