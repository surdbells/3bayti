import { TestBed, fakeAsync, flushMicrotasks, tick } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';

import { PortalAuthService } from './portal-auth.service';
import { PortalCrudAdapter } from './portal-crud-adapter';
import { GlobalComponent } from '../global-component';

const LOGOUT_URL = 'https://api-v3.3bayti.ae/v3/auth/logout';
const REFRESH_URL = 'https://api-v3.3bayti.ae/v3/auth/refresh';

function writeSession(extra: Record<string, unknown> = {}): void {
  sessionStorage.setItem('SESSION', GlobalComponent.encodeBase64({
    id: 7, token: 'tab-access', refresh_token: 'tab-refresh', is_admin: true, ...extra,
  }));
}

/**
 * The shared sign-out helper: a fire-and-forget POST /auth/logout for this
 * tab's session only, that never blocks or alters the local sign-out.
 */
describe('PortalAuthService sign-out', () => {
  let auth: PortalAuthService;
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
    auth = TestBed.inject(PortalAuthService);
    http = TestBed.inject(HttpTestingController);
    // Karma's webpack build can't bundle @3bayti/api-client (TS source with
    // `.js`-suffixed imports), so map the param-less v3 keys used here directly.
    spyOn<any>(TestBed.inject(PortalCrudAdapter), 'url').and.callFake(
      (key: string) => `https://api-v3.3bayti.ae/v3${key.slice(key.indexOf(' ') + 1)}`,
    );
    spyOn(console, 'error'); // logAndRethrow noise on a failed logout
  });

  afterEach(() => {
    http.verify();
    sessionStorage.removeItem('SESSION');
  });

  it('posts the stored refresh token WITHOUT a bearer, though the session holds one', () => {
    // No bearer: an API not yet redeployed (bearer required) then answers 401
    // and revokes nothing, rather than revoking just this token and treating
    // its later re-presentation as reuse (signing the user out everywhere).
    writeSession();
    void auth.revokeCurrentSession();

    const req = http.expectOne(LOGOUT_URL);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ refresh_token: 'tab-refresh' });
    expect(req.request.headers.has('Authorization')).toBeFalse();
    req.flush(null, { status: 204, statusText: 'No Content' });
  });

  it('reads the token synchronously, so storage can be cleared right after', () => {
    writeSession();
    void auth.revokeCurrentSession();
    sessionStorage.clear();

    const req = http.expectOne(LOGOUT_URL);
    expect(req.request.body).toEqual({ refresh_token: 'tab-refresh' });
    expect(req.request.headers.has('Authorization')).toBeFalse();
    req.flush(null, { status: 204, statusText: 'No Content' });
  });

  it('settles quietly when an old API rejects the bearer-less call (401 AUTH_MISSING_TOKEN)', fakeAsync(() => {
    writeSession();
    let settled = false;
    void auth.revokeCurrentSession().then(() => (settled = true));

    http.expectOne(LOGOUT_URL).flush(
      { error: { code: 'AUTH_MISSING_TOKEN', message: 'x' } },
      { status: 401, statusText: 'Unauthorized' },
    );
    flushMicrotasks();

    http.expectNone(REFRESH_URL);
    expect(settled).toBeTrue();
    expect(router.navigate).not.toHaveBeenCalled();
  }));

  it('does nothing when there is no session or refresh token', async () => {
    await expectAsync(auth.revokeCurrentSession()).toBeResolved();
    writeSession({ refresh_token: '' });
    await expectAsync(auth.revokeCurrentSession()).toBeResolved();

    http.expectNone(LOGOUT_URL);
  });

  it('swallows a failure and never refresh-and-retries or signs out', fakeAsync(() => {
    writeSession();
    let settled = false;
    let rejected = false;
    auth.revokeCurrentSession().then(() => (settled = true), () => (rejected = true));

    http.expectOne(LOGOUT_URL).flush(
      { error: { code: 'AUTH_INVALID_TOKEN', message: 'x' } },
      { status: 401, statusText: 'Unauthorized' },
    );
    flushMicrotasks();

    http.expectNone(REFRESH_URL);
    expect(settled).toBeTrue();
    expect(rejected).toBeFalse();
    expect(router.navigate).not.toHaveBeenCalled();
    expect(sessionStorage.getItem('SESSION')).not.toBeNull();
  }));

  it('resolves after a short cap when the call hangs, without cancelling it', fakeAsync(() => {
    writeSession();
    let settled = false;
    void auth.revokeCurrentSession().then(() => (settled = true));

    const req = http.expectOne(LOGOUT_URL);
    tick(PortalAuthService.REVOKE_WAIT_MS - 1);
    expect(settled).toBeFalse();
    tick(1);
    expect(settled).toBeTrue();
    expect(req.cancelled).toBeFalse();
    req.flush(null, { status: 204, statusText: 'No Content' });
  }));

  it('signOut revokes this session, then clears local state as before', () => {
    writeSession();
    localStorage.setItem('ADMIN_SESSION_BACKUP', 'x');

    auth.signOut();

    expect(sessionStorage.getItem('SESSION')).toBeNull();
    expect(localStorage.getItem('ADMIN_SESSION_BACKUP')).toBeNull();
    const req = http.expectOne(LOGOUT_URL);
    expect(req.request.body).toEqual({ refresh_token: 'tab-refresh' });
    req.flush(null, { status: 204, statusText: 'No Content' });
  });

  it('signOut still clears local state when the logout call fails', () => {
    writeSession();

    auth.signOut();
    http.expectOne(LOGOUT_URL).error(new ProgressEvent('error'));

    expect(sessionStorage.getItem('SESSION')).toBeNull();
  });

  it('signOut while impersonating also revokes the stashed admin session, each by its own token', () => {
    writeSession({ id: 42, token: 'vendor-access', refresh_token: 'vendor-refresh', is_admin: false, impersonating: true });
    localStorage.setItem('ADMIN_SESSION_BACKUP', GlobalComponent.encodeBase64({
      id: 7, token: 'admin-access', refresh_token: 'admin-refresh', is_admin: true,
    }));

    auth.signOut();

    const calls = http.match(LOGOUT_URL);
    expect(calls.map((c) => c.request.body)).toEqual([
      { refresh_token: 'vendor-refresh' },
      { refresh_token: 'admin-refresh' },
    ]);
    // Neither carries a bearer, so no call can hit the cross-user guard.
    expect(calls.every((c) => !c.request.headers.has('Authorization'))).toBeTrue();
    expect(localStorage.getItem('ADMIN_SESSION_BACKUP')).toBeNull();
    expect(sessionStorage.getItem('SESSION')).toBeNull();
    calls.forEach((c) => c.flush(null, { status: 204, statusText: 'No Content' }));
  });

  describe('discardStashedAdminSession', () => {
    afterEach(() => localStorage.removeItem('ADMIN_SESSION_BACKUP'));

    it('does nothing when no admin session is stashed', () => {
      writeSession();

      auth.discardStashedAdminSession();

      http.expectNone(LOGOUT_URL);
      expect(sessionStorage.getItem('SESSION')).not.toBeNull();
    });

    it('drops an undecodable stash without a call', () => {
      localStorage.setItem('ADMIN_SESSION_BACKUP', 'not-base64-json');

      auth.discardStashedAdminSession();

      http.expectNone(LOGOUT_URL);
      expect(localStorage.getItem('ADMIN_SESSION_BACKUP')).toBeNull();
    });

    it('does not revoke the current session twice when the stash is that same session', () => {
      writeSession();
      localStorage.setItem('ADMIN_SESSION_BACKUP', sessionStorage.getItem('SESSION')!);

      auth.discardStashedAdminSession();

      http.expectNone(LOGOUT_URL);
      expect(localStorage.getItem('ADMIN_SESSION_BACKUP')).toBeNull();
      expect(sessionStorage.getItem('SESSION')).not.toBeNull();
    });
  });
});
