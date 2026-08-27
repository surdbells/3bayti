/* ============================================================================
   i18n, Locale types
   ----------------------------------------------------------------------------
   Single source of truth for what locales the web app supports.
   Adding a locale means: add a code here, add translation files under
   public/i18n/<code>/, update LOCALE_LABELS, and (if RTL) update IS_RTL.
   ============================================================================ */

/**
 * Supported locale codes (ISO 639-1 short tags).
 *
 * Two locales in M3.2.Y.1:
 *   - 'en', default, English
 *   - 'ar', Arabic (MSA, formal register, matches the X.7 email
 *     translation conventions)
 *
 * BCP-47 region tags ('en-AE', 'ar-AE') are normalised to short tags
 * by LocaleService::normalize(). We don't ship per-region copy.
 */
export const LOCALES = ['en', 'ar'] as const;
export type Locale = (typeof LOCALES)[number];

/**
 * Default locale when nothing else can be determined.
 * Matches the API's LocaleResolver fallback (X.7).
 */
export const DEFAULT_LOCALE: Locale = 'en';

/**
 * Cookie name used to persist the user's locale preference across
 * sessions for anonymous visitors. Authenticated users sync this
 * value with `User.locale` via PATCH /v3/me/profile (handled later
 * in Y.1-B's AuthService).
 *
 * Path is '/' so SSR and BFF routes both see it. SameSite=Lax is
 * fine, locale isn't a security-sensitive value. 1-year TTL.
 */
export const LOCALE_COOKIE = 'bayti_locale';
export const LOCALE_COOKIE_MAX_AGE_SECONDS = 60 * 60 * 24 * 365; // 1 year

/**
 * Human-readable labels for the locale switcher.
 * Each locale's label is in its own script, the switcher shows
 * each option as it would read to a speaker of that locale.
 */
export const LOCALE_LABELS: Record<Locale, string> = {
  en: 'English',
  ar: 'العربية',
};

/**
 * Whether each locale renders right-to-left.
 * Drives the `<html dir>` attribute and Tailwind `rtl:` variants.
 */
export const IS_RTL: Record<Locale, boolean> = {
  en: false,
  ar: true,
};

/**
 * Type guard. Useful when accepting unvalidated input from a cookie,
 * query string, or Accept-Language header.
 */
export function isLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (LOCALES as readonly string[]).includes(value);
}

/**
 * Normalise an incoming locale string to a supported short tag.
 *
 *   - Strips region: 'en-AE' → 'en', 'ar-SA' → 'ar'
 *   - Lower-cases: 'EN' → 'en'
 *   - Falls back to DEFAULT_LOCALE on anything unsupported.
 *
 * Mirrors LocaleResolver::normalizeToShortTag() in apps/api (X.7).
 */
export function normalizeLocale(value: string | null | undefined): Locale {
  if (!value || typeof value !== 'string') {
    return DEFAULT_LOCALE;
  }
  const short = value.toLowerCase().split('-')[0]?.trim() ?? '';
  return isLocale(short) ? short : DEFAULT_LOCALE;
}
