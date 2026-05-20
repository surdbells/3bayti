import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ProfileService } from './profile.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { ApiConfigService } from '../../core/api/api-config.service';
import { AuthService } from '../../core/auth/auth.service';
import type { AuthUser } from '../../core/auth/auth.types';

const V3 = 'https://api-v3.3bayti.ae';

function makeUser(o: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 1, email: 'a@b.co', phone: '+971500000000', country_code: 'AE',
    first_name: 'Sara', last_name: 'Khan', gender: 'female',
    dob: '1990-01-01', locale: 'en', timezone: 'Asia/Dubai',
    is_phone_verified: true, is_email_verified: true, roles: [],
    is_store_approved: false, is_store_active: false, last_login_at: null,
    ...o,
  } as AuthUser;
}

class StubAuthService {
  applied: AuthUser[] = [];
  private _user = signal<AuthUser | null>(makeUser());
  currentUser = this._user.asReadonly();
  /** Mirrors the real AuthService.applyProfile contract: no-op when
   *  signed out, so a stale profile response can't resurrect a
   *  logged-out session. */
  applyProfile(u: AuthUser): void {
    if (this._user() !== null) {
      this.applied.push(u);
      this._user.set(u);
    }
  }
  setSignedOut(): void { this._user.set(null); }
}

function setup(): {
  service: ProfileService;
  controller: HttpTestingController;
  auth: StubAuthService;
} {
  const auth = new StubAuthService();
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      RoutedHttpClient,
      ApiConfigService,
      ProfileService,
      { provide: AuthService, useValue: auth },
    ],
  });
  return {
    service: TestBed.inject(ProfileService),
    controller: TestBed.inject(HttpTestingController),
    auth,
  };
}

describe('ProfileService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('getProfile', () => {
    it('GETs /v3/me/profile and unwraps user', async () => {
      const { service, controller } = setup();
      const promise = service.getProfile();
      const req = controller.expectOne(`${V3}/v3/me/profile`);
      expect(req.request.method).toBe('GET');
      req.flush({ data: { user: makeUser({ first_name: 'Layla' }) } });
      const user = await promise;
      expect(user.first_name).toBe('Layla');
    });

    it('toggles isLoading during the fetch', async () => {
      const { service, controller } = setup();
      const promise = service.getProfile();
      expect(service.isLoading()).toBe(true);
      controller.expectOne(`${V3}/v3/me/profile`).flush({ data: { user: makeUser() } });
      await promise;
      expect(service.isLoading()).toBe(false);
    });
  });

  describe('updateProfile', () => {
    it('PATCHes /v3/me/profile with only the provided keys', async () => {
      const { service, controller } = setup();
      const promise = service.updateProfile({ first_name: 'Noor', dob: null });
      const req = controller.expectOne(`${V3}/v3/me/profile`);
      expect(req.request.method).toBe('PATCH');
      expect(req.request.body).toEqual({ first_name: 'Noor', dob: null });
      req.flush({ data: { user: makeUser({ first_name: 'Noor', dob: null }) } });
      const user = await promise;
      expect(user.first_name).toBe('Noor');
    });

    it('syncs the updated user into AuthService', async () => {
      const { service, controller, auth } = setup();
      const promise = service.updateProfile({ first_name: 'Mariam' });
      controller.expectOne(`${V3}/v3/me/profile`)
        .flush({ data: { user: makeUser({ first_name: 'Mariam' }) } });
      await promise;
      expect(auth.applied).toHaveLength(1);
      expect(auth.applied[0].first_name).toBe('Mariam');
      expect(auth.currentUser()?.first_name).toBe('Mariam');
    });

    it('does not resurrect a signed-out session', async () => {
      const { service, controller, auth } = setup();
      auth.setSignedOut();
      const promise = service.updateProfile({ first_name: 'X' });
      controller.expectOne(`${V3}/v3/me/profile`)
        .flush({ data: { user: makeUser({ first_name: 'X' }) } });
      await promise;
      /* applyProfile is a no-op when signed out. */
      expect(auth.currentUser()).toBeNull();
    });

    it('toggles isSaving during the update', async () => {
      const { service, controller } = setup();
      const promise = service.updateProfile({ first_name: 'Z' });
      expect(service.isSaving()).toBe(true);
      controller.expectOne(`${V3}/v3/me/profile`).flush({ data: { user: makeUser() } });
      await promise;
      expect(service.isSaving()).toBe(false);
    });

    it('propagates a validation error and leaves isSaving false', async () => {
      const { service, controller } = setup();
      const promise = service.updateProfile({ phone: 'bad' });
      controller.expectOne(`${V3}/v3/me/profile`)
        .flush({ error_code: 'VALIDATION_FAILED' }, { status: 422, statusText: 'Unprocessable' });
      await expect(promise).rejects.toBeDefined();
      expect(service.isSaving()).toBe(false);
    });
  });
});
