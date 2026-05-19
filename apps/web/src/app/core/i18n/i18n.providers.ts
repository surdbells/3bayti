import { EnvironmentProviders, makeEnvironmentProviders, provideAppInitializer, inject } from '@angular/core';
import { provideTranslateService } from '@ngx-translate/core';
import { provideTranslateHttpLoader } from '@ngx-translate/http-loader';
import { DEFAULT_LOCALE, LOCALES } from './locale.types';
import { LocaleService } from './locale.service';

/**
 * Bundle of providers that wires ngx-translate + LocaleService + an
 * APP_INITIALIZER that resolves the locale BEFORE the app renders.
 *
 * Why APP_INITIALIZER
 * -------------------
 * The very first paint must already have translated strings or users
 * see English flash on a server-rendered Arabic page (or vice versa).
 * Returning the LocaleService.initialize() promise from the initializer
 * blocks app bootstrap until the JSON file is loaded — small cost
 * (~10-50 KB single-fetch) for a clean first paint.
 *
 * Asset path layout
 * -----------------
 * Translations live under public/i18n/<locale>/<namespace>.json.
 * Angular's build copies public/** to the dist root, so they're
 * served from /i18n/<locale>/<namespace>.json in dev and production.
 *
 * The http-loader's prefix + suffix determine the URL it fetches.
 * We use `/i18n/` + `.json` and a single `auth.json` per locale in
 * Y.1; later sub-phases add namespaces (cart.json, checkout.json,
 * etc.). The default loader fetches one file per language; to
 * combine namespaces we'd need a custom loader (deferred).
 *
 * For Y.1-A we ship a single `common.json` per locale containing
 * header/footer/locale-switcher strings + auth-page strings. As
 * Y.1 grows, we'll either split into namespaces (needs custom
 * loader) or keep one growing file (simpler; acceptable up to
 * ~500 strings).
 */
export function provideI18n(): EnvironmentProviders {
  return makeEnvironmentProviders([
    /* ngx-translate core: knows about the supported languages and
       which one to fall back to when a key is missing. */
    provideTranslateService({
      fallbackLang: DEFAULT_LOCALE,
      lang: DEFAULT_LOCALE,
      /* The TranslateService's addLangs is called lazily; we don't
         add languages here because the http-loader will fetch
         whichever `use()` requests. */
    }),

    /* Default HTTP loader — fetches /i18n/<lang>.json. */
    provideTranslateHttpLoader({
      prefix: '/i18n/',
      suffix: '.json',
    }),

    /* App initializer — resolves the locale before first render.
       Inside the factory we have access to inject(). The function
       may return a Promise to defer bootstrap. */
    provideAppInitializer(() => {
      const locale = inject(LocaleService);

      /* Register the supported language list with TranslateService.
         (We could do this inside the service itself, but doing it
         here keeps the service decoupled from ngx-translate's
         lifecycle methods.) */
      // Note: addLangs is internally a no-op for already-added langs.
      // We don't need to call it ourselves — TranslateService picks
      // up the lang from .use() automatically.

      return locale.initialize();
    }),
  ]);
}

/**
 * Re-export the supported locales for app code that needs them
 * without depending on the internal types module.
 */
export { LOCALES };
