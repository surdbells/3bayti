import { EnvironmentProviders, makeEnvironmentProviders, provideAppInitializer, inject } from '@angular/core';
import { withInterceptors, provideHttpClient, withFetch } from '@angular/common/http';
import { AuthService } from './auth.service';
import { refreshInterceptor } from './refresh.interceptor';

/**
 * provideAuth() — wires the auth interceptor and hydrates the session
 * at app bootstrap.
 *
 * Why APP_INITIALIZER for hydration
 * ---------------------------------
 * On both SSR and the client, the very first paint should reflect
 * the user's signed-in state — otherwise the page flashes "Sign in"
 * for half a second before discovering the cookie session and
 * swapping to the user's name. Returning the hydrate() promise from
 * the initializer delays bootstrap until /auth-proxy/me has resolved
 * (either to a user payload or to a 401). For SSR this also seeds
 * TransferState so the client doesn't repeat the call.
 *
 * Cost
 * ----
 * One BFF round-trip on every page load. Modest (~50-100ms on a warm
 * lambda) and well within the budget for a marketplace where the
 * vast majority of pages benefit from knowing the user is logged in.
 *
 * Order of registration
 * ---------------------
 * provideAuth() must be added to app.config.ts AFTER provideI18n()
 * because AuthService injects LocaleService inside its constructor.
 * The DI container resolves them in declaration order; getting it
 * wrong manifests as a "no provider for LocaleService" at runtime,
 * not at compile time.
 *
 * Note
 * ----
 * This function adds the `refreshInterceptor` to provideHttpClient
 * via `withInterceptors`. The root `provideHttpClient(withFetch())`
 * in app.config.ts should be REMOVED in favour of this one — having
 * two `provideHttpClient` calls leads to undefined behaviour in
 * which one wins. We do it this way (own the HttpClient setup) to
 * avoid the more fragile alternative (multiple HTTP_INTERCEPTORS).
 */
export function provideAuth(): EnvironmentProviders {
  return makeEnvironmentProviders([
    /* Replace the previous provideHttpClient call. withFetch() keeps
       the same edge-runtime compatibility as the old config. */
    provideHttpClient(withFetch(), withInterceptors([refreshInterceptor])),

    /* Hydrate the session before the app's first paint. */
    provideAppInitializer(() => {
      const auth = inject(AuthService);
      return auth.hydrate();
    }),
  ]);
}
