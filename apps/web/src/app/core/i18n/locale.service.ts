import {
  Injectable,
  Signal,
  signal,
  inject,
  DOCUMENT,
  computed,
} from '@angular/core';
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
 *   1. Cookie `bayti_locale` (carries the last explicit user choice)
 *   2. `navigator.language` (browser preference on first visit)
 *   3. DEFAULT_LOCALE
 *
 * Authenticated users
 * -------------------
 * AuthService watches `currentUser` and calls `setLocale(user.locale)`
 * on login + PATCHes /v3/me/profile on switch (Y.1-B). This service only
 * handles anonymous resolution.
 *
 * Why a service rather than an Angular provider factory
 * -----------------------------------------------------
 * The locale can change at runtime (header switcher). A static
 * `LOCALE_ID` provider can't do that. The service holds a signal,
 * pushes into TranslateService, and updates `<html>` reactively.
 */
@Injectable({ providedIn: 'root' })
export class LocaleService {
  private readonly document = inject(DOCUMENT);
  private readonly translate = inject(TranslateService);

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
   * Called once at startup from APP_INITIALIZER (registered in
   * i18n.providers.ts). Reads the cookie (or falls back to
   * navigator.language), applies <html lang>/<dir>, persists the cookie,
   * and activates the locale's translations.
   *
   * Returns a promise that resolves once translations are loaded for the
   * resolved locale, so the app's first paint already has translated
   * strings.
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
   *   - Persists the cookie
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
   * Run the resolution precedence chain: cookie, then navigator.language,
   * then DEFAULT_LOCALE.
   *
   * Pure function modulo `inject()` reads; covered by unit tests with a
   * mocked DOCUMENT / cookies.
   */
  private resolve(): Locale {
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

  /**
   * Read the bayti_locale cookie from document.cookie.
   */
  private readCookieFromDocument(): Locale | null {
    /* document.cookie is "key=value; key=value" */
    const cookieString = this.document.cookie ?? '';
    return this.parseCookieHeader(cookieString, LOCALE_COOKIE);
  }

  /**
   * Read navigator.language and map it to a supported locale, or null.
   */
  private readNavigatorLocale(): Locale | null {
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
   * Persist the locale to the bayti_locale cookie (document.cookie).
   *
   * Idempotent: writing the same cookie twice is a no-op.
   */
  private persistCookie(locale: Locale): void {
    /* document.cookie assignment sets one cookie per assignment. */
    this.document.cookie = this.buildCookieString(locale);
  }

  /**
   * Build a `name=value; ...attributes` cookie string.
   *
   * Attributes:
   *   - Path=/             — visible to every route (BFF + client)
   *   - Max-Age=31536000   — 1 year (long-lived; user choice persists)
   *   - SameSite=Lax       — locale isn't security-sensitive; Lax is fine
   *   - Secure (https only) — sent only over HTTPS
   *
   * We don't set HttpOnly because the app reads this cookie client-side
   * to resolve the locale.
   */
  private buildCookieString(locale: Locale): string {
    const parts = [
      `${LOCALE_COOKIE}=${locale}`,
      'Path=/',
      `Max-Age=${LOCALE_COOKIE_MAX_AGE_SECONDS}`,
      'SameSite=Lax',
    ];

    /* Set Secure over HTTPS (location.protocol is the source of truth). */
    if (this.isSecureContext()) {
      parts.push('Secure');
    }

    return parts.join('; ');
  }

  private isSecureContext(): boolean {
    return this.document.defaultView?.location?.protocol === 'https:';
  }

  /**
   * Apply <html lang> and <html dir> to the document.
   */
  private applyToDocument(locale: Locale): void {
    const html = this.document.documentElement;
    if (!html) return;
    html.setAttribute('lang', locale);
    html.setAttribute('dir', IS_RTL[locale] ? 'rtl' : 'ltr');
  }
}
