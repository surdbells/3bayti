import { TestBed, discardPeriodicTasks, fakeAsync, tick } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Router } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';

import { SessionManager } from './session-manager.service';
import { PortalCrudAdapter } from './portal-crud-adapter';
import { PortalAuthService } from './portal-auth.service';
import { SESSION_CHANGED } from './session-errors';
import { GlobalComponent } from '../global-component';

const TICK = 15_000;

/** Store a session whose access token expires `inMs` from now. */
function writeSession(inMs: number): void {
  sessionStorage.setItem('SESSION', GlobalComponent.encodeBase64({
    id: 7,
    token: 'access',
    refresh_token: 'refresh',
    access_token_expires_at: new Date(Date.now() + inMs).toISOString(),
  }));
}

function refreshError(status: number, code?: string): Observable<never> {
  return throwError(() => new HttpErrorResponse({
    status,
    error: code ? { error: { code, message: 'x' } } : null,
  }));
}

/**
 * SessionManager's proactive refresh: a definitive "session is over" answer
 * signs out once; anything transient keeps the session and backs off
 * (30s, 60s, 120s ... capped at 5 min) instead of retrying every 15s tick.
 */
describe('SessionManager', () => {
  let manager: SessionManager;
  let adapter: jasmine.SpyObj<PortalCrudAdapter>;
  let auth: jasmine.SpyObj<PortalAuthService>;
  let router: jasmine.SpyObj<Router>;

  beforeEach(() => {
    adapter = jasmine.createSpyObj<PortalCrudAdapter>('PortalCrudAdapter', ['refreshSession', 'terminate']);
    auth = jasmine.createSpyObj<PortalAuthService>('PortalAuthService', [
      'revokeCurrentSession', 'discardStashedAdminSession',
    ]);
    auth.revokeCurrentSession.and.resolveTo();
    router = jasmine.createSpyObj<Router>('Router', ['navigate']);
    router.navigate.and.resolveTo(true);

    TestBed.configureTestingModule({
      providers: [
        { provide: PortalCrudAdapter, useValue: adapter },
        { provide: PortalAuthService, useValue: auth },
        { provide: Router, useValue: router },
      ],
    });
    manager = TestBed.inject(SessionManager);
  });

  afterEach(() => {
    manager.stop();
    sessionStorage.removeItem('SESSION');
  });

  it('keeps the session on transient failures and backs off exponentially', fakeAsync(() => {
    writeSession(60_000); // inside the 2-minute refresh lead on every tick
    adapter.refreshSession.and.returnValue(refreshError(503));
    manager.start();

    tick(TICK);              // t=15s: first attempt fails, next allowed at 45s
    expect(adapter.refreshSession).toHaveBeenCalledTimes(1);
    tick(TICK);              // t=30s: backing off
    expect(adapter.refreshSession).toHaveBeenCalledTimes(1);
    tick(TICK);              // t=45s: second attempt fails, next allowed at 105s
    expect(adapter.refreshSession).toHaveBeenCalledTimes(2);
    tick(3 * TICK);          // t=90s: still backing off
    expect(adapter.refreshSession).toHaveBeenCalledTimes(2);
    tick(TICK);              // t=105s: third attempt
    expect(adapter.refreshSession).toHaveBeenCalledTimes(3);

    expect(adapter.terminate).not.toHaveBeenCalled();
    expect(router.navigate).not.toHaveBeenCalled();
    expect(sessionStorage.getItem('SESSION')).not.toBeNull();
    discardPeriodicTasks();
  }));

  it('caps the backoff at 5 minutes', fakeAsync(() => {
    writeSession(60_000);
    adapter.refreshSession.and.returnValue(refreshError(0));
    manager.start();

    // Offline: attempts at 15s, 45s, 105s, 225s, 465s (30/60/120/240s waits),
    // then every 300s (the cap) at 765s, 1065s.
    tick(765_000);
    expect(adapter.refreshSession).toHaveBeenCalledTimes(6);
    tick(285_000); // t=1050s
    expect(adapter.refreshSession).toHaveBeenCalledTimes(6);
    tick(TICK);    // t=1065s
    expect(adapter.refreshSession).toHaveBeenCalledTimes(7);
    expect(adapter.terminate).not.toHaveBeenCalled();
    expect(sessionStorage.getItem('SESSION')).not.toBeNull();
    discardPeriodicTasks();
  }));

  for (const [label, status, code] of [
    ['a 403', 403, 'FORBIDDEN'],
    ['a 401 without the refresh code', 401, 'AUTH_INVALID_TOKEN'],
    ['a code-less 401', 401, undefined],
    ['a 429', 429, 'RATE_LIMITED'],
    ['a network error', 0, undefined],
  ] as const) {
    it(`treats ${label} as transient`, fakeAsync(() => {
      writeSession(60_000);
      adapter.refreshSession.and.returnValue(refreshError(status, code));
      manager.start();

      tick(TICK);

      expect(adapter.refreshSession).toHaveBeenCalledTimes(1);
      expect(adapter.terminate).not.toHaveBeenCalled();
      expect(sessionStorage.getItem('SESSION')).not.toBeNull();
      discardPeriodicTasks();
    }));
  }

  it('recovers when a retry succeeds and resets the backoff', fakeAsync(() => {
    writeSession(60_000);
    let attempt = 0;
    adapter.refreshSession.and.callFake(() => {
      attempt++;
      // 1st fails; 2nd succeeds but (in this test) leaves the token near expiry.
      return attempt === 1 ? refreshError(503) : of('new-access');
    });
    manager.start();

    tick(TICK);      // t=15s: fail, back off until 45s
    tick(2 * TICK);  // t=45s: success
    expect(adapter.refreshSession).toHaveBeenCalledTimes(2);
    tick(TICK);      // t=60s: no backoff left, refreshes right away
    expect(adapter.refreshSession).toHaveBeenCalledTimes(3);
    expect(adapter.terminate).not.toHaveBeenCalled();
    discardPeriodicTasks();
  }));

  it('does not refresh while the access token is fresh', fakeAsync(() => {
    writeSession(10 * 60_000);
    adapter.refreshSession.and.returnValue(of('new-access'));
    manager.start();

    tick(4 * TICK);

    expect(adapter.refreshSession).not.toHaveBeenCalled();
    discardPeriodicTasks();
  }));

  it('ends the session exactly once on 401 + AUTH_REFRESH_TOKEN_INVALID', fakeAsync(() => {
    writeSession(60_000);
    adapter.refreshSession.and.returnValue(refreshError(401, 'AUTH_REFRESH_TOKEN_INVALID'));
    manager.start();

    tick(TICK);
    expect(adapter.terminate).toHaveBeenCalledTimes(1);

    tick(10 * TICK); // ticking stopped, no further attempts
    expect(adapter.refreshSession).toHaveBeenCalledTimes(1);
    expect(adapter.terminate).toHaveBeenCalledTimes(1);
    expect(auth.revokeCurrentSession).not.toHaveBeenCalled();
  }));

  it('ignores a refresh dropped because the tab switched sessions: no sign-out, no backoff', fakeAsync(() => {
    writeSession(60_000);
    let attempt = 0;
    adapter.refreshSession.and.callFake(() => (++attempt === 1
      ? throwError(() => new Error(SESSION_CHANGED))
      : of('new-access')));
    manager.start();

    tick(TICK); // the old session's outcome is dropped
    expect(adapter.refreshSession).toHaveBeenCalledTimes(1);
    expect(adapter.terminate).not.toHaveBeenCalled();
    expect(sessionStorage.getItem('SESSION')).not.toBeNull();
    tick(TICK); // no backoff: the current session is checked on the very next tick
    expect(adapter.refreshSession).toHaveBeenCalledTimes(2);
    discardPeriodicTasks();
  }));

  it('"Stay signed in" refreshes immediately even while backing off', fakeAsync(() => {
    writeSession(60_000);
    adapter.refreshSession.and.returnValue(refreshError(503));
    manager.start();

    tick(TICK); // fail, backing off
    adapter.refreshSession.and.returnValue(of('new-access'));
    manager.stayActive();

    expect(adapter.refreshSession).toHaveBeenCalledTimes(2);
    discardPeriodicTasks();
  }));

  it('an explicit "Log out" also revokes the session server-side, before clearing it', () => {
    writeSession(60_000);
    let sessionAtRevoke: string | null = null;
    auth.revokeCurrentSession.and.callFake(() => {
      sessionAtRevoke = sessionStorage.getItem('SESSION');
      return Promise.resolve();
    });

    manager.logoutNow('user');

    expect(auth.revokeCurrentSession).toHaveBeenCalledTimes(1);
    expect(sessionAtRevoke).not.toBeNull();
    // Mid-impersonation, the stashed admin session ends too.
    expect(auth.discardStashedAdminSession).toHaveBeenCalledTimes(1);
    expect(sessionStorage.getItem('SESSION')).toBeNull();
    expect(router.navigate).toHaveBeenCalledWith(['/login'], { queryParams: {} });
  });

  it('an idle timeout signs this tab out locally without revoking server-side', () => {
    writeSession(60_000);

    manager.logoutNow('idle');

    expect(auth.revokeCurrentSession).not.toHaveBeenCalled();
    expect(auth.discardStashedAdminSession).not.toHaveBeenCalled();
    expect(sessionStorage.getItem('SESSION')).toBeNull();
    expect(router.navigate).toHaveBeenCalledWith(['/login'], { queryParams: { reason: 'idle' } });
  });
});
