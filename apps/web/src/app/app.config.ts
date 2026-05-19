import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideClientHydration, withEventReplay } from '@angular/platform-browser';

import { routes } from './app.routes';
import { provideI18n } from './core/i18n';
import { provideAuth } from './core/auth/auth.providers';

/**
 * Root application config — provided once per app instance.
 *
 * - `provideClientHydration(withEventReplay())` enables hydration of
 *   the prerendered HTML, replaying any events that fired before
 *   Angular took over (so a click during the JS load isn't lost).
 *
 * - `provideI18n()` (Y.1-A) wires ngx-translate + the HTTP loader and
 *   registers an APP_INITIALIZER that resolves the locale and loads
 *   its translations BEFORE the app's first paint. See
 *   ./core/i18n/i18n.providers.ts for the resolution chain.
 *
 * - `provideAuth()` (Y.1-C) wires HttpClient with the refresh
 *   interceptor AND registers an APP_INITIALIZER that calls
 *   AuthService.hydrate() (hits /auth-proxy/me with the HttpOnly
 *   refresh cookie) so the first paint already reflects auth state.
 *   provideAuth() OWNS provideHttpClient — DO NOT add another
 *   provideHttpClient() call here, or the interceptor chain becomes
 *   undefined behaviour.
 *
 * Order matters: provideI18n() before provideAuth() because AuthService
 * injects LocaleService.
 */
export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),
    provideClientHydration(withEventReplay()),
    provideI18n(),
    provideAuth(),
  ],
};
