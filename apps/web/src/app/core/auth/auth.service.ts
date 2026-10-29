import {
  Injectable,
  Signal,
  signal,
  computed,
  effect,
  inject,
  DestroyRef,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom, type Observable } from 'rxjs';
import { AccessTokenStore, AccessTokenSnapshot, jwtLifetimeMs } from './access-token-store';
import { isSessionEndedError } from './auth-errors';
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
import { SocialAuthService, type SocialProvider } from './social-auth.service';

/**
 * Result of a social sign-in attempt surfaced to the UI. On success the
 * session is already applied; `user` lets the caller decide whether to route
 * to the phone gate (is_phone_verified === false). The failure reasons mirror
 * SocialIdTokenResult so the page can stay silent on a cancelled popup.
 */
export type SocialLoginResult =
  | { ok: true; user: AuthUser }
  | { ok: false; reason: 'cancelled' | 'unavailable' | 'popup-blocked' | 'failed' };

/**
 * Whether the browser has a session.
 *   - 'unknown'       , not resolved yet: before hydrate(), or hydrate()
 *                       hit a transient failure and is retrying.
 *   - 'authenticated' , a session is applied (currentUser is set).
 *   - 'anonymous'     , definitively signed out (no / rejected cookie,
 *                       or an explicit logout).
 */
export type SessionStatus = 'unknown' | 'authenticated' | 'anonymous';

/**
 * Result of refresh().
 *   - 'refreshed'     , a new access token is in the store.
 *   - 'session-ended' , the BFF definitively rejected the session
 *                       (401 AUTH_REFRESH_TOKEN_INVALID), or the user
 *                       logged out while the refresh was in flight.
 *   - 'unavailable'   , transient failure; the session is KEPT and a
 *                       background retry is scheduled.
 */
export type RefreshOutcome = 'refreshed' | 'session-ended' | 'unavailable';

/** First background retry after a transient session failure. Doubles per attempt. */
const RECOVERY_BASE_DELAY_MS = 2_000;
/** Ceiling for the background retry backoff. */
const RECOVERY_MAX_DELAY_MS = 60_000;

/**
 * Per-browser localStorage marker: "Log out was tapped here, but the BFF
 * has not confirmed the refresh-cookie clear yet". While it is set, no
 * /me or /refresh may revive the HttpOnly cookie (it lives 400 days);
 * the clear is retried instead. Exported for tests.
 */
export const LOGOUT_PENDING_KEY = 'bayti.logoutPending';

/**
 * AuthService, single source of truth for authentication state.
 *
 * Public surface
 * --------------
 *   - `currentUser`    , Signal<AuthUser | null>
 *   - `isAuthenticated`, Signal<boolean>
 *   - `accessToken`    , Signal<string | null>
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
 * The scheduler is armed inside applyAuthState(), i.e. only after a
 * real auth response sets a real token. We deliberately do NOT inject
 * ApplicationRef and wait for isStable: ApplicationRef participates in
 * the APP_INITIALIZER graph, so injecting it from a root service used
 * by an initializer creates an NG0200 circular dependency. Arming on
 * token-set is the correct trigger anyway, with no token there is
 * nothing to schedule.
 *
 * Session persistence (product rule)
 * ----------------------------------
 * A customer stays signed in until THEY tap "Log out". Only the BFF's
 * definitive rejection (401 AUTH_REFRESH_TOKEN_INVALID, see
 * auth-errors.ts) ends the local session. Any other refresh / hydrate
 * failure (network, timeout, 5xx, 429, 403, non-JSON) keeps the session
 * and schedules a background retry with exponential backoff, which also
 * fires early when the browser comes back 'online' or the tab becomes
 * visible again.
 *
 * Cookie ordering
 * ---------------
 * The browser applies every Set-Cookie in the order responses arrive,
 * so calls that WRITE the refresh cookie (a sign-in, the logout clear)
 * are serialised against the ones that rotate it (/me, /refresh): a
 * write waits for the rotations already on the wire, and rotations
 * started meanwhile wait for the write (see writeCookie). A response
 * from an older session can therefore never overwrite a newer cookie.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);
  private readonly tokenStore = inject(AccessTokenStore);
  private readonly locale = inject(LocaleService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly proxyBase = inject(AUTH_PROXY_BASE);
  private readonly refreshLeadTimeMs = inject(AUTH_REFRESH_LEAD_TIME_MS);
  private readonly social = inject(SocialAuthService);

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
   * PATCH /me/password. The endpoint no longer revokes any session (this
   * one and the HttpOnly refresh cookie stay valid), but it bumps the
   * user's password-changed marker, so access tokens minted BEFORE the
   * change get one 401 AUTH_INVALID_TOKEN. Adopting the returned token
   * (valid on first use) avoids that extra refresh round-trip here.
   * Mirrors the token-first / user-second / reschedule sequence of
   * applyAuthState.
   *
   * No-op when signed out (defensive, a password change can only
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

  /**
   * True while a session exists (a user is applied). Deliberately NOT
   * tied to the access token's expiry: an aged-out token or a skewed
   * device clock must not make a signed-in customer look signed out.
   * Staleness is handled by the pre-emptive refresh and by the
   * interceptor's 401-driven refresh.
   */
  readonly isAuthenticated = computed(() => this._currentUser() !== null);

  private readonly _sessionStatus = signal<SessionStatus>('unknown');

  /** Session resolution state, see SessionStatus. */
  readonly sessionStatus: Signal<SessionStatus> = this._sessionStatus.asReadonly();

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
  private inflightRefresh: Promise<RefreshOutcome> | null = null;

  /** Single-flight /me request (initial hydrate + its retries). */
  private inflightHydrate: Promise<void> | null = null;

  /** Pending background retry after a transient failure (null = none). */
  private recoveryTimerId: ReturnType<typeof setTimeout> | null = null;

  /** Consecutive transient failures, drives the retry backoff. */
  private recoveryAttempts = 0;

  /**
   * Bumped on every logout. A refresh that was in flight when the user
   * logged out compares its captured value and drops its result, so a
   * late response can never resurrect a signed-out session.
   */
  private logoutEpoch = 0;

  /**
   * Bumped when a sign-in call starts (mintSession). A /me or /refresh
   * still carrying the previous cookie drops its result (treated as
   * transient) instead of applying another account over the new one.
   */
  private sessionEpoch = 0;

  /**
   * Settles once the cookie write in progress (a sign-in or the logout
   * clear) has landed; null when none. See writeCookie.
   */
  private cookieWriter: Promise<void> | null = null;

  /** Single-flight POST /auth-proxy/logout (see clearServerSession). */
  private inflightLogout: Promise<void> | null = null;

  /** Callers of whenSessionKnown() waiting for 'unknown' to resolve. */
  private sessionWaiters: Array<(status: SessionStatus) => void> = [];

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
      this.cancelRecovery();
    });

    /* Retry a failed refresh / hydrate as soon as the browser is back
       online or the tab is visible again, instead of waiting out the
       backoff. Only acts while a retry is actually pending. Coming back
       online also finishes a logout whose cookie clear never landed. */
    if (typeof window !== 'undefined' && typeof document !== 'undefined') {
      const onOnline = (): void => {
        if (this.isLogoutPending()) void this.clearServerSession();
        this.recoverNowIfPending();
      };
      const onVisibility = (): void => {
        if (document.visibilityState === 'visible') this.recoverNowIfPending();
      };
      window.addEventListener('online', onOnline);
      document.addEventListener('visibilitychange', onVisibility);
      this.destroyRef.onDestroy(() => {
        window.removeEventListener('online', onOnline);
        document.removeEventListener('visibilitychange', onVisibility);
      });
    }

    /* Locale → server sync (Y.1-J).

       When an authenticated user changes their locale via the header
       switcher, push that choice to the API so the next session (on
       another device, after cookie expiry, after a hard refresh, etc.)
       resumes in the chosen locale.

       This watches the LocaleService.current() signal. We deliberately
       do NOT push on the FIRST emit after login, the API just told us
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
        /* Local state already matches server, no need to PATCH. */
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
   *
   * Never rejects. Outcomes:
   *   - 200                              → session applied ('authenticated')
   *   - 401 AUTH_REFRESH_TOKEN_INVALID   → 'anonymous' (no / dead cookie)
   *   - anything else (network, 503, …) → stays 'unknown' (rendered as
   *     signed-out-for-now) and retries in the background with backoff;
   *     the session appears as soon as a retry succeeds. The cookie is
   *     untouched by the BFF in that case, so nothing is lost.
   *   - a logout tapped earlier whose cookie clear never landed
   *     → 'anonymous' without calling /me; the clear is retried.
   */
  async hydrate(): Promise<void> {
    await this.attemptHydrate();
  }

  /**
   * Resolve once the session state is known ('authenticated' or
   * 'anonymous'). While it is 'unknown' (a hydrate retry is pending)
   * this kicks an immediate attempt rather than waiting out the backoff.
   *
   * With `timeoutMs`, resolves with the then-current status (possibly
   * still 'unknown') once the timeout elapses. Used by the route guards
   * so a transient failure never bounces a signed-in customer to /login.
   */
  whenSessionKnown(timeoutMs?: number): Promise<SessionStatus> {
    const status = this._sessionStatus();
    if (status !== 'unknown') return Promise.resolve(status);
    return new Promise<SessionStatus>(resolve => {
      this.sessionWaiters.push(resolve);
      if (timeoutMs !== undefined) {
        setTimeout(() => resolve(this._sessionStatus()), timeoutMs);
      }
      this.kickRecovery();
    });
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
    const response = await this.mintSession(() =>
      this.http.post<BffLoginResponse>(`${this.proxyBase}/login`, input, {
        withCredentials: true,
      }),
    );
    return response.user;
  }

  /**
   * Sign in with a social provider (Google / Apple).
   *
   * Flow:
   *   1. SocialAuthService drives the Firebase popup → Firebase ID token.
   *   2. POST that id_token (+ name from the popup) to the BFF
   *      /auth-proxy/social, which exchanges it at /v3/auth/social and parks
   *      the refresh_token in the HttpOnly cookie (same model as /login).
   *   3. Feed the response into the SAME applyAuthState() as password login -
   *      token store + currentUser + scheduleRefresh + locale sync.
   *
   * Returns a typed result rather than throwing: a user-cancelled popup is a
   * normal outcome the page should ignore, not an error. A non-cancel failure
   * (BFF/network/unavailable) returns ok:false with a reason the page maps to
   * a toast. The caller inspects `result.user.is_phone_verified` to decide
   * whether to route to the phone-after-social gate.
   */
  async loginWithProvider(provider: SocialProvider): Promise<SocialLoginResult> {
    const tokenResult =
      provider === 'google'
        ? await this.social.getGoogleIdToken()
        : await this.social.getAppleIdToken();

    if (!tokenResult.ok) {
      return { ok: false, reason: tokenResult.reason };
    }

    try {
      const response = await this.mintSession(() =>
        this.http.post<BffLoginResponse>(
          `${this.proxyBase}/social`,
          {
            id_token: tokenResult.idToken,
            first_name: tokenResult.firstName,
            last_name: tokenResult.lastName,
          },
          { withCredentials: true },
        ),
      );
      return { ok: true, user: response.user };
    } catch {
      /* BFF / upstream / network failure, the popup itself succeeded, but
         the token exchange didn't. Surface a generic failure for the page. */
      return { ok: false, reason: 'failed' };
    }
  }

  /**
   * Register a new account. Returns the verification_id needed for
   * the OTP confirm step. NO tokens issued yet, the user is still
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
    const response = await this.mintSession(() =>
      this.http.post<BffLoginResponse>(`${this.proxyBase}/confirm`, input, {
        withCredentials: true,
      }),
    );
    return response.user;
  }

  /**
   * Complete the phone-claim account merge (phone-after-social "already
   * registered" recovery). POSTs the OTP to the BFF /auth-proxy/phone-claim-verify,
   * forwarding the CURRENT access token so the API can authenticate the /me
   * call; on success the API merged this social identity into the existing
   * account and the BFF parked THAT account's refresh cookie. We adopt the
   * returned session, so we're now signed in as the existing account. Errors
   * (invalid OTP, CONFLICT/PHONE_LINK_AMBIGUOUS, …) propagate to the caller.
   */
  async completePhoneClaim(input: ConfirmInput): Promise<AuthUser> {
    const response = await this.mintSession(() => {
      /* Read the token when the request actually goes out: a refresh
         that was in flight (and that mintSession waited for) may have
         just replaced it. */
      const token = this.tokenStore.getToken();
      return this.http.post<BffLoginResponse>(`${this.proxyBase}/phone-claim-verify`, input, {
        withCredentials: true,
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
    });
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
   * non-existent emails, that's fine, the OTP verification step
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
    const response = await this.mintSession(() =>
      this.http.post<BffLoginResponse>(`${this.proxyBase}/reset-confirm`, input, {
        withCredentials: true,
      }),
    );
    return response.user;
  }

  /* =========================================================================
     Passwordless parity (#8), mirrors the mobile v3 passwordless flows.
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
    const response = await this.mintSession(() =>
      this.http.post<BffLoginResponse>(
        `${this.proxyBase}/otp-login/verify`,
        input,
        { withCredentials: true },
      ),
    );
    return response.user;
  }

  /**
   * Register step 1 (phone), initiate. Sends a phone OTP and returns the
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
   * Register step 2 (verify phone), exchanges the phone OTP for a
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
   * Register step 3 (details), submit. Sends a fresh email OTP and
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
    const response = await this.mintSession(() =>
      this.http.post<BffLoginResponse>(
        `${this.proxyBase}/register/confirm-email`,
        input,
        { withCredentials: true },
      ),
    );
    return response.user;
  }

  /**
   * Best-effort phone-availability probe for the register step-1 UX.
   * Returns { phone, available }; available=false means already
   * registered. Never a hard gate, the register/initiate 409 is.
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
   * Resolves (never rejects) with a RefreshOutcome:
   *   - 'refreshed'     , new access token adopted.
   *   - 'session-ended' , definitive rejection; local state already
   *                       cleared.
   *   - 'unavailable'   , transient failure; the session is KEPT and a
   *                       background retry is scheduled. Callers fail
   *                       their own request with a normal (connection)
   *                       error, never a logout.
   *
   * Why return an outcome rather than throw
   * ---------------------------------------
   * The refresh interceptor calls this from inside an HTTP error
   * handler chain where mixing observables and async/await with
   * thrown errors becomes hard to reason about. Returning a value
   * keeps the interceptor's logic linear.
   */
  refresh(): Promise<RefreshOutcome> {
    if (this.inflightRefresh !== null) {
      return this.inflightRefresh;
    }
    this.inflightRefresh = this.afterCookieWrite(() => this.performRefresh()).finally(() => {
      this.inflightRefresh = null;
    });
    return this.inflightRefresh;
  }

  /**
   * Log out, clear server-side refresh-token row + local state.
   *
   * Local state goes FIRST (contract C3): the UI flips to signed-out at
   * once, and the logout epoch + cancelled timers mean no scheduled
   * refresh, background retry or late response can bring the session
   * back. Then the BFF's logout endpoint clears the HttpOnly cookie and
   * asks the API to end THIS browser's session family (never other
   * devices); it is queued behind any refresh already on the wire, so
   * that refresh's Set-Cookie can't land after the clear.
   *
   * If the clear can't reach the BFF (offline, 5xx, timeout) a
   * pending-logout marker survives the reload, so the next page load
   * (or coming back online) finishes it instead of /me silently signing
   * the user back in. This is the ONLY user-initiated path that ends a
   * session. Never rejects.
   */
  async logout(): Promise<void> {
    this.markLogoutPending();
    this.applyLogoutState();
    await this.clearServerSession();
  }

  /* ---------- Internals ---------- */

  /**
   * Run a call that MINTS a new refresh cookie (login, OTP verify,
   * register confirm, reset-confirm, social, phone-claim) and adopt the
   * session it returns.
   *
   * A background /me or /refresh can still be holding the PREVIOUS
   * cookie (e.g. a hydrate retry for another account while the session
   * is 'unknown'). Had its response landed after this one, the browser
   * would apply that older Set-Cookie on top of the new session and the
   * next refresh would silently swap accounts. So the call goes through
   * writeCookie (waits for those, holds new ones back), and bumping
   * sessionEpoch makes any older result be dropped rather than applied.
   */
  private mintSession(
    request: () => Observable<BffLoginResponse>,
  ): Promise<BffLoginResponse> {
    this.sessionEpoch += 1;
    const logoutEpoch = this.logoutEpoch;
    return this.writeCookie(async landed => {
      let response: BffLoginResponse;
      try {
        response = await firstValueFrom(request());
      } finally {
        landed();
      }
      /* Log out was tapped while this was in flight: the logout wins (its
         cookie clear is queued right behind this call). */
      if (logoutEpoch === this.logoutEpoch) {
        /* The new cookie replaced any old one, so an unfinished logout
           has nothing left to clear. */
        this.clearLogoutPending();
        this.applyAuthState(response);
      }
      return response;
    });
  }

  /**
   * Serialise a call that WRITES the refresh cookie (a sign-in or the
   * logout clear): it starts only once every cookie call already on the
   * wire has settled, and /me + /refresh calls started meanwhile wait
   * for it (afterCookieWrite). Starts `work` synchronously when nothing
   * is pending.
   *
   * `work` calls `landed()` as soon as its response has arrived (the
   * moment the browser applied its Set-Cookie), which releases waiting
   * readers in that same tick. A work that fails before getting there is
   * released when it settles.
   */
  private writeCookie<T>(work: (landed: () => void) => Promise<T>): Promise<T> {
    const pending: Array<Promise<unknown>> = [];
    for (const p of [this.cookieWriter, this.inflightRefresh, this.inflightHydrate]) {
      if (p !== null) pending.push(p);
    }
    let release: () => void = () => undefined;
    const writer = new Promise<void>(resolve => {
      release = () => {
        if (this.cookieWriter === writer) this.cookieWriter = null;
        resolve();
      };
    });
    this.cookieWriter = writer;
    const start = (): Promise<T> => work(release);
    const run = pending.length === 0 ? start() : Promise.allSettled(pending).then(start);
    run.then(release, release);
    return run;
  }

  /** Start a /me or /refresh call once any cookie write in progress has landed. */
  private afterCookieWrite<T>(work: () => Promise<T>): Promise<T> {
    const writer = this.cookieWriter;
    return writer === null ? work() : writer.then(work);
  }

  /**
   * POST /auth-proxy/logout: the BFF clears the HttpOnly cookie and asks
   * the API to end this browser's session family. Clears the
   * pending-logout marker on success; on any failure the marker stays
   * and the clear is retried on 'online' and on the next page load.
   * Single-flight, never rejects.
   */
  private clearServerSession(): Promise<void> {
    if (this.inflightLogout !== null) return this.inflightLogout;
    const done = this.writeCookie(async landed => {
      try {
        await firstValueFrom(
          this.http.post<void>(`${this.proxyBase}/logout`, {}, { withCredentials: true }),
        );
        this.clearLogoutPending();
      } catch (err) {
        if (typeof console !== 'undefined') {
          console.warn('[AuthService] logout could not reach the server, will retry', err);
        }
      } finally {
        landed();
      }
    }).finally(() => {
      this.inflightLogout = null;
    });
    this.inflightLogout = done;
    return done;
  }

  /** True while a tapped logout still has to clear the refresh cookie. */
  private isLogoutPending(): boolean {
    try {
      return localStorage.getItem(LOGOUT_PENDING_KEY) !== null;
    } catch {
      return false;
    }
  }

  private markLogoutPending(): void {
    try {
      localStorage.setItem(LOGOUT_PENDING_KEY, '1');
    } catch {
      /* Storage unavailable: the logout still runs, just without the
         reload safety net. */
    }
  }

  private clearLogoutPending(): void {
    try {
      localStorage.removeItem(LOGOUT_PENDING_KEY);
    } catch {
      /* storage unavailable */
    }
  }

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
    this.cancelRecovery();
    this.setSessionStatus('authenticated');
    this.scheduleRefresh();
    /* Locale sync is a NON-CRITICAL side effect. It must never be able
       to throw out of applyAuthState, otherwise a failure here
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
   *
   * Reached ONLY from an explicit logout() (in this tab, or a pending
   * one from another tab / an earlier page load) or the BFF's definitive
   * session-ended rejection, never from a transient failure.
   */
  private applyLogoutState(): void {
    this.logoutEpoch += 1;
    this.tokenStore.clear();
    this._currentUser.set(null);
    this.cancelScheduledRefresh();
    this.cancelRecovery();
    this.setSessionStatus('anonymous');
  }

  /** Update the session status and release whenSessionKnown() waiters. */
  private setSessionStatus(status: SessionStatus): void {
    this._sessionStatus.set(status);
    if (status === 'unknown') return;
    const waiters = this.sessionWaiters;
    this.sessionWaiters = [];
    for (const resolve of waiters) resolve(status);
  }

  /**
   * Sync the user's stored locale preference to the LocaleService.
   *
   * If the user has a locale on record that differs from the current
   * resolved locale, switch to theirs. We don't write back to the
   * API here, the user chose their locale when registering or in
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
       don't await, the locale change can settle in the background.
       The signal is updated synchronously inside setLocale. */
    void this.locale.setLocale(user.locale);
  }

  /**
   * Push the user's locale preference to /v3/me/profile.
   *
   * Browser-only, silent-on-failure. The browser-side HttpClient
   * goes via the refreshInterceptor so Authorization is attached
   * and 401s trigger a token refresh + retry. No BFF route needed -
   * this is a Bearer-bound call, not a cookie-bound one.
   *
   * Returns nothing; failures are logged as a warning and otherwise
   * swallowed. The interceptor handles a stale token (refresh + retry);
   * only a definitive session-ended rejection clears the session.
   */
  private async pushLocaleToServer(locale: 'en' | 'ar'): Promise<void> {
    /* Build the v3 base URL inline rather than injecting
       ApiConfigService, keeps the dependency graph small.
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
         the effect would re-fire the PATCH, bad. */
    }
  }

  /**
   * Actually perform a refresh request.
   *
   * Only the definitive 401 AUTH_REFRESH_TOKEN_INVALID clears local
   * state. A transient failure keeps currentUser + the (possibly stale)
   * token and schedules a background retry.
   *
   * Also used, via the interceptor, to resolve a still-'unknown' session
   * (the BFF /refresh returns the user too): success signs in, the
   * definitive rejection settles it as 'anonymous'.
   */
  private async performRefresh(): Promise<RefreshOutcome> {
    /* Signed out (logout or a definitive rejection): nothing to refresh,
       and a /refresh now could re-set a cookie that a logout clears. */
    if (this._sessionStatus() === 'anonymous') return 'session-ended';
    /* Log out was tapped in another tab and its cookie clear hasn't
       landed yet: honour it here too. */
    if (this.isLogoutPending()) {
      this.applyLogoutState();
      void this.clearServerSession();
      return 'session-ended';
    }
    const logoutEpoch = this.logoutEpoch;
    const sessionEpoch = this.sessionEpoch;
    try {
      const response = await firstValueFrom(
        this.http.post<BffRefreshResponse>(
          `${this.proxyBase}/refresh`,
          {},
          { withCredentials: true },
        ),
      );
      /* Signed out while this was in flight: never resurrect. */
      if (logoutEpoch !== this.logoutEpoch) return 'session-ended';
      /* A sign-in started meanwhile: this rotated the OLD cookie, so its
         user must not be applied. Treat it like a transient failure. */
      if (sessionEpoch !== this.sessionEpoch) return this.keepSessionAndRetry();
      this.applyAuthState(response);
      return 'refreshed';
    } catch (err) {
      if (logoutEpoch !== this.logoutEpoch) return 'session-ended';
      if (sessionEpoch === this.sessionEpoch && isSessionEndedError(err)) {
        this.applyLogoutState();
        return 'session-ended';
      }
      if (typeof console !== 'undefined') {
        console.warn('[AuthService] refresh failed (transient), keeping the session', err);
      }
      return this.keepSessionAndRetry();
    }
  }

  /**
   * Transient refresh outcome: keep whatever session exists and arm the
   * background retry (a refresh for a signed-in user, a hydrate for a
   * still-'unknown' one).
   */
  private keepSessionAndRetry(): RefreshOutcome {
    if (this._currentUser() !== null || this._sessionStatus() === 'unknown') {
      this.scheduleRecovery();
    }
    return 'unavailable';
  }

  /** Single-flight wrapper around performHydrate(). */
  private attemptHydrate(): Promise<void> {
    if (this.inflightHydrate !== null) {
      return this.inflightHydrate;
    }
    this.inflightHydrate = this.afterCookieWrite(() => this.performHydrate()).finally(() => {
      this.inflightHydrate = null;
    });
    return this.inflightHydrate;
  }

  /**
   * One GET /auth-proxy/me. Applies its result only while the session
   * is still 'unknown', so a slow retry can't override a login or logout
   * that happened meanwhile.
   */
  private async performHydrate(): Promise<void> {
    /* Settled while this waited behind a sign-in / logout. */
    if (this._sessionStatus() !== 'unknown') return;
    /* Log out was tapped but the cookie clear never reached the BFF:
       finish it rather than let /me sign the user back in. */
    if (this.isLogoutPending()) {
      this.applyLogoutState();
      void this.clearServerSession();
      return;
    }
    const sessionEpoch = this.sessionEpoch;
    try {
      const response = await firstValueFrom(
        this.http.get<BffLoginResponse>(`${this.proxyBase}/me`, {
          withCredentials: true,
        }),
      );
      if (this._sessionStatus() !== 'unknown') return;
      /* A sign-in started meanwhile: this read the OLD cookie. Drop it;
         the sign-in settles the session (the retry covers a failed one). */
      if (sessionEpoch !== this.sessionEpoch) {
        this.scheduleRecovery();
        return;
      }
      this.applyAuthState(response);
    } catch (err) {
      if (this._sessionStatus() !== 'unknown') return;
      /* No cookie, or the API definitively rejected it: signed out.
         Don't surface the error to the UI; this is the initial check
         and "you're not logged in" isn't an error. (Not when a sign-in
         started meanwhile: the dead cookie was the old one.) */
      if (sessionEpoch === this.sessionEpoch && isSessionEndedError(err)) {
        this.applyLogoutState();
        return;
      }
      /* Transient (network, 503, 5xx, non-JSON): stay 'unknown' and
         retry. The BFF left the cookie alone, so the session comes back
         as soon as a retry gets through. */
      if (typeof console !== 'undefined') {
        console.warn('[AuthService] hydrate failed (transient), retrying', err);
      }
      this.scheduleRecovery();
    }
  }

  /**
   * Arm one background retry with exponential backoff
   * (2s, 4s, 8s, … capped at 60s). No-op if one is already pending.
   */
  private scheduleRecovery(): void {
    if (this.recoveryTimerId !== null) return;
    const delayMs = Math.min(
      RECOVERY_BASE_DELAY_MS * 2 ** this.recoveryAttempts,
      RECOVERY_MAX_DELAY_MS,
    );
    this.recoveryAttempts += 1;
    this.recoveryTimerId = setTimeout(() => {
      this.recoveryTimerId = null;
      void this.recover();
    }, delayMs);
  }

  /** Drop any pending retry and reset the backoff. */
  private cancelRecovery(): void {
    if (this.recoveryTimerId !== null) {
      clearTimeout(this.recoveryTimerId);
      this.recoveryTimerId = null;
    }
    this.recoveryAttempts = 0;
  }

  /**
   * One recovery attempt: refresh an existing session, or re-run the
   * hydrate for a still-unknown one. Each path re-arms the backoff on
   * another transient failure.
   */
  private async recover(): Promise<void> {
    if (this._currentUser() !== null) {
      await this.refresh();
    } else if (this._sessionStatus() === 'unknown') {
      await this.attemptHydrate();
    }
  }

  /** 'online' / tab-visible: retry now instead of waiting out the backoff. */
  private recoverNowIfPending(): void {
    if (this.recoveryTimerId !== null) {
      this.kickRecovery();
    }
  }

  /**
   * Start a recovery attempt immediately (skipping the remaining
   * backoff) unless one is already in flight. Keeps the backoff counter
   * so a still-failing network doesn't get hammered.
   */
  private kickRecovery(): void {
    if (this.inflightHydrate !== null || this.inflightRefresh !== null) return;
    if (this.recoveryTimerId !== null) {
      clearTimeout(this.recoveryTimerId);
      this.recoveryTimerId = null;
    }
    void this.recover();
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

    /* Prefer the token's own iat→exp lifetime, measured from now (this
       runs right after a token is adopted). Both claims are server-clock,
       so a device clock that is minutes off can't make every fresh token
       look expired and spin the refresh in a tight loop. The floor at
       half the lifetime keeps a lead time >= the lifetime from looping
       too. Non-JWT tokens fall back to the expires_at comparison. */
    const lifetimeMs = jwtLifetimeMs(snap.token);
    const delayMs =
      lifetimeMs !== null
        ? Math.max(lifetimeMs - this.refreshLeadTimeMs, lifetimeMs / 2)
        : new Date(snap.expiresAt).getTime() - this.refreshLeadTimeMs - Date.now();

    /* If the token is already inside the refresh window (e.g. user
       hydrates a tab with a near-expired token), fire immediately.
       If it's already EXPIRED, also fire, the refresh will either
       extend the session or keep it and retry on a transient error. */
    const clampedDelay = Math.max(delayMs, 0);

    this.refreshTimerId = setTimeout(() => {
      this.refreshTimerId = null;
      /* Fire-and-forget: performRefresh() handles every outcome
         (adopt, definitive logout, or keep + background retry). */
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
