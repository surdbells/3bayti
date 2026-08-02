import { Injectable } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { Clipboard } from '@capacitor/clipboard';

/**
 * Reads text from the system clipboard across platforms.
 *
 * Why this exists: on the native WebView (WKWebView / Android WebView) the web
 * `navigator.clipboard.readText()` is blocked — it throws NotAllowedError or
 * isn't exposed at all — which is why the in-app Paste button reported
 * "couldn't read the clipboard". The reliable native path is the Capacitor
 * Clipboard plugin. This service prefers the plugin on native and falls back
 * to the web API on the browser (and on native shells that predate the plugin,
 * where the plugin call rejects and we degrade gracefully).
 *
 * NOTE: the plugin is a NATIVE capability — it only works in a store build that
 * shipped @capacitor/clipboard (via `cap sync`), NOT through an OTA JS bundle.
 * On an older shell read() returns null and the caller shows a manual-paste
 * hint.
 */
@Injectable({ providedIn: 'root' })
export class ClipboardService {
  /**
   * @returns the clipboard text, '' when empty, or null when the clipboard
   *          could not be read (blocked / unsupported) so the caller can
   *          surface a "paste manually" hint.
   */
  async read(): Promise<string | null> {
    // 1) Native plugin — the only reliable path inside the app's WebView.
    if (Capacitor.isNativePlatform()) {
      try {
        const res = await Clipboard.read();
        return res?.value ?? '';
      } catch {
        // Shell without the plugin (pre-store-build), or user denied — fall
        // through to the web API, then to null.
      }
    }

    // 2) Web Clipboard API — works in a real browser (and newer WebViews).
    try {
      if (typeof navigator !== 'undefined' && navigator.clipboard?.readText) {
        return await navigator.clipboard.readText();
      }
    } catch {
      /* blocked */
    }

    return null;
  }
}
