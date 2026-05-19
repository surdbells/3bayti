/**
 * Public surface of the i18n module.
 *
 * App code should import from this barrel rather than reaching into
 * individual files, so refactoring internals doesn't ripple outward.
 */

export { LocaleService } from './locale.service';
export { provideI18n } from './i18n.providers';
export {
  DEFAULT_LOCALE,
  IS_RTL,
  LOCALE_COOKIE,
  LOCALE_LABELS,
  LOCALES,
  isLocale,
  normalizeLocale,
} from './locale.types';
export type { Locale } from './locale.types';
