import { TestBed } from '@angular/core/testing';
import { NavController } from '@ionic/angular/standalone';
import { Observable, of, throwError } from 'rxjs';

import { AuthSessionService } from './auth-session.service';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';
import { PushManager } from './push-manager.service';
import { LocalCartService } from './local-cart.service';
import { PendingOrdersService } from './pending-orders.service';
import { CartCountService } from './cart-count.service';
import {
  SESSION_PERSISTENCE,
  SessionStore,
  type SessionPersistence,
  type SessionTokenPair,
} from './session-store.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

class MemoryPersistence implements SessionPersistence {
  constructor(public value: string | null) {}
  read(): Promise<string | null> { return Promise.resolve(this.value); }
  write(value: string): Promise<void> { this.value = value; return Promise.resolve(); }
  remove(): Promise<void> { this.value = null; return Promise.resolve(); }
}

class AdapterStub {
  calls: Array<{ route: string; body: unknown; opts: unknown; sessionAtCall: boolean }> = [];
  response: Observable<unknown> = of({ response_code: 204, status: 'success', data: null });
  refreshedWith: string[] = [];
  refreshResult: SessionTokenPair | null = { access_token: 'fresh-access', refresh_token: 'fresh-refresh' };
  /** Ordered log of remote clean-up steps (shared with the push stub). */
  log!: string[];
  store!: SessionStore;
  post_v3(route: string, body: unknown, opts?: unknown): Observable<unknown> {
    this.calls.push({ route, body, opts, sessionAtCall: this.store.hasSession() });
    this.log.push(`post ${route}`);
    return this.response;
  }
  refreshDetached(refreshToken: string): Promise<SessionTokenPair | null> {
    this.refreshedWith.push(refreshToken);
    this.log.push('refresh');
    return Promise.resolve(this.refreshResult);
  }
}

/**
 * Stand-in for PushManager.onSignedOut: deactivation "fails" for the tokens
 * in `rejects` and then asks for a renewed token, like the real one.
 */
class PushStub {
  signedOutWith: string[] = [];
  rejects = new Set<string>();
  log!: string[];
  async onSignedOut(token: string, renew?: () => Promise<string | null>): Promise<void> {
    this.signedOutWith.push(token);
    this.log.push(`deactivate ${token}`);
    if (this.rejects.has(token) && renew) {
      const fresh = await renew();
      if (fresh) {
        this.signedOutWith.push(fresh);
        this.log.push(`deactivate ${fresh}`);
      }
    }
  }
}

const SESSION = { id: 3, token: 'access-1', refresh_token: 'refresh-1' };

/** Let the fire-and-forget remote clean-up run. */
async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await new Promise((r) => setTimeout(r, 0));
  }
}

async function setup(session: Record<string, unknown> | null = SESSION) {
  const log: string[] = [];
  const adapter = new AdapterStub();
  adapter.log = log;
  const push = new PushStub();
  push.log = log;
  const localCart = jasmine.createSpyObj('LocalCartService', { clear: Promise.resolve() });
  const pending = jasmine.createSpyObj('PendingOrdersService', ['clear']);
  const cartCount = jasmine.createSpyObj('CartCountService', { refresh: Promise.resolve() });
  const nav = jasmine.createSpyObj('NavController', { navigateRoot: Promise.resolve(true) });
  const toast = jasmine.createSpyObj('AxNotificationService', ['error', 'success']);

  TestBed.configureTestingModule({
    providers: [
      { provide: SESSION_PERSISTENCE, useValue: new MemoryPersistence(session ? JSON.stringify(session) : null) },
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: PushManager, useValue: push },
      { provide: LocalCartService, useValue: localCart },
      { provide: PendingOrdersService, useValue: pending },
      { provide: CartCountService, useValue: cartCount },
      { provide: NavController, useValue: nav },
      { provide: AxNotificationService, useValue: toast },
      { provide: I18nService, useValue: { t: (k: string) => k } },
    ],
  });
  const store = TestBed.inject(SessionStore);
  await store.ready();
  adapter.store = store;
  const service = TestBed.inject(AuthSessionService);
  return { service, store, adapter, push, log, localCart, pending, cartCount, nav, toast };
}

describe('AuthSessionService', () => {
  describe('logout', () => {
    it('ends the local session FIRST, then revokes this session without a bearer', async () => {
      const { service, store, adapter, push, nav } = await setup();

      await service.logout();
      expect(store.hasSession()).toBeFalse();
      expect(nav.navigateRoot).toHaveBeenCalledWith(['/home']);
      await settle();

      expect(adapter.calls.length).toBe(1);
      const call = adapter.calls[0];
      expect(call.route).toBe('POST /auth/logout');
      expect(call.body).toEqual({ refresh_token: 'refresh-1' });
      expect(call.opts).toBeUndefined(); // anonymous: no bearer, no refresh-and-retry
      expect(call.sessionAtCall).toBeFalse();
      expect(push.signedOutWith).toEqual(['access-1']);
      expect(adapter.refreshedWith).toEqual([]); // deactivation worked: no renewal
    });

    it('deactivates push BEFORE revoking, renewing an expired access token with the captured refresh token', async () => {
      const { service, adapter, push, log } = await setup();
      push.rejects.add('access-1'); // captured access token expired (~15 min TTL)

      await service.logout();
      await settle();

      expect(adapter.refreshedWith).toEqual(['refresh-1']);
      expect(push.signedOutWith).toEqual(['access-1', 'fresh-access']);
      // The logout comes last and names the family by the captured token
      // (it ends the pair the renewal just minted too).
      expect(log).toEqual(['deactivate access-1', 'refresh', 'deactivate fresh-access', 'post POST /auth/logout']);
      expect(adapter.calls[0].body).toEqual({ refresh_token: 'refresh-1' });
    });

    it('the clean-up only ever uses the captured tokens, never the next account\'s session', async () => {
      const { service, store, adapter, push } = await setup();
      push.rejects.add('access-1');

      await service.logout();
      await store.signIn({ id: 9, token: 'next-access', refresh_token: 'next-refresh' });
      await settle();

      expect(adapter.refreshedWith).toEqual(['refresh-1']);
      expect(push.signedOutWith).not.toContain('next-access');
      expect(adapter.calls.map((c) => c.body)).toEqual([{ refresh_token: 'refresh-1' }]);
      expect(store.currentAccessToken()).toBe('next-access');
    });

    it('still revokes when no fresh token can be had', async () => {
      const { service, adapter, push } = await setup();
      push.rejects.add('access-1');
      adapter.refreshResult = null;

      await service.logout();
      await settle();

      expect(push.signedOutWith).toEqual(['access-1']);
      expect(adapter.calls.map((c) => c.route)).toEqual(['POST /auth/logout']);
    });

    it('clears the local per-user state', async () => {
      const { service, localCart, pending, cartCount, toast } = await setup();
      await service.logout();
      expect(pending.clear).toHaveBeenCalled();
      expect(localCart.clear).toHaveBeenCalled();
      expect(cartCount.refresh).toHaveBeenCalled();
      expect(toast.error).not.toHaveBeenCalled();
    });

    it('still signs out offline (the revoke fails silently)', async () => {
      const { service, store, adapter, nav } = await setup();
      adapter.response = throwError(() => new Error('offline'));
      await expectAsync(service.logout()).toBeResolved();
      await settle();
      expect(store.hasSession()).toBeFalse();
      expect(nav.navigateRoot).toHaveBeenCalled();
    });

    it('skips the revoke (and any renewal) when the blob has no refresh token', async () => {
      const { service, adapter, push } = await setup({ id: 3, token: 'legacy' });
      push.rejects.add('legacy');
      await service.logout();
      await settle();
      expect(adapter.calls.length).toBe(0);
      expect(adapter.refreshedWith).toEqual([]);
    });
  });

  describe('endAfterAccountDeletion', () => {
    it('ends the session as "deleted" and revokes it, without navigating', async () => {
      const { service, store, adapter, nav } = await setup();
      const ended: string[] = [];
      store.sessionEnded$.subscribe((r) => ended.push(r));

      await service.endAfterAccountDeletion();
      await settle();

      expect(ended).toEqual(['deleted']);
      expect(adapter.calls.map((c) => c.route)).toEqual(['POST /auth/logout']);
      expect(nav.navigateRoot).not.toHaveBeenCalled();
    });
  });

  describe('watchSessionEnd (server-ended sessions)', () => {
    it('on a rejection: local teardown, root navigation and ONE toast', async () => {
      const { service, store, nav, toast, pending, adapter } = await setup();
      service.watchSessionEnd();
      service.watchSessionEnd(); // idempotent, never a second subscription

      await store.endSession('rejected', 'refresh-1');
      await settle();

      expect(nav.navigateRoot).toHaveBeenCalledOnceWith(['/home']);
      expect(toast.error).toHaveBeenCalledOnceWith('session_ended_sign_in_again', { position: 'top-center' });
      expect(pending.clear).toHaveBeenCalled();
      expect(adapter.calls.length).toBe(0); // nothing remote: the server already ended it
      expect(adapter.refreshedWith).toEqual([]);
    });

    it('does not react to an explicit logout', async () => {
      const { service, toast } = await setup();
      service.watchSessionEnd();
      await service.logout();
      await settle();
      expect(toast.error).not.toHaveBeenCalled();
    });

    it('a stale rejection (session already replaced) shows nothing', async () => {
      const { service, store, toast, nav } = await setup();
      service.watchSessionEnd();
      await store.endSession('rejected', 'some-older-refresh-token');
      await settle();
      expect(store.hasSession()).toBeTrue();
      expect(toast.error).not.toHaveBeenCalled();
      expect(nav.navigateRoot).not.toHaveBeenCalled();
    });
  });
});
