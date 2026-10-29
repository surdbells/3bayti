import { TestBed, fakeAsync, flushMicrotasks } from '@angular/core/testing';

import { ImpersonationService } from './impersonation.service';
import { PortalCrudAdapter } from './portal-crud-adapter';
import { PortalAuthService } from './portal-auth.service';
import { HotToastService } from '../shared/toast/toast.service';

/**
 * Exiting impersonation ends the impersonation session server-side (only that
 * session, never the vendor's own) before the admin session is restored, and
 * only reloads the page once that call has had its chance to go out.
 */
describe('ImpersonationService.exit', () => {
  let service: ImpersonationService;
  let auth: jasmine.SpyObj<PortalAuthService>;
  let navigate: jasmine.Spy;
  let releaseRevoke: () => void;

  beforeEach(() => {
    auth = jasmine.createSpyObj<PortalAuthService>('PortalAuthService', [
      'revokeCurrentSession', 'stopImpersonation', 'isImpersonating', 'impersonatedName',
    ]);
    auth.revokeCurrentSession.and.returnValue(new Promise<void>((resolve) => (releaseRevoke = resolve)));

    TestBed.configureTestingModule({
      providers: [
        { provide: PortalAuthService, useValue: auth },
        { provide: PortalCrudAdapter, useValue: jasmine.createSpyObj('PortalCrudAdapter', ['post_v3']) },
        { provide: HotToastService, useValue: jasmine.createSpyObj('HotToastService', ['error']) },
      ],
    });
    service = TestBed.inject(ImpersonationService);
    navigate = spyOn<any>(service, 'hardNavigate');
  });

  it('revokes the impersonation session before restoring the admin session', fakeAsync(() => {
    auth.stopImpersonation.and.returnValue(true);

    service.exit();

    expect(auth.revokeCurrentSession).toHaveBeenCalledBefore(auth.stopImpersonation);
    expect(auth.stopImpersonation).toHaveBeenCalledTimes(1);
    // The reload waits for the logout call (bounded inside revokeCurrentSession).
    flushMicrotasks();
    expect(navigate).not.toHaveBeenCalled();

    releaseRevoke();
    flushMicrotasks();
    expect(navigate).toHaveBeenCalledOnceWith('/backend');
  }));

  it('still revokes and signs out cleanly when the admin backup is missing', fakeAsync(() => {
    auth.stopImpersonation.and.returnValue(false);
    sessionStorage.setItem('SESSION', 'impersonation');

    service.exit();
    releaseRevoke();
    flushMicrotasks();

    expect(auth.revokeCurrentSession).toHaveBeenCalledTimes(1);
    expect(sessionStorage.getItem('SESSION')).toBeNull();
    expect(navigate).toHaveBeenCalledOnceWith('/');
  }));

  it('ignores a double tap on Exit', fakeAsync(() => {
    auth.stopImpersonation.and.returnValue(true);

    service.exit();
    service.exit();
    releaseRevoke();
    flushMicrotasks();

    expect(auth.revokeCurrentSession).toHaveBeenCalledTimes(1);
    expect(auth.stopImpersonation).toHaveBeenCalledTimes(1);
    expect(navigate).toHaveBeenCalledTimes(1);
  }));
});
