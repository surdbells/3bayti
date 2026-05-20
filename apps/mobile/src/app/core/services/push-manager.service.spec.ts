import { TestBed } from '@angular/core/testing';
import { Capacitor } from '@capacitor/core';
import { PushManager, resolvePushDeepLink, PushTapHandler } from './push-manager.service';
import { PushRegistrationService } from './push-registration.service';

describe('resolvePushDeepLink', () => {
  it('maps a string order_id to /orders/:id', () => {
    expect(resolvePushDeepLink({ type: 'order.shipped', order_id: '123', order_reference: 'V3-1' }))
      .toBe('/orders/123');
  });

  it('tolerates a numeric order_id', () => {
    expect(resolvePushDeepLink({ order_id: 456 })).toBe('/orders/456');
  });

  it('trims a padded order_id', () => {
    expect(resolvePushDeepLink({ order_id: '  789  ' })).toBe('/orders/789');
  });

  it('returns null when order_id is missing', () => {
    expect(resolvePushDeepLink({ type: 'order.paid' })).toBeNull();
  });

  it('returns null when order_id is blank', () => {
    expect(resolvePushDeepLink({ order_id: '   ' })).toBeNull();
  });

  it('returns null for null/undefined payloads', () => {
    expect(resolvePushDeepLink(null)).toBeNull();
    expect(resolvePushDeepLink(undefined)).toBeNull();
  });
});

class RegistrationStub {
  registerCalls: Array<{ authToken: string; deviceToken: string }> = [];
  deactivateCalls: Array<{ authToken: string; deviceToken: string }> = [];
  registerResult = true;
  deactivateResult = true;

  async register(authToken: string, deviceToken: string): Promise<boolean> {
    this.registerCalls.push({ authToken, deviceToken });
    return this.registerResult;
  }
  async deactivate(authToken: string, deviceToken: string): Promise<boolean> {
    this.deactivateCalls.push({ authToken, deviceToken });
    return this.deactivateResult;
  }
}

describe('PushManager', () => {
  let manager: PushManager;
  let registration: RegistrationStub;

  beforeEach(() => {
    registration = new RegistrationStub();
    TestBed.configureTestingModule({
      providers: [
        PushManager,
        { provide: PushRegistrationService, useValue: registration },
      ],
    });
    manager = TestBed.inject(PushManager);
  });

  describe('non-native (web/test) is inert', () => {
    beforeEach(() => spyOn(Capacitor, 'isNativePlatform').and.returnValue(false));

    it('initListeners does nothing', async () => {
      await manager.initListeners();
      // No throw, nothing registered.
      expect(registration.registerCalls.length).toBe(0);
    });

    it('onSignedIn does not attempt registration', async () => {
      await manager.onSignedIn();
      expect(registration.registerCalls.length).toBe(0);
    });

    it('onSignedOut does not attempt deactivation', async () => {
      await manager.onSignedOut('auth-tok');
      expect(registration.deactivateCalls.length).toBe(0);
    });
  });

  describe('tap handler delegation', () => {
    it('invokes the set handler with the notification data', () => {
      const seen: Array<Record<string, unknown>> = [];
      const handler: PushTapHandler = (d) => seen.push(d);
      manager.setTapHandler(handler);
      // Reach the private handler the way the listener would.
      (manager as any).tapHandler({ order_id: '5' });
      expect(seen).toEqual([{ order_id: '5' }]);
    });
  });

  describe('onSignedOut (native)', () => {
    beforeEach(() => spyOn(Capacitor, 'isNativePlatform').and.returnValue(true));

    it('deactivates with the supplied auth token + cached device token', async () => {
      // Seed a device token as if the plugin had delivered one.
      (manager as any).deviceToken = 'fcm-dev-1';
      await manager.onSignedOut('auth-tok');
      expect(registration.deactivateCalls).toEqual([
        { authToken: 'auth-tok', deviceToken: 'fcm-dev-1' },
      ]);
    });

    it('no-ops when there is no device token', async () => {
      (manager as any).deviceToken = null;
      await manager.onSignedOut('auth-tok');
      expect(registration.deactivateCalls.length).toBe(0);
    });

    it('no-ops when the auth token is empty', async () => {
      (manager as any).deviceToken = 'fcm-dev-1';
      await manager.onSignedOut('');
      expect(registration.deactivateCalls.length).toBe(0);
    });

    it('swallows deactivate errors', async () => {
      (manager as any).deviceToken = 'fcm-dev-1';
      registration.deactivate = () => Promise.reject(new Error('boom'));
      await expectAsync(manager.onSignedOut('auth-tok')).toBeResolved();
    });
  });

  describe('onSignedOutReadingToken (native)', () => {
    beforeEach(() => spyOn(Capacitor, 'isNativePlatform').and.returnValue(true));
    afterEach(() => window.localStorage.removeItem('CapacitorStorage.user'));

    it('reads the token from Preferences then deactivates', async () => {
      (manager as any).deviceToken = 'fcm-dev-1';
      // Capacitor Preferences (web) is a Proxy whose `get` can't be
      // spied; seed the real plugin via its localStorage backing store.
      window.localStorage.setItem(
        'CapacitorStorage.user',
        JSON.stringify({ id: 1, token: 'stored-auth-tok' }),
      );

      await manager.onSignedOutReadingToken();

      expect(registration.deactivateCalls).toEqual([
        { authToken: 'stored-auth-tok', deviceToken: 'fcm-dev-1' },
      ]);
    });

    it('no-ops when no device token cached', async () => {
      (manager as any).deviceToken = null;
      window.localStorage.setItem(
        'CapacitorStorage.user',
        JSON.stringify({ id: 1, token: 'stored-auth-tok' }),
      );
      await manager.onSignedOutReadingToken();
      expect(registration.deactivateCalls.length).toBe(0);
    });

    it('no-ops when no stored user', async () => {
      (manager as any).deviceToken = 'fcm-dev-1';
      // No CapacitorStorage.user seeded → readAuthToken returns ''.
      await manager.onSignedOutReadingToken();
      expect(registration.deactivateCalls.length).toBe(0);
    });
  });
});
