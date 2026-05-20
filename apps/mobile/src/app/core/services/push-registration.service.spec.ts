import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { PushRegistrationService } from './push-registration.service';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';
import { Capacitor } from '@capacitor/core';

class AdapterStub {
  postResp: any = { response_code: 201, status: 'success', data: { id: 1, platform: 'android', is_active: true } };
  deleteResp: any = { response_code: 204, status: 'success', data: null };
  lastPost: any = null;
  lastDelete: any = null;

  post_v3(routeKey: string, body: any, opts: any) {
    this.lastPost = { routeKey, body, opts };
    return of(this.postResp);
  }
  delete_v3(routeKey: string, opts: any) {
    this.lastDelete = { routeKey, opts };
    return of(this.deleteResp);
  }
}

describe('PushRegistrationService', () => {
  let service: PushRegistrationService;
  let adapter: AdapterStub;

  beforeEach(() => {
    adapter = new AdapterStub();
    TestBed.configureTestingModule({
      providers: [
        PushRegistrationService,
        { provide: MobileNetworkAdapter, useValue: adapter },
      ],
    });
    service = TestBed.inject(PushRegistrationService);
  });

  describe('register', () => {
    it('POSTs token + android platform by default', async () => {
      spyOn(Capacitor, 'getPlatform').and.returnValue('android');
      const ok = await service.register('auth-tok', 'fcm-device-1');
      expect(adapter.lastPost.routeKey).toBe('POST /me/device-tokens');
      expect(adapter.lastPost.body).toEqual({ token: 'fcm-device-1', platform: 'android' });
      expect(adapter.lastPost.opts).toEqual({ authToken: 'auth-tok' });
      expect(ok).toBeTrue();
    });

    it('maps ios platform', async () => {
      spyOn(Capacitor, 'getPlatform').and.returnValue('ios');
      await service.register('auth-tok', 'fcm-device-1');
      expect(adapter.lastPost.body.platform).toBe('ios');
    });

    it('maps web (and any non-ios) platform to android', async () => {
      spyOn(Capacitor, 'getPlatform').and.returnValue('web');
      await service.register('auth-tok', 'fcm-device-1');
      expect(adapter.lastPost.body.platform).toBe('android');
    });

    it('returns true on 200 (re-registration)', async () => {
      spyOn(Capacitor, 'getPlatform').and.returnValue('android');
      adapter.postResp = { response_code: 200, status: 'success', data: {} };
      const ok = await service.register('auth-tok', 'fcm-device-1');
      expect(ok).toBeTrue();
    });

    it('returns false on an error envelope', async () => {
      spyOn(Capacitor, 'getPlatform').and.returnValue('android');
      adapter.postResp = { response_code: 422, status: 'error', data: null };
      const ok = await service.register('auth-tok', 'fcm-device-1');
      expect(ok).toBeFalse();
    });

    it('no-ops (false) when authToken is empty', async () => {
      const ok = await service.register('', 'fcm-device-1');
      expect(ok).toBeFalse();
      expect(adapter.lastPost).toBeNull();
    });

    it('no-ops (false) when deviceToken is empty', async () => {
      const ok = await service.register('auth-tok', '');
      expect(ok).toBeFalse();
      expect(adapter.lastPost).toBeNull();
    });
  });

  describe('deactivate', () => {
    it('DELETEs with the token in the body', async () => {
      const ok = await service.deactivate('auth-tok', 'fcm-device-1');
      expect(adapter.lastDelete.routeKey).toBe('DELETE /me/device-tokens');
      expect(adapter.lastDelete.opts.authToken).toBe('auth-tok');
      expect(adapter.lastDelete.opts.body).toEqual({ token: 'fcm-device-1' });
      expect(ok).toBeTrue();
    });

    it('treats 204 as success', async () => {
      adapter.deleteResp = { response_code: 204, status: 'success', data: null };
      const ok = await service.deactivate('auth-tok', 'fcm-device-1');
      expect(ok).toBeTrue();
    });

    it('no-ops (false) when authToken is empty', async () => {
      const ok = await service.deactivate('', 'fcm-device-1');
      expect(ok).toBeFalse();
      expect(adapter.lastDelete).toBeNull();
    });

    it('no-ops (false) when deviceToken is empty', async () => {
      const ok = await service.deactivate('auth-tok', '');
      expect(ok).toBeFalse();
      expect(adapter.lastDelete).toBeNull();
    });
  });
});
