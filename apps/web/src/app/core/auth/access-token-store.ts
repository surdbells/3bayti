import {
  Injectable,
  Signal,
  signal,
  computed,
  inject,
  PLATFORM_ID,
  TransferState,
  makeStateKey,
  StateKey,
} from '@angular/core';
import { isPlatformBrowser, isPlatformServer } from '@angular/common';

/**
 * Snapshot of the access token + its expiry. The two travel together
 * — we never want to know the token without knowing when it dies.
 */
export interface AccessTokenSnapshot {
  /** The raw JWT access token. */
  token: string;
  /** ISO 8601 ATOM datetime when the token expires. */
  expiresAt: string;
}

/**
 * SSR ↔ client handoff key. When the server resolves the session by
 * calling /auth-proxy/me with the user's cookies, the access token
 * is dropped into TransferState so the client can pick it up on
 * hydration instead of making a duplicate request.
 *
 * Why TransferState rather than embedding in the HTML directly
 * ------------------------------------------------------------
 * Angular's TransferState mechanism serialises into a single <script>
 * tag with `id="ng-state"` (or per Angular's current convention) and
 * the client reads from there during hydration. This avoids us
 * hand-rolling our own serialization + parsing. Same security
 * properties: anything in TransferState is visible in the page
 * source. We DO NOT put the refresh token in here — that stays in
 * the HttpOnly cookie and never enters the JS context.
 */
const ACCESS_TOKEN_STATE_KEY: StateKey<AccessTokenSnapshot | null> = makeStateKey<AccessTokenSnapshot | null>(
  'AUTH_ACCESS_TOKEN',
);

/**
 * AccessTokenStore — in-memory access-token cache.
 *
 * Responsibilities
 * ----------------
 *   - Hold the current access token + expiry in a signal.
 *   - On SSR: write the snapshot into TransferState before render
 *     finishes so the client hydrates without a duplicate /me call.
 *   - On the browser at hydration time: read TransferState and seed
 *     the signal.
 *   - Never persist to localStorage / sessionStorage / cookies.
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
 *   - `current()` returns either a snapshot or null — never a partial.
 *   - `isExpired()` is a pure derivation from `current()` + Date.now().
 *   - `clear()` always succeeds and is idempotent.
 */
@Injectable({ providedIn: 'root' })
export class AccessTokenStore {
  private readonly platformId = inject(PLATFORM_ID);
  private readonly transferState = inject(TransferState);

  private readonly _current = signal<AccessTokenSnapshot | null>(null);

  /** Current token snapshot (or null when unauthenticated). */
  readonly current: Signal<AccessTokenSnapshot | null> = this._current.asReadonly();

  /** True if a token is present and not yet expired. */
  readonly hasValidToken = computed(() => {
    const snap = this._current();
    if (snap === null) return false;
    return new Date(snap.expiresAt).getTime() > Date.now();
  });

  constructor() {
    /* On the browser, attempt to hydrate from TransferState set by the
       server-side render. If the state contains a snapshot AND we don't
       already have one, seed the signal.

       Using `get(KEY, defaultValue)` with `null` as default returns
       null both when the key was set to null AND when it was never
       set; we read once and clear regardless. */
    if (isPlatformBrowser(this.platformId)) {
      const hydrated = this.transferState.get(ACCESS_TOKEN_STATE_KEY, null);
      if (hydrated !== null) {
        /* Defensive: only seed if expiresAt is in the future. A
           hydration that arrives after the token has already
           expired (slow client + short TTL) shouldn't paint the
           user as logged-in. The refresh interceptor will handle
           the swap. */
        if (new Date(hydrated.expiresAt).getTime() > Date.now()) {
          this._current.set(hydrated);
        }
      }
      /* Remove the key from TransferState so it doesn't ship to
         a possible second render (the page may be hydrated more
         than once in edge cases, e.g. dev hot reload). The token
         is sensitive even though it's short-lived; minimise its
         exposure. */
      this.transferState.remove(ACCESS_TOKEN_STATE_KEY);
    }
  }

  /**
   * Set or replace the current token snapshot.
   *
   * Called from AuthService after login / register-confirm / refresh /
   * reset-confirm. On the server, also writes into TransferState so
   * the client picks it up on hydration.
   */
  set(snapshot: AccessTokenSnapshot): void {
    this._current.set(snapshot);
    if (isPlatformServer(this.platformId)) {
      this.transferState.set(ACCESS_TOKEN_STATE_KEY, snapshot);
    }
  }

  /**
   * Clear the token. Idempotent.
   *
   * Called from AuthService after logout, refresh failure, or any
   * other event that should drop the session.
   */
  clear(): void {
    this._current.set(null);
    if (isPlatformServer(this.platformId)) {
      this.transferState.remove(ACCESS_TOKEN_STATE_KEY);
    }
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
