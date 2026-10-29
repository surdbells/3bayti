import { TestBed, fakeAsync, tick } from '@angular/core/testing';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';

import { PortalCrudAdapter } from './portal-crud-adapter';
import { isSessionChanged, isSessionOver } from './session-errors';
import { GlobalComponent } from '../global-component';

const REFRESH_URL = 'https://api-v3.3bayti.ae/v3/auth/refresh';
const PASSWORD_URL = 'https://api-v3.3bayti.ae/v3/me/password';

function writeSession(extra: Record<string, unknown> = {}): void {
  sessionStorage.setItem('SESSION', GlobalComponent.encodeBase64({
    id: 7, token: 'old-access', refresh_token: 'old-refresh', is_admin: true, ...extra,
  }));
}

function readSession(): any {
  const raw = sessionStorage.getItem('SESSION');
  return raw ? GlobalComponent.decodeBase64(raw) : null;
}

function errorBody(code: string) {
  return { error: { code, message: 'x' } };
}

/**
 * Karma's webpack build can't bundle @3bayti/api-client (TS source with
 * `.js`-suffixed imports), so resolveUrl is unavailable in specs; map the
 * param-less v3 keys used here the way the routing table does.
 */
function stubV3Urls(adapter: PortalCrudAdapter): void {
  spyOn<any>(adapter, 'url').and.callFake(
    (key: string) => `https://api-v3.3bayti.ae/v3${key.slice(key.indexOf(' ') + 1)}`,
  );
}

/**
 * Refresh-and-retry and sign-out classification in the adapter: only a
 * stale/missing-token 401 triggers a refresh, and only the API's definitive
 * "session is over" refresh answer signs the user out.
 */
describe('PortalCrudAdapter refresh handling', () => {
  let adapter: PortalCrudAdapter;
  let http: HttpTestingController;
  let router: jasmine.SpyObj<Router>;

  beforeEach(() => {
    router = jasmine.createSpyObj<Router>('Router', ['navigate']);
    router.navigate.and.resolveTo(true);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: Router, useValue: router },
      ],
    });
    adapter = TestBed.inject(PortalCrudAdapter);
    http = TestBed.inject(HttpTestingController);
    stubV3Urls(adapter);
    spyOn(console, 'error'); // logAndRethrow noise
    writeSession();
  });

  afterEach(() => {
    http.verify();
    sessionStorage.removeItem('SESSION');
  });

  /** PATCH /me/password and capture what the caller receives. */
  function callPassword() {
    const seen: { value?: unknown; error?: any } = {};
    adapter.patch_v3('PATCH /me/password', { current_password: 'a', new_password: 'b' }).subscribe({
      next: (v) => (seen.value = v),
      error: (e) => (seen.error = e),
    });
    return seen;
  }

  it('passes a business 401 (wrong current password) to the caller without refreshing', () => {
    const seen = callPassword();
    http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_CREDENTIALS'), { status: 401, statusText: 'Unauthorized' });

    http.expectNone(REFRESH_URL);
    expect(seen.error.status).toBe(401);
    expect(seen.error.error.error.code).toBe('AUTH_INVALID_CREDENTIALS');
    expect(readSession().token).toBe('old-access');
    expect(router.navigate).not.toHaveBeenCalled();
  });

  it('passes a wrong-OTP 401 through without refreshing', () => {
    const seen = callPassword();
    http.expectOne(PASSWORD_URL).flush(errorBody('OTP_VERIFICATION_FAILED'), { status: 401, statusText: 'Unauthorized' });

    http.expectNone(REFRESH_URL);
    expect(seen.error.error.error.code).toBe('OTP_VERIFICATION_FAILED');
  });

  it('refreshes on 401 AUTH_INVALID_TOKEN, stores the rotated pair and retries with it', () => {
    const seen = callPassword();
    http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });

    const refresh = http.expectOne(REFRESH_URL);
    expect(refresh.request.body).toEqual({ refresh_token: 'old-refresh' });
    expect(refresh.request.headers.has('Authorization')).toBeFalse();
    refresh.flush({
      access_token: 'new-access',
      refresh_token: 'new-refresh',
      access_token_expires_at: '2030-01-01T00:00:00Z',
      refresh_token_expires_at: '2035-01-01T00:00:00Z',
      user: {},
    });

    const retry = http.expectOne(PASSWORD_URL);
    expect(retry.request.headers.get('Authorization')).toBe('Bearer new-access');
    retry.flush({ ok: true });

    expect(seen.value).toEqual({ ok: true });
    expect(readSession().token).toBe('new-access');
    expect(readSession().refresh_token).toBe('new-refresh');
    expect(readSession().access_token_expires_at).toBe('2030-01-01T00:00:00Z');
  });

  it('refreshes on a 401 that carries no error code', () => {
    const seen = callPassword();
    http.expectOne(PASSWORD_URL).flush(null, { status: 401, statusText: 'Unauthorized' });
    http.expectOne(REFRESH_URL).flush({ access_token: 'new-access', refresh_token: 'new-refresh' });
    http.expectOne(PASSWORD_URL).flush({ ok: true });

    expect(seen.value).toEqual({ ok: true });
  });

  it('shares one refresh across concurrent 401s', () => {
    callPassword();
    callPassword();
    const [a, b] = http.match(PASSWORD_URL);
    a.flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });
    b.flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });

    http.expectOne(REFRESH_URL).flush({ access_token: 'new-access', refresh_token: 'new-refresh' });
    expect(http.match(PASSWORD_URL).length).toBe(2);
  });

  it('signs out only on refresh 401 + AUTH_REFRESH_TOKEN_INVALID', () => {
    const seen = callPassword();
    http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });
    http.expectOne(REFRESH_URL).flush(errorBody('AUTH_REFRESH_TOKEN_INVALID'), { status: 401, statusText: 'Unauthorized' });

    expect(sessionStorage.getItem('SESSION')).toBeNull();
    expect(router.navigate).toHaveBeenCalledOnceWith(['/login']);
    expect(seen.error.status).toBe(401);
  });

  const transientCases: { name: string; status: number; body: object | string | null }[] = [
    { name: 'a proxy 403', status: 403, body: errorBody('FORBIDDEN') },
    { name: 'a 401 without the refresh code', status: 401, body: errorBody('AUTH_INVALID_TOKEN') },
    { name: 'a code-less 401', status: 401, body: null },
    { name: 'a 429', status: 429, body: errorBody('RATE_LIMITED') },
    { name: 'a 500', status: 500, body: errorBody('INTERNAL_ERROR') },
    { name: 'a 503 HTML page', status: 503, body: '<html>maintenance</html>' },
    { name: 'a 422', status: 422, body: errorBody('VALIDATION_FAILED') },
  ];

  for (const c of transientCases) {
    it(`keeps the session when the refresh fails with ${c.name}, surfacing that error`, () => {
      const seen = callPassword();
      http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });
      http.expectOne(REFRESH_URL).flush(c.body, { status: c.status, statusText: 'x' });

      expect(readSession()?.refresh_token).toBe('old-refresh');
      expect(router.navigate).not.toHaveBeenCalled();
      expect(seen.error.status).toBe(c.status);
    });
  }

  it('keeps the session when the refresh hits a network error (status 0)', () => {
    const seen = callPassword();
    http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });
    http.expectOne(REFRESH_URL).error(new ProgressEvent('error'));

    expect(readSession()?.refresh_token).toBe('old-refresh');
    expect(router.navigate).not.toHaveBeenCalled();
    expect(seen.error.status).toBe(0);
  });

  it('keeps the session when the refresh answers 200 without an access token', () => {
    const seen = callPassword();
    http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });
    http.expectOne(REFRESH_URL).flush({ unexpected: true });

    expect(readSession()?.refresh_token).toBe('old-refresh');
    expect(router.navigate).not.toHaveBeenCalled();
    expect(seen.error).toBeDefined();
  });

  it('times out a hung refresh as a network failure and frees the next refresh', fakeAsync(() => {
    let error: unknown;
    adapter.refreshSession().subscribe({ error: (e) => (error = e) });
    const hung = http.expectOne(REFRESH_URL);

    tick(30_000);

    expect(hung.cancelled).toBeTrue();
    expect(error instanceof HttpErrorResponse && error.status === 0).toBeTrue();
    expect(readSession()?.refresh_token).toBe('old-refresh');
    // A later refresh issues a fresh call instead of re-joining the hung one.
    adapter.refreshSession().subscribe();
    http.expectOne(REFRESH_URL).flush({ access_token: 'new-access', refresh_token: 'new-refresh' });
    expect(readSession().token).toBe('new-access');
  }));

  it('signs out when the tab holds no refresh token at all', () => {
    writeSession({ refresh_token: '' });
    callPassword();
    http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });

    http.expectNone(REFRESH_URL);
    expect(sessionStorage.getItem('SESSION')).toBeNull();
    expect(router.navigate).toHaveBeenCalledOnceWith(['/login']);
  });

  describe('when the tab switches sessions mid-refresh', () => {
    /** The admin session restored by an impersonation exit (another user). */
    const ADMIN = { id: 1, token: 'admin-access', refresh_token: 'admin-refresh', is_admin: true };

    it('drops a successful refresh instead of writing it into the new session', () => {
      const seen = callPassword();
      http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });
      const refresh = http.expectOne(REFRESH_URL);

      writeSession(ADMIN);
      refresh.flush({ access_token: 'new-access', refresh_token: 'new-refresh' });

      expect(readSession().token).toBe('admin-access');
      expect(readSession().refresh_token).toBe('admin-refresh');
      // The old session's request is not retried with anyone's token.
      http.expectNone(PASSWORD_URL);
      expect(seen.error.status).toBe(401);
      expect(router.navigate).not.toHaveBeenCalled();
    });

    it('never signs the new session out on the old one\'s AUTH_REFRESH_TOKEN_INVALID', () => {
      const seen = callPassword();
      http.expectOne(PASSWORD_URL).flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });
      const refresh = http.expectOne(REFRESH_URL);

      writeSession(ADMIN);
      refresh.flush(errorBody('AUTH_REFRESH_TOKEN_INVALID'), { status: 401, statusText: 'Unauthorized' });

      expect(readSession()?.token).toBe('admin-access');
      expect(router.navigate).not.toHaveBeenCalled();
      expect(seen.error.status).toBe(401);
    });

    it('reports SESSION_CHANGED (transient) to a proactive refresh, for both outcomes', () => {
      const errors: unknown[] = [];
      adapter.refreshSession().subscribe({ error: (e) => errors.push(e) });
      const first = http.expectOne(REFRESH_URL);
      writeSession(ADMIN);
      first.flush({ access_token: 'new-access', refresh_token: 'new-refresh' });

      writeSession();
      adapter.refreshSession().subscribe({ error: (e) => errors.push(e) });
      const second = http.expectOne(REFRESH_URL);
      writeSession(ADMIN);
      second.flush(errorBody('AUTH_REFRESH_TOKEN_INVALID'), { status: 401, statusText: 'Unauthorized' });

      expect(errors.map((e) => isSessionChanged(e))).toEqual([true, true]);
      expect(errors.some((e) => isSessionOver(e))).toBeFalse();
      expect(readSession()?.token).toBe('admin-access');
    });

    it('does not hand the new session the old session\'s in-flight refresh', () => {
      let oldAccess: string | undefined;
      let newAccess: string | undefined;
      adapter.refreshSession().subscribe({ next: (t) => (oldAccess = t), error: () => {} });
      const oldCall = http.expectOne(REFRESH_URL);

      writeSession(ADMIN);
      adapter.refreshSession().subscribe((t) => (newAccess = t));
      const calls = http.match(REFRESH_URL);
      expect(calls.length).toBe(1);
      expect(calls[0].request.body).toEqual({ refresh_token: 'admin-refresh' });

      // The old call settling must not free the new call's slot: a second
      // admin caller still joins the admin refresh rather than starting one.
      oldCall.flush({ access_token: 'vendor-access-2', refresh_token: 'vendor-refresh-2' });
      adapter.refreshSession().subscribe();
      http.expectNone(REFRESH_URL);

      calls[0].flush({ access_token: 'admin-access-2', refresh_token: 'admin-refresh-2' });
      expect(oldAccess).toBeUndefined();
      expect(newAccess).toBe('admin-access-2');
      expect(readSession().token).toBe('admin-access-2');
      expect(readSession().refresh_token).toBe('admin-refresh-2');
    });

    it('does not refresh-and-retry a request whose 401 lands after another user signed in', () => {
      const seen = callPassword();
      const req = http.expectOne(PASSWORD_URL);

      writeSession(ADMIN);
      req.flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });

      http.expectNone(REFRESH_URL);
      expect(seen.error.status).toBe(401);
      expect(readSession().token).toBe('admin-access');
      expect(router.navigate).not.toHaveBeenCalled();
    });

    it('still refresh-and-retries when the same user re-signed in meanwhile', () => {
      const seen = callPassword();
      const req = http.expectOne(PASSWORD_URL);

      writeSession({ token: 'relogin-access', refresh_token: 'relogin-refresh' }); // same id 7
      req.flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });

      const refresh = http.expectOne(REFRESH_URL);
      expect(refresh.request.body).toEqual({ refresh_token: 'relogin-refresh' });
      refresh.flush({ access_token: 'new-access', refresh_token: 'new-refresh' });
      http.expectOne(PASSWORD_URL).flush({ ok: true });
      expect(seen.value).toEqual({ ok: true });
    });
  });

  it('never refresh-and-retries an auth route (logout)', () => {
    let status = -1;
    adapter.post_v3('POST /auth/logout', { refresh_token: 'old-refresh' }).subscribe({
      error: (e) => (status = e.status),
    });
    http.expectOne('https://api-v3.3bayti.ae/v3/auth/logout')
      .flush(errorBody('AUTH_INVALID_TOKEN'), { status: 401, statusText: 'Unauthorized' });

    http.expectNone(REFRESH_URL);
    expect(status).toBe(401);
    expect(router.navigate).not.toHaveBeenCalled();
  });
});
