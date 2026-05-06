import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withFetch } from '@angular/common/http';
import { provideClientHydration, withEventReplay } from '@angular/platform-browser';

import { routes } from './app.routes';

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
 */
export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideHttpClient(withFetch()),
    provideClientHydration(withEventReplay()),
  ],
};
