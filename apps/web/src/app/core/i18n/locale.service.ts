import {
  Injectable,
  Signal,
  signal,
  inject,
  PLATFORM_ID,
  REQUEST,
  RESPONSE_INIT,
  DOCUMENT,
  computed,
} from '@angular/core';
import { isPlatformBrowser, isPlatformServer } from '@angular/common';
import { TranslateService } from '@ngx-translate/core';
import {
  DEFAULT_LOCALE,
  IS_RTL,
  LOCALE_COOKIE,
  LOCALE_COOKIE_MAX_AGE_SECONDS,
  Locale,
  LOCALES,
  normalizeLocale,
  isLocale,
} from './locale.types';

/**
 * LocaleService — chooses and applies the active locale.
 *
 * Resolution precedence (highest to lowest)
 * ------------------------------------------
 *   1. SSR: cookie `bayti_locale` (carries last user choice across requests)
 *   2. SSR: `Accept-Language` header (browser preference on first visit)
 *   3. Client: cookie `bayti_locale` (set by SSR; hydration reads same)
 *   4. Client: `navigator.language` (fallback if no cookie present)
 *   5. DEFAULT_LOCALE
 *
 * Authenticated users
 * -------------------
 * Auth integration is added in Y.1-B (`AuthService` watches `currentUser`
 * and calls `setLocale(user.locale)` on login + PATCHes /v3/me/profile
 * on switch). For Y.1-A we only handle anonymous resolution.
 *
 * SSR / hydration consistency
 * ---------------------------
 * The server-rendered HTML's `<html lang>` and `dir` attributes must
 * match what the client computes on hydration, or Angular emits a
 * hydration mismatch warning. We achieve this by:
 *   - SSR sets the cookie if not already set (so the client sees it)
 *   - Client reads the cookie first; never falls back to navigator.language
 *     unless cookie is missing (which only happens on the very first
 *     visit if SSR didn't run — rare)
 *
 * Why a service rather than an Angular provider factory
 * -----------------------------------------------------
 * The locale can change at runtime (header switcher). A static
 * `LOCALE_ID` provider can't do that. The service holds a signal,
 * pushes into TranslateService, and updates `<html>` reactively.
 */
@Injectable({ providedIn: 'root' })
export class LocaleService {
  private readonly platformId = inject(PLATFORM_ID);
  private readonly document = inject(DOCUMENT);
  private readonly translate = inject(TranslateService);

  /* SSR-only injections — null on the browser. We use { optional: true }
     because in some test setups these may be absent even server-side. */
  private readonly request = inject(REQUEST, { optional: true });
  private readonly responseInit = inject(RESPONSE_INIT, { optional: true });

  /* Current locale — signal so views can react. Components inject the
     service and bind to `current` directly, or to `dir` / `lang` for
     attribute bindings. */
  private readonly _current = signal<Locale>(DEFAULT_LOCALE);
  readonly current: Signal<Locale> = this._current.asReadonly();
  readonly dir = computed<'rtl' | 'ltr'>(() => (IS_RTL[this._current()] ? 'rtl' : 'ltr'));
  readonly isRtl = computed(() => IS_RTL[this._current()]);
  readonly supported: readonly Locale[] = LOCALES;

  /**
   * Initialise the service.
   *
   * Called once per request from APP_INITIALIZER (registered in
   * i18n.providers.ts). On the server it reads cookie/Accept-Language
   * and writes a Set-Cookie on the response. On the client it reads
   * the cookie (set by SSR) or falls back to navigator.language.
   *
   * Returns a promise that resolves once translations are loaded
   * for the resolved locale, so the app's first paint already has
   * translated strings.
   */
  async initialize(): Promise<void> {
    const resolved = this.resolve();
    this._current.set(resolved);
    this.applyToDocument(resolved);
    this.persistCookie(resolved);

    /* TranslateService is configured with addLangs + setFallbackLang
       in provideI18n; here we just activate. `use()` returns an
       Observable that emits when the JSON file is loaded; awaiting
       it ensures the first paint is already translated. */
    await this.translate.use(resolved);
  }

  /**
   * Switch to a different locale at runtime (header switcher).
   *
   * Side effects:
   *   - Updates the signal
   *   - Applies <html lang> + <html dir>
   *   - Persists the cookie on the BROWSER (we can't write Set-Cookie
   *     after SSR is done; for SSR-time changes, callers should use
   *     `initialize()` instead)
   *   - Loads the new locale's translations
   */
  async setLocale(next: Locale): Promise<void> {
    if (!isLocale(next) || next === this._current()) {
      return;
    }
    this._current.set(next);
    this.applyToDocument(next);
    this.persistCookie(next);
    await this.translate.use(next);
  }

  /* ----------------------------------------------------------------
     Internals
     ---------------------------------------------------------------- */

  /**
   * Run the resolution precedence chain.
   *
   * Pure function modulo `inject()` reads; covered by unit tests with
   * mocked PLATFORM_ID / REQUEST / cookies.
   */
  private resolve(): Locale {
    /* Server-side: look at cookie header first, then Accept-Language */
    if (isPlatformServer(this.platformId)) {
      const cookieLocale = this.readCookieFromRequest();
      if (cookieLocale !== null) {
        return cookieLocale;
      }
      const acceptLanguage = this.readAcceptLanguageFromRequest();
      if (acceptLanguage !== null) {
        return acceptLanguage;
      }
      return DEFAULT_LOCALE;
    }

    /* Browser-side: cookie first (set by SSR), then navigator.language */
    if (isPlatformBrowser(this.platformId)) {
      const cookieLocale = this.readCookieFromDocument();
      if (cookieLocale !== null) {
        return cookieLocale;
      }
      const navigatorLocale = this.readNavigatorLocale();
      if (navigatorLocale !== null) {
        return navigatorLocale;
      }
      return DEFAULT_LOCALE;
    }

    /* Some non-DOM platform (web worker, test, etc.) — fall back. */
    return DEFAULT_LOCALE;
  }

  /**
   * Read the bayti_locale cookie from the incoming SSR request.
   * Returns null if no cookie or value is unsupported.
   */
  private readCookieFromRequest(): Locale | null {
    if (this.request === null) return null;
    const header = this.request.headers.get('cookie');
    if (header === null) return null;
    return this.parseCookieHeader(header, LOCALE_COOKIE);
  }

  /**
   * Read the first valid Accept-Language entry from the SSR request.
   * Doesn't implement full quality-value parsing — that's overkill
   * for two locales. We just check whether `ar` appears anywhere.
   */
  private readAcceptLanguageFromRequest(): Locale | null {
    if (this.request === null) return null;
    const header = this.request.headers.get('accept-language');
    if (header === null) return null;

    /* Example header: "ar-AE,ar;q=0.9,en;q=0.8"
       Split on commas, take the first locale code from each entry,
       normalise to short tag, return the first one we support. */
    const entries = header.split(',');
    for (const entry of entries) {
      const code = entry.split(';')[0]?.trim() ?? '';
      const short = normalizeLocale(code);
      /* normalizeLocale always returns a supported locale (falling
         back to DEFAULT_LOCALE on unsupported input). We only want
         to count it as a hit if the input genuinely matched, so
         re-check against the original. */
      if (short !== DEFAULT_LOCALE || code.toLowerCase().startsWith(DEFAULT_LOCALE)) {
        return short;
      }
    }
    return null;
  }

  /**
   * Read the bayti_locale cookie from document.cookie.
   * Browser-only.
   */
  private readCookieFromDocument(): Locale | null {
    /* document.cookie is "key=value; key=value" */
    const cookieString = this.document.cookie ?? '';
    return this.parseCookieHeader(cookieString, LOCALE_COOKIE);
  }

  /**
   * Read navigator.language. Browser-only.
   */
  private readNavigatorLocale(): Locale | null {
    /* defaultView is the window in SSR-aware Angular; on the client
       it's globalThis.window. nav.language may be undefined in tests. */
    const nav = this.document.defaultView?.navigator;
    if (!nav || !nav.language) return null;
    const short = normalizeLocale(nav.language);
    /* Only count as a hit if the locale was genuinely supported.
       normalizeLocale falls back to DEFAULT_LOCALE on miss, so we
       have to re-check. */
    if (short !== DEFAULT_LOCALE) return short;
    if (nav.language.toLowerCase().startsWith(DEFAULT_LOCALE)) return DEFAULT_LOCALE;
    return null;
  }

  /**
   * Parse a Cookie or document.cookie header and return the named cookie's
   * value, normalised to a supported Locale or null.
   *
   * Defensive: cookies with embedded '=' in values are handled by
   * splitting on the first '=' only.
   */
  private parseCookieHeader(header: string, name: string): Locale | null {
    const parts = header.split(/;\s*/);
    for (const part of parts) {
      const eqIndex = part.indexOf('=');
      if (eqIndex < 0) continue;
      const key = part.substring(0, eqIndex).trim();
      if (key !== name) continue;
      const rawValue = part.substring(eqIndex + 1).trim();
      /* Cookie values may be URI-encoded. Decode defensively. */
      let decoded: string;
      try {
        decoded = decodeURIComponent(rawValue);
      } catch {
        decoded = rawValue;
      }
      return isLocale(decoded) ? decoded : null;
    }
    return null;
  }

  /**
   * Persist the locale to a cookie.
   *
   *   - On the server: writes Set-Cookie via RESPONSE_INIT.headers
   *   - On the browser: writes document.cookie
   *
   * Idempotent: writing the same cookie twice is a no-op.
   */
  private persistCookie(locale: Locale): void {
    if (isPlatformServer(this.platformId)) {
      if (this.responseInit === null) return;
      /* RESPONSE_INIT.headers may be undefined; initialise lazily.
         Multiple Set-Cookie headers can coexist (e.g. session +
         locale); use Headers API to append rather than overwrite. */
      const headers =
        this.responseInit.headers instanceof Headers
          ? this.responseInit.headers
          : new Headers(this.responseInit.headers ?? undefined);
      const cookieValue = this.buildCookieString(locale);
      headers.append('set-cookie', cookieValue);
      this.responseInit.headers = headers;
      return;
    }

    if (isPlatformBrowser(this.platformId)) {
      /* document.cookie assignment sets one cookie per assignment. */
      this.document.cookie = this.buildCookieString(locale);
    }
  }

  /**
   * Build a `name=value; ...attributes` cookie string.
   *
   * Attributes:
   *   - Path=/             — visible to every route (SSR + BFF + client)
   *   - Max-Age=31536000   — 1 year (long-lived; user choice persists)
   *   - SameSite=Lax       — locale isn't security-sensitive; Lax is fine
   *   - Secure (prod only) — sent only over HTTPS in production
   *
   * We don't set HttpOnly because the browser needs to read this on
   * hydration to avoid a mismatch with SSR.
   */
  private buildCookieString(locale: Locale): string {
    const parts = [
      `${LOCALE_COOKIE}=${locale}`,
      'Path=/',
      `Max-Age=${LOCALE_COOKIE_MAX_AGE_SECONDS}`,
      'SameSite=Lax',
    ];

    /* In SSR contexts the request's `protocol` tells us whether to
       set Secure. On the browser, location.protocol is the source of
       truth. Defensive: when in doubt, set Secure (most modern
       browsers tolerate Secure on http://localhost during dev). */
    const isHttps = this.isSecureContext();
    if (isHttps) {
      parts.push('Secure');
    }

    return parts.join('; ');
  }

  private isSecureContext(): boolean {
    if (isPlatformServer(this.platformId) && this.request !== null) {
      /* Edge runtimes pass `x-forwarded-proto` from the upstream proxy. */
      const proto =
        this.request.headers.get('x-forwarded-proto') ??
        new URL(this.request.url).protocol.replace(':', '');
      return proto === 'https';
    }
    if (isPlatformBrowser(this.platformId)) {
      return this.document.defaultView?.location?.protocol === 'https:';
    }
    return false;
  }

  /**
   * Apply <html lang> and <html dir> to the document.
   *
   * Works on both SSR (where the document is the rendering buffer)
   * and the browser (where it's the live DOM).
   */
  private applyToDocument(locale: Locale): void {
    const html = this.document.documentElement;
    if (!html) return;
    html.setAttribute('lang', locale);
    html.setAttribute('dir', IS_RTL[locale] ? 'rtl' : 'ltr');
  }
}
