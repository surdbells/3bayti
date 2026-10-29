import { Injectable, Signal, signal, computed } from '@angular/core';

/**
 * Snapshot of the access token + its expiry. The two travel together
 *, we never want to know the token without knowing when it dies.
 */
export interface AccessTokenSnapshot {
  /** The raw JWT access token. */
  token: string;
  /** ISO 8601 ATOM datetime when the token expires. */
  expiresAt: string;
}

/**
 * AccessTokenStore, in-memory access-token cache.
 *
 * Responsibilities
 * ----------------
 *   - Hold the current access token + expiry in a signal.
 *   - Never persist to localStorage / sessionStorage / cookies.
 *
 * The token is seeded on the client by AuthService.hydrate(), which
 * calls /auth-proxy/me with the HttpOnly refresh cookie and adopts the
 * returned access token. The long-lived refresh token never enters the
 * JS context, it lives only in the HttpOnly cookie owned by the
 * auth-proxy Pages Function.
 *
 * Why a separate store rather than living inside AuthService
 * ----------------------------------------------------------
 *   - AuthService imports this and also TranslateService, HttpClient,
 *     LocaleService, etc. The refresh interceptor needs to read the
 *     token WITHOUT triggering circular DI through AuthService's
 *     other dependencies. Splitting the store out is the cleanest
 *     way to avoid the cycle.
 *   - Unit tests for the interceptor can mock just this store
 *     without standing up all of AuthService.
 *
 * Invariants
 * ----------
 *   - `current()` returns either a snapshot or null, never a partial.
 *   - `hasValidToken()` is a pure derivation from `current()` + Date.now().
 *   - `clear()` always succeeds and is idempotent.
 */
@Injectable({ providedIn: 'root' })
export class AccessTokenStore {
  private readonly _current = signal<AccessTokenSnapshot | null>(null);

  /** Current token snapshot (or null when unauthenticated). */
  readonly current: Signal<AccessTokenSnapshot | null> = this._current.asReadonly();

  /**
   * True if a token is present and not yet expired BY THE DEVICE CLOCK.
   *
   * Not a session signal: a skewed device clock or a token that simply
   * aged out must not make a signed-in customer look signed out. Session
   * state is AuthService.isAuthenticated (currentUser-based); staleness is
   * handled by the pre-emptive refresh and the 401-driven refresh.
   */
  readonly hasValidToken = computed(() => {
    const snap = this._current();
    if (snap === null) return false;
    return new Date(snap.expiresAt).getTime() > Date.now();
  });

  /**
   * Set or replace the current token snapshot.
   *
   * Called from AuthService after login / register-confirm / refresh /
   * reset-confirm.
   */
  set(snapshot: AccessTokenSnapshot): void {
    this._current.set(snapshot);
  }

  /**
   * Clear the token. Idempotent.
   *
   * Called from AuthService after an explicit logout or a definitive
   * session-ended rejection (never after a transient refresh failure).
   */
  clear(): void {
    this._current.set(null);
  }

  /**
   * Read the raw access token string, or null.
   *
   * Convenience for interceptors that just want the token to put
   * on the Authorization header.
   */
  getToken(): string | null {
    return this._current()?.token ?? null;
  }
}

/**
 * Lifetime of a JWT access token in ms (exp - iat), or null when the
 * token isn't a decodable JWT carrying both numeric claims.
 *
 * Both claims come from the SERVER clock, so their difference is immune
 * to device clock skew, unlike comparing `expires_at` with Date.now().
 * Read-only decode: the signature is the API's business, not ours.
 */
export function jwtLifetimeMs(token: string): number | null {
  const parts = token.split('.');
  if (parts.length !== 3) return null;
  try {
    const b64 = parts[1].replace(/-/g, '+').replace(/_/g, '/');
    const padded = b64 + '='.repeat((4 - (b64.length % 4)) % 4);
    const claims = JSON.parse(atob(padded)) as Record<string, unknown>;
    const iat = claims['iat'];
    const exp = claims['exp'];
    if (typeof iat !== 'number' || typeof exp !== 'number' || exp <= iat) return null;
    return (exp - iat) * 1000;
  } catch {
    return null;
  }
}
