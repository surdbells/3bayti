import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withFetch } from '@angular/common/http';
import { provideClientHydration, withEventReplay } from '@angular/platform-browser';

import { routes } from './app.routes';
import { provideI18n } from './core/i18n';

/**
 * Root application config — provided once per app instance.
 *
 * - `provideHttpClient(withFetch())` uses native fetch under the hood,
 *   which is required for Angular Universal to work on edge runtimes
 *   (Cloudflare Workers, Vercel Edge) where XHR isn't available. Even
 *   for our prerender + Node SSR setup, fetch is the modern path.
 *
 * - `provideClientHydration(withEventReplay())` enables hydration of
 *   the prerendered HTML, replaying any events that fired before
 *   Angular took over (so a click during the JS load isn't lost).
 *
 * - `provideI18n()` (Y.1-A) wires ngx-translate + the HTTP loader and
 *   registers an APP_INITIALIZER that resolves the locale and loads
 *   its translations BEFORE the app's first paint. See
 *   ./core/i18n/i18n.providers.ts for the resolution chain.
 */
export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(withFetch()),
    provideClientHydration(withEventReplay()),
    provideI18n(),
  ],
};
