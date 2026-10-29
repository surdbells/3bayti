import { TestBed } from '@angular/core/testing';

import {
  SESSION_PERSISTENCE,
  SessionStore,
  type SessionEndReason,
  type SessionPersistence,
} from './session-store.service';

/**
 * In-memory stand-in for Capacitor Preferences('user'). Records every write
 * so specs can assert ordering; `writeDelays` lets a spec make individual
 * writes resolve out of call order.
 */
class MemoryPersistence implements SessionPersistence {
  value: string | null = null;
  writes: Array<string | null> = [];
  writeDelays: number[] = [];
  /** Writes/removes STARTED (synchronously counted, before they land). */
  dispatched = 0;
  /** The next N reads reject (Preferences unreadable), then reads work again. */
  failReads = 0;
  reads = 0;

  read(): Promise<string | null> {
    this.reads++;
    if (this.failReads > 0) {
      this.failReads--;
      return Promise.reject(new Error('storage busy'));
    }
    return Promise.resolve(this.value);
  }

  write(value: string): Promise<void> {
    return this.delayed(() => {
      this.value = value;
      this.writes.push(value);
    });
  }

  remove(): Promise<void> {
    return this.delayed(() => {
      this.value = null;
      this.writes.push(null);
    });
  }

  stored(): Record<string, unknown> | null {
    return this.value === null ? null : JSON.parse(this.value);
  }

  private delayed(apply: () => void): Promise<void> {
    this.dispatched++;
    const ms = this.writeDelays.shift() ?? 0;
    return new Promise((resolve) => setTimeout(() => {
      apply();
      resolve();
    }, ms));
  }
}

const SIGNED_IN = {
  id: 42,
  token: 'access-1',
  refresh_token: 'refresh-1',
  first_name: 'Mona',
  avatar: 'https://cdn/a.png',
};

function setup(
  initial: unknown = SIGNED_IN,
  failReads = 0,
): { store: SessionStore; persistence: MemoryPersistence; ended: SessionEndReason[] } {
  const persistence = new MemoryPersistence();
  persistence.value = initial === null ? null : typeof initial === 'string' ? initial : JSON.stringify(initial);
  persistence.failReads = failReads;
  TestBed.configureTestingModule({
    providers: [{ provide: SESSION_PERSISTENCE, useValue: persistence }],
  });
  const store = TestBed.inject(SessionStore);
  const ended: SessionEndReason[] = [];
  store.sessionEnded$.subscribe((r) => ended.push(r));
  return { store, persistence, ended };
}

describe('SessionStore', () => {
  describe('load', () => {
    it('reads the stored blob into memory', async () => {
      const { store } = setup();
      await store.ready();
      expect(store.hasSession()).toBeTrue();
      expect(store.currentAccessToken()).toBe('access-1');
      expect(store.currentRefreshToken()).toBe('refresh-1');
      expect(await store.get()).toEqual(SIGNED_IN);
    });

    it('starts signed out when nothing is stored', async () => {
      const { store } = setup(null);
      expect(await store.get()).toBeNull();
      expect(store.currentAccessToken()).toBe('');
    });

    it('starts signed out (no crash) on a corrupt blob', async () => {
      const { store } = setup('{not json');
      expect(await store.get()).toBeNull();
      expect(store.isSignedOut()).toBeTrue();
    });

    it('isSignedOut is true only once loaded and empty', async () => {
      const signedIn = setup().store;
      await signedIn.ready();
      expect(signedIn.isSignedOut()).toBeFalse();
      TestBed.resetTestingModule();
      const guest = setup(null).store;
      expect(guest.isSignedOut()).toBeFalse(); // not read yet: unknown
      await guest.ready();
      expect(guest.isSignedOut()).toBeTrue();
    });

    it('retries a failed Preferences READ before settling', async () => {
      const { store, persistence } = setup(SIGNED_IN, 2);
      await store.ready();
      expect(persistence.reads).toBe(3);
      expect(store.isLoaded()).toBeTrue();
      expect(store.currentAccessToken()).toBe('access-1');
    });

    it('an unreadable store is UNKNOWN, not signed out, and the next call reads again', async () => {
      const { store, persistence, ended } = setup(SIGNED_IN, 3); // every read of the first load fails
      await store.ready();
      expect(store.isLoaded()).toBeFalse();
      expect(store.hasSession()).toBeFalse();
      expect(store.isSignedOut()).toBeFalse(); // never prompt sign-in on a glitch
      expect(persistence.value).not.toBeNull(); // nothing removed
      expect(ended).toEqual([]);

      // Storage is back: the next caller loads the real session.
      expect(await store.get()).toEqual(SIGNED_IN);
      expect(store.isLoaded()).toBeTrue();
      expect(store.currentRefreshToken()).toBe('refresh-1');
    });

    it('page write-backs do nothing while the store is unreadable (no merge on unknown state)', async () => {
      const { store, persistence } = setup(SIGNED_IN, 6); // two whole loads fail
      await store.ready();
      expect(await store.saveProfile({ ...SIGNED_IN, token: 'stale', first_name: 'X' })).toBeFalse();
      expect(persistence.writes.length).toBe(0);
      expect(persistence.stored()).toEqual(SIGNED_IN);
    });

    it('a re-read still in flight never overwrites a sign-in that landed meanwhile', async () => {
      const { store } = setup(SIGNED_IN, 3); // the first load gives up
      // Registered first, so it runs first once that load settles: it starts
      // a second read (which returns the OLD blob)...
      void store.ready().then(() => store.ready());
      // ...and this sign-in, waiting on the same first load, lands before
      // that read returns.
      await store.signIn({ id: 8, token: 'new', refresh_token: 'new-r' });
      await store.ready();
      await new Promise((r) => setTimeout(r, 5));
      expect(store.currentAccessToken()).toBe('new');
      expect(store.currentRefreshToken()).toBe('new-r');
    });

    it('signIn and explicit logout still work while the store is unreadable', async () => {
      const a = setup(SIGNED_IN, 100);
      await a.store.signIn({ id: 8, token: 'new', refresh_token: 'new-r' });
      expect(a.store.isLoaded()).toBeTrue();
      expect(a.persistence.stored()).toEqual({ id: 8, token: 'new', refresh_token: 'new-r' });

      TestBed.resetTestingModule();
      const b = setup(SIGNED_IN, 100);
      await b.store.endSession('logout');
      expect(b.persistence.value).toBeNull();
      expect(b.store.isSignedOut()).toBeTrue();
    });
  });

  describe('signIn', () => {
    it('replaces the session in memory and persists it', async () => {
      const { store, persistence } = setup(null);
      await store.signIn({ id: 7, token: 'a', refresh_token: 'r' });
      expect(store.currentAccessToken()).toBe('a');
      expect(persistence.stored()).toEqual({ id: 7, token: 'a', refresh_token: 'r' });
    });

    it('updates memory synchronously once loaded (callers can use the token right away)', async () => {
      const { store } = setup(null);
      await store.ready();
      void store.signIn({ id: 7, token: 'fresh', refresh_token: 'r' });
      expect(store.currentAccessToken()).toBe('fresh');
    });
  });

  describe('saveProfile', () => {
    it('writes profile fields but ALWAYS keeps the stored token and refresh_token', async () => {
      const { store, persistence } = setup();
      // A page copy taken before a rotation: stale tokens + an edit.
      const ok = await store.saveProfile({
        ...SIGNED_IN,
        token: 'stale-access',
        refresh_token: 'stale-refresh',
        first_name: 'Mona Edited',
        location: 'Dubai Marina',
      });
      expect(ok).toBeTrue();
      const stored = persistence.stored()!;
      expect(stored['token']).toBe('access-1');
      expect(stored['refresh_token']).toBe('refresh-1');
      expect(stored['first_name']).toBe('Mona Edited');
      expect(stored['location']).toBe('Dubai Marina');
    });

    it('keeps tokens rotated after the page read its copy', async () => {
      const { store, persistence } = setup();
      const pageCopy = (await store.get())!;
      await store.replaceTokens('refresh-1', { access_token: 'access-2', refresh_token: 'refresh-2' });
      await store.saveProfile({ ...pageCopy, avatar: 'https://cdn/b.png' });
      const stored = persistence.stored()!;
      expect(stored['token']).toBe('access-2');
      expect(stored['refresh_token']).toBe('refresh-2');
      expect(stored['avatar']).toBe('https://cdn/b.png');
    });

    it('ignores a copy that belongs to another user id', async () => {
      const { store, persistence } = setup();
      const ok = await store.saveProfile({ ...SIGNED_IN, id: 99, first_name: 'Other' });
      expect(ok).toBeFalse();
      expect(persistence.stored()!['first_name']).toBe('Mona');
      expect(persistence.writes.length).toBe(0);
    });

    it('is a no-op after endSession (a stale page can never resurrect the session)', async () => {
      const { store, persistence } = setup();
      await store.endSession('logout');
      const ok = await store.saveProfile({ ...SIGNED_IN, first_name: 'Ghost' });
      expect(ok).toBeFalse();
      expect(store.hasSession()).toBeFalse();
      expect(persistence.value).toBeNull();
    });

    it('does not add a refresh_token the stored blob never had', async () => {
      const { store, persistence } = setup({ id: 5, token: 'legacy' });
      await store.saveProfile({ id: 5, token: 'x', refresh_token: 'page-only', first_name: 'A' });
      const stored = persistence.stored()!;
      expect(stored['token']).toBe('legacy');
      expect('refresh_token' in stored).toBeFalse();
    });
  });

  describe('patchProfile', () => {
    it('merges fields but never touches tokens or the id', async () => {
      const { store, persistence } = setup();
      await store.patchProfile({ is_phone_verified: true, phone: '+971500000000', token: 'evil', id: 1 });
      const stored = persistence.stored()!;
      expect(stored['is_phone_verified']).toBeTrue();
      expect(stored['phone']).toBe('+971500000000');
      expect(stored['token']).toBe('access-1');
      expect(stored['id']).toBe(42);
    });

    it('is a no-op when signed out', async () => {
      const { store, persistence } = setup(null);
      expect(await store.patchProfile({ phone: '1' })).toBeFalse();
      expect(persistence.writes.length).toBe(0);
    });
  });

  describe('replaceTokens (compare-and-set)', () => {
    it('stores the new pair when the used refresh token is still current', async () => {
      const { store, persistence } = setup();
      const ok = await store.replaceTokens('refresh-1', { access_token: 'access-2', refresh_token: 'refresh-2' });
      expect(ok).toBeTrue();
      expect(store.currentAccessToken()).toBe('access-2');
      expect(persistence.stored()).toEqual({ ...SIGNED_IN, token: 'access-2', refresh_token: 'refresh-2' });
    });

    it('refuses when a newer login replaced the session (late refresh)', async () => {
      const { store } = setup();
      await store.signIn({ id: 8, token: 'new-login', refresh_token: 'new-refresh' });
      const ok = await store.replaceTokens('refresh-1', { access_token: 'late', refresh_token: 'late-r' });
      expect(ok).toBeFalse();
      expect(store.currentAccessToken()).toBe('new-login');
      expect(store.currentRefreshToken()).toBe('new-refresh');
    });

    it('refuses after logout (never resurrects a logged-out session)', async () => {
      const { store, persistence } = setup();
      await store.endSession('logout');
      const ok = await store.replaceTokens('refresh-1', { access_token: 'late', refresh_token: 'late-r' });
      expect(ok).toBeFalse();
      expect(store.hasSession()).toBeFalse();
      expect(persistence.value).toBeNull();
    });

    it('updates identity/profile fields from the server user (claim merge can change the id)', async () => {
      const { store } = setup();
      await store.replaceTokens('refresh-1', { access_token: 'a2', refresh_token: 'r2' }, {
        id: 77,
        first_name: 'Merged',
        roles: ['customer', 'vendor'],
        is_store_active: true,
      });
      const blob = (await store.get())!;
      expect(blob['id']).toBe(77);
      expect(blob['first_name']).toBe('Merged');
      expect(blob['is_vendor']).toBeTrue();
      expect(blob['is_store_active']).toBeTrue();
      // Absent from the server user -> kept, never blanked.
      expect(blob['avatar']).toBe('https://cdn/a.png');
    });

    it('keeps the used refresh token when the response omits a new one', async () => {
      const { store } = setup();
      await store.replaceTokens('refresh-1', { access_token: 'a2' });
      expect(store.currentRefreshToken()).toBe('refresh-1');
      expect(store.currentAccessToken()).toBe('a2');
    });
  });

  describe('endSession', () => {
    it('with a STALE used refresh token does nothing', async () => {
      const { store, persistence, ended } = setup();
      const ok = await store.endSession('rejected', 'refresh-0-old');
      expect(ok).toBeFalse();
      expect(store.hasSession()).toBeTrue();
      expect(persistence.stored()).toEqual(SIGNED_IN);
      expect(ended).toEqual([]);
    });

    it('with the current used refresh token removes the session and emits the reason', async () => {
      const { store, persistence, ended } = setup();
      const ok = await store.endSession('rejected', 'refresh-1');
      expect(ok).toBeTrue();
      expect(store.hasSession()).toBeFalse();
      expect(persistence.value).toBeNull();
      expect(ended).toEqual(['rejected']);
    });

    it("'' matches a blob without a refresh token", async () => {
      const { store, ended } = setup({ id: 5, token: 'legacy' });
      expect(await store.endSession('rejected', '')).toBeTrue();
      expect(ended).toEqual(['rejected']);
    });

    it('without a used token (explicit logout) removes unconditionally', async () => {
      const { store, persistence, ended } = setup();
      expect(await store.endSession('logout')).toBeTrue();
      expect(persistence.value).toBeNull();
      expect(ended).toEqual(['logout']);
    });

    it('emits nothing when there was no session', async () => {
      const { store, ended } = setup(null);
      expect(await store.endSession('logout')).toBeFalse();
      expect(await store.endSession('rejected', 'x')).toBeFalse();
      expect(ended).toEqual([]);
    });
  });

  describe('write queue', () => {
    it('lands writes in operation order even when an earlier write is slower', async () => {
      const { store, persistence } = setup();
      await store.ready();
      persistence.writeDelays = [30, 0, 0];
      const a = store.saveProfile({ ...SIGNED_IN, first_name: 'One' });
      const b = store.replaceTokens('refresh-1', { access_token: 'access-2', refresh_token: 'refresh-2' });
      const c = store.saveProfile({ ...SIGNED_IN, first_name: 'Three' });
      await Promise.all([a, b, c]);
      expect(persistence.writes.length).toBe(3);
      expect(JSON.parse(persistence.writes[0]!)['first_name']).toBe('One');
      expect(JSON.parse(persistence.writes[1]!)['token']).toBe('access-2');
      // Last write = final in-memory state: the edit AND the rotated tokens.
      expect(persistence.stored()).toEqual({
        ...SIGNED_IN,
        first_name: 'Three',
        token: 'access-2',
        refresh_token: 'refresh-2',
      });
    });

    it('dispatches the write in the same tick when nothing is pending (readers right after see it)', async () => {
      const { store, persistence } = setup(null);
      await store.ready();
      void store.signIn({ id: 1, token: 't', refresh_token: 'r' });
      expect(persistence.dispatched).toBe(1);
      // A second write while the first is in flight waits its turn.
      void store.patchProfile({ first_name: 'B' });
      expect(persistence.dispatched).toBe(1);
      await store.get();
      await new Promise((r) => setTimeout(r, 5));
      expect(persistence.dispatched).toBe(2);
      expect(persistence.stored()!['first_name']).toBe('B');
    });

    it('applies operations issued before the initial load in call order', async () => {
      const { store, persistence } = setup();
      // Not awaited: both run after load, first-come first-served.
      const a = store.replaceTokens('refresh-1', { access_token: 'access-2', refresh_token: 'refresh-2' });
      const b = store.replaceTokens('refresh-1', { access_token: 'dup', refresh_token: 'dup-r' });
      expect(await a).toBeTrue();
      expect(await b).toBeFalse();
      expect(persistence.stored()!['token']).toBe('access-2');
    });
  });
});
