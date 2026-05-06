/**
 * Auth header provider.
 *
 * The client doesn't manage auth state itself — that's the app's job
 * (AuthService in apps/web). The client just asks "what's the current
 * Authorization header?" before each request.
 *
 * The signature is intentionally simple. M1 adds refresh-on-401 logic
 * by returning a Promise from the provider (the client awaits it on
 * 401 responses). For now, a synchronous string-or-null is enough.
 */

export type AuthHeaderProvider = () => string | null;

/**
 * Build a basic provider that reads a Bearer token from a getter.
 *
 * Usage:
 *   const auth = createBearerProvider(() => localStorage.getItem('access_token'));
 *   const client = createClient({ ..., authProvider: auth });
 */
export function createBearerProvider(tokenGetter: () => string | null | undefined): AuthHeaderProvider {
  return () => {
    const token = tokenGetter();
    return token ? `Bearer ${token}` : null;
  };
}
