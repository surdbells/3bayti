import {
  Injectable,
  Signal,
  signal,
  computed,
  effect,
  inject,
  DestroyRef,
} from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { catchError, firstValueFrom, of } from 'rxjs';
import { AccessTokenStore, AccessTokenSnapshot } from './access-token-store';
import { LocaleService } from '../i18n/locale.service';
import {
  AUTH_PROXY_BASE,
  AUTH_REFRESH_LEAD_TIME_MS,
} from './auth.tokens';
import type {
  AuthUser,
  BffLoginResponse,
  BffRefreshResponse,
  ConfirmInput,
  LoginInput,
  RegisterInput,
  RegisterResponse,
  ResetConfirmInput,
  ResetResponse,
  SendOtpResponse,
  OtpLoginSendInput,
  OtpLoginSendResponse,
  OtpLoginVerifyInput,
  RegisterInitiateInput,
  RegisterInitiateResponse,
  RegisterVerifyPhoneInput,
  RegisterVerifyPhoneResponse,
  RegisterSubmitInput,
  RegisterSubmitResponse,
  RegisterConfirmEmailInput,
  ValidatePhoneInput,
  ValidatePhoneResponse,
  ResetRequestInput,
} from './auth.types';

/**
 * AuthService — single source of truth for authentication state.
 *
 * Public surface
 * --------------
 *   - `currentUser`     — Signal<AuthUser | null>
 *   - `isAuthenticated` — Signal<boolean>
 *   - `accessToken`     — Signal<string | null>
 *   - `login()`, `register()`, `confirmRegistration()`, `refresh()`,
 *     `logout()`, `requestPasswordReset()`, `confirmPasswordReset()`,
 *     `resendOtp()`, `hydrate()`
 *
 * What this does NOT do
 * ---------------------
 *   - Doesn't manage refresh tokens. Refresh tokens are HttpOnly
 *     cookies owned by the BFF; AuthService just calls
 *     /auth-proxy/refresh and the BFF deals with the cookie swap.
 *   - Doesn't apply the `Authorization` header to outgoing requests.
 *     That's the refresh interceptor's job (refresh.interceptor.ts).
 *
 * Pre-emptive refresh
 * -------------------
 * On every successful login / confirm / refresh / reset-confirm, we
 * schedule a refresh AUTH_REFRESH_LEAD_TIME_MS (~60s) before the
 * access token's `expires_at`. If the scheduler fires while the app
 * is hidden / suspended, the next 401 from a real request triggers
 * the reactive refresh path instead. Both paths share the same
 * `refresh()` method, which is idempotent under concurrent calls
 * thanks to the single-flight promise in
 * refresh.interceptor.ts. (AuthService's `refresh()` itself does the
 * work; the interceptor's queueing layer sits on top.)
 *
 * Refresh scheduler
 * -----------------
 * The scheduler is armed inside applyAuthState() — i.e. only after a
 * real auth response sets a real token. We deliberately do NOT inject
 * ApplicationRef and wait for isStable: ApplicationRef participates in
 * the APP_INITIALIZER graph, so injecting it from a root service used
 * by an initializer creates an NG0200 circular dependency. Arming on
 * token-set is the correct trigger anyway — with no token there is
 * nothing to schedule.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly tokenStore = inject(AccessTokenStore);
  private readonly locale = inject(LocaleService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly proxyBase = inject(AUTH_PROXY_BASE);
  private readonly refreshLeadTimeMs = inject(AUTH_REFRESH_LEAD_TIME_MS);

  /* ---------- Reactive state ---------- */

  private readonly _currentUser = signal<AuthUser | null>(null);

  /** Current authenticated user (or null when not signed in). */
  readonly currentUser: Signal<AuthUser | null> = this._currentUser.asReadonly();

  /**
   * Replace the cached current-user with a fresh profile (e.g. after a
   * PATCH /me/profile in ProfileService). No-op when signed out, so a
   * stale profile response can't resurrect a logged-out session.
   */
  applyProfile(user: AuthUser): void {
    if (this._currentUser() !== null) {
      this._currentUser.set(user);
    }
  }

  /**
   * Adopt the fresh access token + user returned by a successful
   * PATCH /me/password. The password-change endpoint revokes ALL of
   * the user's refresh tokens (including the current session's) and
   * issues a new pair, so the caller MUST adopt the new access token
   * or the next request fails with a revoked token. Mirrors the
   * token-first / user-second / reschedule sequence of applyAuthState.
   *
   * No-op when signed out (defensive — a password change can only
   * happen for an authenticated user).
   */
  applyPasswordChange(input: {
    access_token: string;
    access_token_expires_at: string;
    user: AuthUser;
  }): void {
    if (this._currentUser() === null) {
      return;
    }
    this.tokenStore.set({
      token: input.access_token,
      expiresAt: input.access_token_expires_at,
    });
    this._currentUser.set(input.user);
    this.scheduleRefresh();
  }

  /** True when a user is signed in AND the access token is still valid. */
  readonly isAuthenticated = computed(
    () => this._currentUser() !== null && this.tokenStore.hasValidToken(),
  );

  /** The raw access token signal, derived from the store. */
  readonly accessToken = computed(() => this.tokenStore.getToken());

  /* ---------- Pre-emptive refresh scheduling ---------- */

  private refreshTimerId: ReturnType<typeof setTimeout> | null = null;

  /**
   * Single-flight refresh promise. While a refresh is in flight,
   * concurrent callers (interceptor + scheduler + manual) all await
   * the same promise rather than firing duplicate /refresh calls.
   * Cleared back to null in finally().
   */
  private inflightRefresh: Promise<boolean> | null = null;

  /**
   * Tracks the last locale value we pushed (or recognised as already
   * synced) to /v3/me/profile. Lets the locale-watch effect skip
   * redundant PATCH calls. Reset to null on logout so the next login
   * re-evaluates.
   */
  private lastPushedLocale: 'en' | 'ar' | null = null;

  constructor() {
    /* Clean up the scheduled timer when the service is destroyed.
       For a root-injected service this typically only happens on
       full app teardown, but the cleanup is cheap and defensive. */
    this.destroyRef.onDestroy(() => {
      if (this.refreshTimerId !== null) {
        clearTimeout(this.refreshTimerId);
        this.refreshTimerId = null;
      }
    });

    /* Locale → server sync (Y.1-J).

       When an authenticated user changes their locale via the header
       switcher, push that choice to the API so the next session (on
       another device, after cookie expiry, after a hard refresh, etc.)
       resumes in the chosen locale.

       This watches the LocaleService.current() signal. We deliberately
       do NOT push on the FIRST emit after login — the API just told us
       what the user's locale was (it set our local state via syncLocale
       on the auth response), so pushing it back would be redundant
       chatter. We track the last-pushed value to skip the no-op case.

       Failures are silent: a 5xx or auth race shouldn't surface a
       toast for what's essentially a preference sync. The next change
       will retry. */
    effect(() => {
      const desired = this.locale.current();
      const user = this._currentUser();
      if (user === null) {
        this.lastPushedLocale = null; /* Clear so next login re-syncs. */
        return;
      }
      if (this.lastPushedLocale === desired) return;
      if (user.locale === desired) {
        /* Local state already matches server — no need to PATCH. */
        this.lastPushedLocale = desired;
        return;
      }
      /* Fire and forget. */
      this.lastPushedLocale = desired;
      void this.pushLocaleToServer(desired);
    });
  }

  /* ---------- Public API ---------- */

  /**
   * Hydrate the session from the BFF.
   *
   * Called by an APP_INITIALIZER (see auth.providers.ts) so the very
   * first render already knows whether the user is signed in. The
   * BFF reads the HttpOnly refresh cookie and returns the current
   * access token + user, or a 401 if there's no valid session.
   *
   * Cookies are attached automatically by fetch() (same-origin), so
   * the BFF reads the HttpOnly refresh cookie to resolve the session.
   */
  async hydrate(): Promise<void> {
    try {
      const response = await firstValueFrom(
        this.http.get<BffLoginResponse>(`${this.proxyBase}/me`, {
          withCredentials: true,
        }),
      );
      this.applyAuthState(response);
    } catch (err) {
      /* Most likely a 401 (no session) — clear local state and
         move on. Don't surface the error to the UI; this is the
         initial check and the UI shouldn't show an error toast
         for "you're not logged in". */
      if (err instanceof HttpErrorResponse && err.status === 401) {
        this.applyLogoutState();
        return;
      }
      /* For other errors (network, 5xx, etc.) we also fall back
         to logged-out, but logging it helps debugging. */
      if (typeof console !== 'undefined') {
        console.warn('[AuthService] hydrate failed', err);
      }
      this.applyLogoutState();
    }
  }

  /**
   * Log in with email + password.
   *
   * On success:
   *   - Stores the access token in the in-memory store
   *   - Sets currentUser
   *   - Schedules pre-emptive refresh
   *   - Syncs locale if user's preferred locale differs from current
   *
   * Throws ApiError-like HttpErrorResponse on failure. The page
   * component is responsible for mapping the error to inline UI.
   */
  async login(input: LoginInput): Promise<AuthUser> {
    const response = await firstValueFrom(
      this.http.post<BffLoginResponse>(`${this.proxyBase}/login`, input, {
        withCredentials: true,
      }),
    );
    this.applyAuthState(response);
    return response.user;
  }

  /**
   * Register a new account. Returns the verification_id needed for
   * the OTP confirm step. NO tokens issued yet — the user is still
   * unverified.
   */
  async register(input: RegisterInput): Promise<RegisterResponse> {
    return firstValueFrom(
      this.http.post<RegisterResponse>(`${this.proxyBase}/register`, input, {
        withCredentials: true,
      }),
    );
  }

  /**
   * Confirm a registration OTP. On success, auto-logs in.
   */
  async confirmRegistration(input: ConfirmInput): Promise<AuthUser> {
    const response = await firstValueFrom(
      this.http.post<BffLoginResponse>(`${this.proxyBase}/confirm`, input, {
        withCredentials: true,
      }),
    );
    this.applyAuthState(response);
    return response.user;
  }

  /**
   * Resend a registration OTP. Returns a new verification_id (the
   * previous one is invalidated by the API).
   */
  async resendOtp(email: string): Promise<SendOtpResponse> {
    return firstValueFrom(
      this.http.post<SendOtpResponse>(
        `${this.proxyBase}/send-otp`,
        { email },
        { withCredentials: true },
      ),
    );
  }

  /**
   * Request a password reset. Always returns 200 (anti-enumeration);
   * the verification_id may be a fake-prefixed string for
   * non-existent emails — that's fine, the OTP verification step
   * will fail the same way as a bad code.
   */
  async requestPasswordReset(email: string): Promise<ResetResponse> {
    return firstValueFrom(
      this.http.post<ResetResponse>(
        `${this.proxyBase}/reset`,
        { email },
        { withCredentials: true },
      ),
    );
  }

  /**
   * Complete the password reset with OTP + new password. On success,
   * auto-logs in (the API returns a token pair).
   */
  async confirmPasswordReset(input: ResetConfirmInput): Promise<AuthUser> {
    const response = await firstValueFrom(
      this.http.post<BffLoginResponse>(`${this.proxyBase}/reset-confirm`, input, {
        withCredentials: true,
      }),
    );
    this.applyAuthState(response);
    return response.user;
  }

  /* =========================================================================
     Passwordless parity (#8) — mirrors the mobile v3 passwordless flows.
     All of these go through the BFF /auth-proxy so the cookie model is
     preserved: the two *verify* steps that mint a token-pair
     (otp-login/verify, register/confirm-email) route through the BFF's
     authSuccessMap (cookie set + refresh_token stripped) and the response
     is fed into applyAuthState(); every other step is a passthrough.
     ========================================================================= */

  /**
   * Send a passwordless login OTP over phone or email.
   *
   * Anti-enumeration: always returns a verification_id (even for an
   * unknown identifier). The page advances to the OTP screen regardless;
   * a bad/unknown identifier only fails at verifyOtpLogin().
   */
  async sendOtpLogin(input: OtpLoginSendInput): Promise<OtpLoginSendResponse> {
    return firstValueFrom(
      this.http.post<OtpLoginSendResponse>(
        `${this.proxyBase}/otp-login/send`,
        input,
        { withCredentials: true },
      ),
    );
  }

  /**
   * Verify a passwordless login OTP. On success the BFF returns the same
   * token-pair envelope as /login (refresh_token parked in the cookie),
   * so we apply auth state and the user is signed in.
   */
  async verifyOtpLogin(input: OtpLoginVerifyInput): Promise<AuthUser> {
    const response = await firstValueFrom(
      this.http.post<BffLoginResponse>(
        `${this.proxyBase}/otp-login/verify`,
        input,
        { withCredentials: true },
      ),
    );
    this.applyAuthState(response);
    return response.user;
  }

  /**
   * Register step 1 (phone) — initiate. Sends a phone OTP and returns the
   * verification_id consumed by registerVerifyPhone().
   */
  async registerInitiate(
    input: RegisterInitiateInput,
  ): Promise<RegisterInitiateResponse> {
    return firstValueFrom(
      this.http.post<RegisterInitiateResponse>(
        `${this.proxyBase}/register/initiate`,
        input,
        { withCredentials: true },
      ),
    );
  }

  /**
   * Register step 2 (verify phone) — exchanges the phone OTP for a
   * short-lived registration_token used to authorise registerSubmit().
   */
  async registerVerifyPhone(
    input: RegisterVerifyPhoneInput,
  ): Promise<RegisterVerifyPhoneResponse> {
    return firstValueFrom(
      this.http.post<RegisterVerifyPhoneResponse>(
        `${this.proxyBase}/register/verify-phone`,
        input,
        { withCredentials: true },
      ),
    );
  }

  /**
   * Register step 3 (details) — submit. Sends a fresh email OTP and
   * returns the verification_id consumed by registerConfirmEmail().
   */
  async registerSubmit(
    input: RegisterSubmitInput,
  ): Promise<RegisterSubmitResponse> {
    return firstValueFrom(
      this.http.post<RegisterSubmitResponse>(
        `${this.proxyBase}/register/submit`,
        input,
        { withCredentials: true },
      ),
    );
  }

  /**
   * Register step 4 (confirm email). On success the BFF returns a full
   * token-pair + user (same envelope as /confirm), so we apply auth state
   * and the new account is auto-logged-in.
   */
  async registerConfirmEmail(
    input: RegisterConfirmEmailInput,
  ): Promise<AuthUser> {
    const response = await firstValueFrom(
      this.http.post<BffLoginResponse>(
        `${this.proxyBase}/register/confirm-email`,
        input,
        { withCredentials: true },
      ),
    );
    this.applyAuthState(response);
    return response.user;
  }

  /**
   * Best-effort phone-availability probe for the register step-1 UX.
   * Returns { phone, available }; available=false means already
   * registered. Never a hard gate — the register/initiate 409 is.
   */
  async validatePhone(input: ValidatePhoneInput): Promise<ValidatePhoneResponse> {
    return firstValueFrom(
      this.http.post<ValidatePhoneResponse>(
        `${this.proxyBase}/validate-phone`,
        input,
        { withCredentials: true },
      ),
    );
  }

  /**
   * Two-channel password reset request (email | phone). Mirrors the
   * mobile reset flow. Anti-enumeration: always 200 with a
   * verification_id; the OTP confirm step (confirmPasswordReset) is where
   * an unknown identifier fails the same way as a bad code.
   */
  async requestPasswordResetChannel(
    input: ResetRequestInput,
  ): Promise<ResetResponse> {
    return firstValueFrom(
      this.http.post<ResetResponse>(`${this.proxyBase}/reset`, input, {
        withCredentials: true,
      }),
    );
  }

  /**
   * Refresh the access token using the HttpOnly refresh cookie.
   *
   * Single-flight: concurrent callers share one inflight promise.
   * Returns `true` if the refresh succeeded, `false` if it failed
   * (which means the session is dead and the user should be logged
   * out — callers should call `applyLogoutState()` on false).
   *
   * Why return boolean rather than throw
   * ------------------------------------
   * The refresh interceptor calls this from inside an HTTP error
   * handler chain where mixing observables and async/await with
   * thrown errors becomes hard to reason about. Returning a bool
   * keeps the interceptor's logic linear.
   */
  refresh(): Promise<boolean> {
    if (this.inflightRefresh !== null) {
      return this.inflightRefresh;
    }
    this.inflightRefresh = this.performRefresh().finally(() => {
      this.inflightRefresh = null;
    });
    return this.inflightRefresh;
  }

  /**
   * Log out — clear server-side refresh-token row + local state.
   *
   * We call the BFF's logout endpoint (which calls API /logout) so
   * the refresh token's DB row is invalidated. Then we drop local
   * state regardless of whether the API call succeeded — local
   * logout should always work even if the network is broken.
   */
  async logout(): Promise<void> {
    try {
      await firstValueFrom(
        this.http
          .post<void>(`${this.proxyBase}/logout`, {}, { withCredentials: true })
          .pipe(catchError(() => of(undefined))),
      );
    } finally {
      this.applyLogoutState();
    }
  }

  /* ---------- Internals ---------- */

  /**
   * Apply a successful auth response to local state.
   *
   * Centralised here so login / confirm / refresh / reset-confirm all
   * follow the same sequence: token first, user second, schedule
   * refresh, sync locale.
   */
  private applyAuthState(response: BffLoginResponse | BffRefreshResponse): void {
    /* Defensive unwrap. /login emits a FLAT user, but a stale or
       mismatched /me BFF build could still emit a double-nested
       { user: { ... } }. Normalise here so _currentUser AND syncLocale
       always receive the real AuthUser regardless of which BFF build is
       live. AuthUser has no `user` field of its own, so once the BFF
       returns the flat shape this is a harmless no-op. */
    const raw = response.user as AuthUser & { user?: AuthUser };
    const user: AuthUser = raw && raw.user ? raw.user : raw;

    const snapshot: AccessTokenSnapshot = {
      token: response.access_token,
      expiresAt: response.access_token_expires_at,
    };
    this.tokenStore.set(snapshot);
    this._currentUser.set(user);
    this.scheduleRefresh();
    /* Locale sync is a NON-CRITICAL side effect. It must never be able
       to throw out of applyAuthState — otherwise a failure here
       propagates into hydrate()'s catch and tears down an otherwise
       valid session (the root cause of "cart disappears on reload":
       syncLocale threw on a missing locale and hydrate logged the user
       out on every full page load). */
    try {
      this.syncLocale(user);
    } catch (err) {
      if (typeof console !== 'undefined') {
        console.warn('[AuthService] locale sync skipped (non-critical)', err);
      }
    }
  }

  /**
   * Reset to logged-out state. Idempotent.
   */
  private applyLogoutState(): void {
    this.tokenStore.clear();
    this._currentUser.set(null);
    this.cancelScheduledRefresh();
  }

  /**
   * Sync the user's stored locale preference to the LocaleService.
   *
   * If the user has a locale on record that differs from the current
   * resolved locale, switch to theirs. We don't write back to the
   * API here — the user chose their locale when registering or in
   * their profile; the client just respects it.
   *
   * For users without a locale on record (null), we leave the
   * resolved locale alone (browser detection wins).
   */
  private syncLocale(user: AuthUser): void {
    /* Track the server-side value so the locale-watch effect can
       skip pushing the same value back. user.locale may be 'en-AE'
       etc. which the LocaleService canonicalises to 'en'/'ar' on
       setLocale. We store the normalised form here.

       Guard with `== null` (covers BOTH null and undefined): a missing
       locale must short-circuit BEFORE .startsWith() rather than throw.
       A bare `!== null` check let an undefined locale through and crashed
       hydrate on every full page load. */
    if (user?.locale == null) return;

    this.lastPushedLocale = user.locale.startsWith('ar') ? 'ar' : 'en';

    if (user.locale === this.locale.current()) return;
    /* setLocale is async (it triggers translation load) but we
       don't await — the locale change can settle in the background.
       The signal is updated synchronously inside setLocale. */
    void this.locale.setLocale(user.locale);
  }

  /**
   * Push the user's locale preference to /v3/me/profile.
   *
   * Browser-only, silent-on-failure. The browser-side HttpClient
   * goes via the refreshInterceptor so Authorization is attached
   * and 401s trigger a token refresh + retry. No BFF route needed —
   * this is a Bearer-bound call, not a cookie-bound one.
   *
   * Returns nothing; failures are logged as a warning and otherwise
   * swallowed. A 401 even after refresh means the session is dead;
   * AuthService will clean up via the interceptor's failure path.
   */
  private async pushLocaleToServer(locale: 'en' | 'ar'): Promise<void> {
    /* Build the v3 base URL inline rather than injecting
       ApiConfigService — keeps the dependency graph small.
       Future Y.2: extract a constant or DI token. */
    const url = 'https://api-v3.3bayti.ae/v3/me/profile';
    try {
      await firstValueFrom(
        this.http.patch(url, { locale }, { withCredentials: false }),
      );
      /* Optimistically update the local user signal so subsequent
         renders reflect the chosen locale without a /me re-fetch. */
      const user = this._currentUser();
      if (user !== null) {
        this._currentUser.set({ ...user, locale });
      }
    } catch (err) {
      if (typeof console !== 'undefined') {
        console.warn('[AuthService] locale push failed', err);
      }
      /* Don't roll back lastPushedLocale: a transient failure followed
         by the user changing locale again would still trigger a push.
         If we cleared the marker, every render-time recomputation of
         the effect would re-fire the PATCH — bad. */
    }
  }

  /**
   * Actually perform a refresh request.
   *
   * Returns true on success (and applies the new state); false on
   * failure (and clears local state so subsequent isAuthenticated
   * reads return false).
   */
  private async performRefresh(): Promise<boolean> {
    try {
      const response = await firstValueFrom(
        this.http.post<BffRefreshResponse>(
          `${this.proxyBase}/refresh`,
          {},
          { withCredentials: true },
        ),
      );
      this.applyAuthState(response);
      return true;
    } catch {
      this.applyLogoutState();
      return false;
    }
  }

  /**
   * Schedule a pre-emptive refresh AUTH_REFRESH_LEAD_TIME_MS before
   * the current token's expiry.
   *
   * Cancels any existing timer first to avoid overlapping schedules.
   */
  private scheduleRefresh(): void {
    this.cancelScheduledRefresh();

    const snap = this.tokenStore.current();
    if (snap === null) return;

    const expiresAtMs = new Date(snap.expiresAt).getTime();
    const fireAtMs = expiresAtMs - this.refreshLeadTimeMs;
    const delayMs = fireAtMs - Date.now();

    /* If the token is already inside the refresh window (e.g. user
       hydrates a tab with a near-expired token), fire immediately.
       If it's already EXPIRED, also fire — the refresh will either
       extend the session or fail cleanly and log out. */
    const clampedDelay = Math.max(delayMs, 0);

    this.refreshTimerId = setTimeout(() => {
      this.refreshTimerId = null;
      /* Fire-and-forget: the timer doesn't await; if the refresh
         fails, applyLogoutState() inside performRefresh() drops
         the session. No need to handle the boolean here. */
      void this.refresh();
    }, clampedDelay);
  }

  private cancelScheduledRefresh(): void {
    if (this.refreshTimerId !== null) {
      clearTimeout(this.refreshTimerId);
      this.refreshTimerId = null;
    }
  }
}
