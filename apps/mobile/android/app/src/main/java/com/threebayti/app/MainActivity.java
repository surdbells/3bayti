package com.threebayti.app;

import android.content.SharedPreferences;
import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // ── Post-Capgo safeguard ──────────────────────────────────────────
        // This app used to ship JS bundles over-the-air via Capgo. After
        // removing Capgo, a device that still had an active OTA bundle could
        // otherwise keep booting it: Capacitor persists the OTA path under
        // CapWebViewSettings/serverBasePath and re-reads it on launch. Capacitor
        // already clears this on a version bump (isNewBinary), but force it to
        // the builtin bundle here — BEFORE the bridge reads it — so a device
        // also recovers on a build that reuses a version (e.g. during testing).
        // We no longer use OTA, so the builtin bundle is always the correct one.
        getSharedPreferences("CapWebViewSettings", MODE_PRIVATE)
            .edit()
            .putString("serverBasePath", "")
            .apply();

        super.onCreate(savedInstanceState);

        clearWebViewCacheOnUpgrade();
    }

    /**
     * Clear the WebView HTTP cache once, the first time a new app version runs,
     * so a stale cached index.html / assets from the previous build can't
     * survive an update. Capacitor's version check resets the bundle path but
     * not the WebView cache. Never fatal — a failure must not block launch.
     */
    private void clearWebViewCacheOnUpgrade() {
        try {
            String current = getPackageManager()
                .getPackageInfo(getPackageName(), 0).versionName;
            SharedPreferences sp = getSharedPreferences("ax_app_state", MODE_PRIVATE);
            if (current != null && !current.equals(sp.getString("last_version_name", ""))) {
                if (getBridge() != null && getBridge().getWebView() != null) {
                    getBridge().getWebView().clearCache(true);
                }
                sp.edit().putString("last_version_name", current).apply();
            }
        } catch (Exception e) {
            // Ignore — recovering from a cache-clear failure isn't worth crashing.
        }
    }
}
