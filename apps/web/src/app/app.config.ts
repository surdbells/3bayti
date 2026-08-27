import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, TitleStrategy } from '@angular/router';

import { routes, I18nTitleStrategy } from './app.routes';
import { provideI18n } from './core/i18n';
import { provideAuth } from './core/auth/auth.providers';
import { provideMonitoring } from './core/monitoring';

/**
 * Root application config, provided once per app instance.
 *
 * This is a pure client-side-rendered (CSR) SPA deployed as static assets
 * on Cloudflare Pages; there is no server hydration. The only server-side
 * piece is the auth-proxy Pages Function (functions/auth-proxy), which
 * AuthService talks to over /auth-proxy/*.
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
 *   provideAuth() OWNS provideHttpClient, DO NOT add another
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
    provideI18n(),
    provideAuth(),
    provideMonitoring(),
    { provide: TitleStrategy, useClass: I18nTitleStrategy },
  ],
};
