import { TestBed } from '@angular/core/testing';
import { Capacitor } from '@capacitor/core';
import { PushManager, resolvePushDeepLink, PushTapHandler } from './push-manager.service';
import { PushRegistrationService } from './push-registration.service';

describe('resolvePushDeepLink', () => {
  describe('order lifecycle -> /orders/:id', () => {
    it('maps a string order_id to /orders/:id', () => {
      expect(resolvePushDeepLink({ type: 'order.shipped', order_id: '123', order_reference: 'V3-1' }))
        .toBe('/orders/123');
    });

    it('maps every order lifecycle type to /orders/:id', () => {
      const types = [
        'order.placed', 'order.paid', 'order.accepted', 'order.preparing',
        'order.rejected', 'order.shipped', 'order.delivered', 'order.cancelled',
        'order.refunded',
      ];
      for (const type of types) {
        expect(resolvePushDeepLink({ type, order_id: '42' })).toBe('/orders/42');
      }
    });

    it('maps order.review_prompt (post-delivery follow-up) to /orders/:id', () => {
      expect(resolvePushDeepLink({ type: 'order.review_prompt', order_id: '77', order_reference: 'V3-7' }))
        .toBe('/orders/77');
    });

    it('tolerates a numeric order_id', () => {
      expect(resolvePushDeepLink({ order_id: 456 })).toBe('/orders/456');
    });

    it('trims a padded order_id', () => {
      expect(resolvePushDeepLink({ order_id: '  789  ' })).toBe('/orders/789');
    });

    it('falls back to /orders/:id when type is absent but order_id present', () => {
      expect(resolvePushDeepLink({ order_id: '5' })).toBe('/orders/5');
    });

    it('returns null when an order type is missing order_id', () => {
      expect(resolvePushDeepLink({ type: 'order.paid' })).toBeNull();
    });

    it('returns null when order_id is blank', () => {
      expect(resolvePushDeepLink({ order_id: '   ' })).toBeNull();
    });
  });

  describe('chat.message -> /chat?uuid=', () => {
    it('maps conversation_uuid to a query-param chat URL', () => {
      expect(resolvePushDeepLink({ type: 'chat.message', conversation_uuid: 'abc-123', order_reference: 'V3-1' }))
        .toBe('/chat?uuid=abc-123');
    });

    it('url-encodes the conversation uuid', () => {
      expect(resolvePushDeepLink({ type: 'chat.message', conversation_uuid: 'a b/c' }))
        .toBe('/chat?uuid=a%20b%2Fc');
    });

    it('returns null when conversation_uuid is missing', () => {
      expect(resolvePushDeepLink({ type: 'chat.message' })).toBeNull();
    });
  });

  describe('gift_card.expiry_nudge -> /my-gift-cards', () => {
    it('routes to the wallet (detail page is state-driven, id alone insufficient)', () => {
      expect(resolvePushDeepLink({ type: 'gift_card.expiry_nudge', card_id: '9', balance: '50' }))
        .toBe('/my-gift-cards');
    });
  });

  describe('cart.abandoned -> /cart', () => {
    it('routes to the cart', () => {
      expect(resolvePushDeepLink({ type: 'cart.abandoned', cart_id: '12' })).toBe('/cart');
    });

    it('routes to the cart even without cart_id', () => {
      expect(resolvePushDeepLink({ type: 'cart.abandoned' })).toBe('/cart');
    });
  });

  describe('re_engagement.nudge -> /home', () => {
    it('routes to the home/shop tab', () => {
      expect(resolvePushDeepLink({ type: 're_engagement.nudge' })).toBe('/home');
    });
  });

  describe('unknown / empty payloads', () => {
    it('returns null for an unrecognised type', () => {
      expect(resolvePushDeepLink({ type: 'something.unknown' })).toBeNull();
    });

    it('returns null for null/undefined payloads', () => {
      expect(resolvePushDeepLink(null)).toBeNull();
      expect(resolvePushDeepLink(undefined)).toBeNull();
    });
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
