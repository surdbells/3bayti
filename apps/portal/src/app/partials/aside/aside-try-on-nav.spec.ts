import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, of, throwError } from 'rxjs';

import { AsideComponent } from './aside.component';
import { NavBadgeService } from './nav-badge.service';
import { PermissionService } from '../../services/permission.service';
import { PortalAuthService } from '../../services/portal-auth.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { TryOnAdminService } from '../../services/try-on-admin.service';
import { HotToastService } from '../../shared/toast/toast.service';

/**
 * The side nav's "Virtual Try-On" rule (no component compilation): the status
 * is fetched only for staff who have products.view (once per session), and the
 * entry shows only with products.view AND Try-On available; unknown or failed
 * status = hidden.
 */

const STAFF = { id: 7, is_admin: true, is_vendor: false, is_finance: false, is_support: false };
const VENDOR = { id: 8, is_admin: false, is_vendor: true, is_finance: false, is_support: false };

function fakePerms(granted: string[]) {
  const permissions = signal<string[]>(granted);
  const svc = {
    can: (p: string) => permissions().includes(p),
    load: jasmine.createSpy('load'),
  };
  return { permissions, svc };
}

function fakeTryOn(available = false) {
  return {
    available: signal(available),
    ensureStatus: jasmine.createSpy('ensureStatus'),
  };
}

function configure(perms: ReturnType<typeof fakePerms>, tryOnProvider: { provide: unknown; useValue: unknown }[]) {
  TestBed.configureTestingModule({
    providers: [
      { provide: PermissionService, useValue: perms.svc },
      { provide: NavBadgeService, useValue: { start: jasmine.createSpy('start') } },
      ...tryOnProvider,
    ],
  });
}

function createAside(): AsideComponent {
  const router = { url: '/backend' } as unknown as Router;
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  const auth = jasmine.createSpyObj<PortalAuthService>('PortalAuthService', ['signOut']);
  return TestBed.runInInjectionContext(() => new AsideComponent(router, toast, auth));
}

/** ngOnInit with the given session, then run the pending effects. */
function init(component: AsideComponent, session: object): void {
  sessionStorage.setItem('SESSION', btoa(JSON.stringify(session)));
  component.ngOnInit();
  TestBed.flushEffects();
}

const shown = (component: AsideComponent): boolean => component['showTryOnNav']();

describe('AsideComponent – Virtual Try-On nav entry (logic)', () => {
  let savedSession: string | null;

  beforeEach(() => {
    savedSession = sessionStorage.getItem('SESSION');
  });

  afterEach(() => {
    if (savedSession === null) sessionStorage.removeItem('SESSION');
    else sessionStorage.setItem('SESSION', savedSession);
  });

  it('fetches the status for staff with products.view, and shows the entry only while Try-On is available', () => {
    const perms = fakePerms(['products.view']);
    const tryOn = fakeTryOn(false);
    configure(perms, [{ provide: TryOnAdminService, useValue: tryOn }]);
    const aside = createAside();

    init(aside, STAFF);

    expect(perms.svc.load).toHaveBeenCalled();
    expect(tryOn.ensureStatus).toHaveBeenCalledTimes(1);
    expect(shown(aside)).toBeFalse(); // unknown / off → hidden

    tryOn.available.set(true);
    expect(shown(aside)).toBeTrue();

    tryOn.available.set(false);
    expect(shown(aside)).toBeFalse();
  });

  it('waits for the permissions to load before fetching, and hides the entry without products.view', () => {
    const perms = fakePerms([]);
    const tryOn = fakeTryOn(true);
    configure(perms, [{ provide: TryOnAdminService, useValue: tryOn }]);
    const aside = createAside();

    init(aside, STAFF);

    expect(tryOn.ensureStatus).not.toHaveBeenCalled();
    expect(shown(aside)).toBeFalse(); // available, but no products.view

    perms.permissions.set(['products.view']); // permissions arrive
    TestBed.flushEffects();

    expect(tryOn.ensureStatus).toHaveBeenCalledTimes(1);
    expect(shown(aside)).toBeTrue();
  });

  it('never fetches or shows it for a vendor session', () => {
    const perms = fakePerms(['products.view']);
    const tryOn = fakeTryOn(true);
    configure(perms, [{ provide: TryOnAdminService, useValue: tryOn }]);
    const aside = createAside();

    init(aside, VENDOR);

    expect(perms.svc.load).not.toHaveBeenCalled();
    expect(tryOn.ensureStatus).not.toHaveBeenCalled();
    expect(shown(aside)).toBeFalse();
  });

  describe('with the real status cache', () => {
    function realService(statusReply: Observable<unknown>) {
      const adapter = {
        get_v3: jasmine.createSpy('get_v3').and.returnValue(statusReply),
        getSession: jasmine.createSpy('getSession').and.returnValue({ id: 7 }),
      };
      const perms = fakePerms(['products.view']);
      configure(perms, [{ provide: PortalCrudAdapter, useValue: adapter }]);
      return adapter;
    }

    it('shows the entry once the status says available, fetching it once per session', () => {
      const adapter = realService(of({
        data: { available: true, tryon_flag: true, ai_enabled: true, enabled_products: 3, eligible_formats: ['jpeg', 'png', 'webp'] },
      }));
      const aside = createAside();
      init(aside, STAFF);

      expect(adapter.get_v3).toHaveBeenCalledOnceWith('GET /admin/try-on/status');
      expect(shown(aside)).toBeTrue();

      // Another render of the nav in the same session reuses the cached status.
      const again = createAside();
      init(again, STAFF);
      expect(adapter.get_v3).toHaveBeenCalledTimes(1);
      expect(shown(again)).toBeTrue();
    });

    it('stays hidden when Try-On is off', () => {
      realService(of({ data: { available: false, tryon_flag: false, ai_enabled: true, enabled_products: 0 } }));
      const aside = createAside();
      init(aside, STAFF);
      expect(shown(aside)).toBeFalse();
    });

    it('fails closed: hidden (and not refetched) when the status request fails', () => {
      const adapter = realService(throwError(() => ({ status: 500 })));
      const aside = createAside();
      init(aside, STAFF);

      expect(adapter.get_v3).toHaveBeenCalledTimes(1);
      expect(shown(aside)).toBeFalse();

      const again = createAside();
      init(again, STAFF);
      expect(adapter.get_v3).toHaveBeenCalledTimes(1);
      expect(shown(again)).toBeFalse();
    });
  });
});
