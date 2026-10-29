import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';

import { ChangePasswordComponent } from './change-password.component';
import { PortalAuthService } from '../../services/portal-auth.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';

/**
 * The forced change-password screen's "Sign out" escape is an explicit
 * sign-out: it ends the session server-side (and, mid-impersonation, the
 * stashed admin session) before clearing it locally. The class is built in an
 * injection context rather than through TestBed.createComponent, which keeps
 * this spec independent of template compilation.
 */
describe('ChangePasswordComponent sign-out', () => {
  let auth: jasmine.SpyObj<PortalAuthService>;
  let router: jasmine.SpyObj<Router>;

  beforeEach(() => {
    auth = jasmine.createSpyObj<PortalAuthService>('PortalAuthService', [
      'revokeCurrentSession', 'discardStashedAdminSession',
    ]);
    router = jasmine.createSpyObj<Router>('Router', ['navigate']);
    router.navigate.and.resolveTo(true);
    TestBed.configureTestingModule({
      providers: [
        { provide: PortalAuthService, useValue: auth },
        { provide: Router, useValue: router },
        { provide: PortalCrudAdapter, useValue: {} },
        { provide: HotToastService, useValue: {} },
      ],
    });
  });

  afterEach(() => sessionStorage.removeItem('SESSION'));

  it('revokes server-side while the session is still readable, then clears it and goes to login', () => {
    sessionStorage.setItem('SESSION', GlobalComponent.encodeBase64({ id: 7, token: 't', refresh_token: 'r' }));
    const seen: (string | null)[] = [];
    auth.revokeCurrentSession.and.callFake(() => {
      seen.push(sessionStorage.getItem('SESSION'));
      return Promise.resolve();
    });
    auth.discardStashedAdminSession.and.callFake(() => {
      seen.push(sessionStorage.getItem('SESSION'));
    });
    const component = TestBed.runInInjectionContext(() => new ChangePasswordComponent());

    component.signOut();

    expect(auth.revokeCurrentSession).toHaveBeenCalledTimes(1);
    expect(auth.discardStashedAdminSession).toHaveBeenCalledTimes(1);
    expect(seen.length).toBe(2);
    expect(seen.every((s) => s !== null)).toBeTrue();
    expect(sessionStorage.getItem('SESSION')).toBeNull();
    expect(router.navigate).toHaveBeenCalledOnceWith(['/login']);
  });
});
