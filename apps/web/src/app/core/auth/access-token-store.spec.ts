import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { AccessTokenStore, AccessTokenSnapshot } from './access-token-store';

/**
 * Tests for AccessTokenStore — the in-memory access-token cache.
 *
 * The store holds the current access-token snapshot in a signal. The
 * token is seeded on the client by AuthService.hydrate(); the store
 * itself never persists to localStorage / cookies and has no
 * platform-specific behaviour.
 */

function makeSnapshot(secondsUntilExpiry = 600): AccessTokenSnapshot {
  return {
    token: 'fake.jwt.access',
    expiresAt: new Date(Date.now() + secondsUntilExpiry * 1000).toISOString(),
  };
}

function makeStore(): AccessTokenStore {
  TestBed.configureTestingModule({ providers: [AccessTokenStore] });
  return TestBed.inject(AccessTokenStore);
}

describe('AccessTokenStore', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  describe('initial state', () => {
    it('starts empty', () => {
      const store = makeStore();
      expect(store.current()).toBeNull();
      expect(store.hasValidToken()).toBe(false);
      expect(store.getToken()).toBeNull();
    });
  });

  describe('set()', () => {
    it('updates the current snapshot', () => {
      const store = makeStore();
      const snap = makeSnapshot();
      store.set(snap);
      expect(store.current()).toEqual(snap);
    });

    it('overwrites a previous entry on re-set', () => {
      const store = makeStore();
      store.set(makeSnapshot(60));

      const replacement = makeSnapshot(900);
      replacement.token = 'second.jwt';
      store.set(replacement);

      expect(store.current()).toEqual(replacement);
      expect(store.getToken()).toBe('second.jwt');
    });
  });

  describe('hasValidToken computed', () => {
    it('returns true for a future-expiring snapshot', () => {
      const store = makeStore();
      store.set(makeSnapshot(600));
      expect(store.hasValidToken()).toBe(true);
    });

    it('returns false for an already-expired snapshot', () => {
      const store = makeStore();
      store.set({
        token: 'expired.jwt',
        expiresAt: new Date(Date.now() - 1000).toISOString(),
      });
      expect(store.hasValidToken()).toBe(false);
    });

    it('returns false after clear() even with a prior valid token', () => {
      const store = makeStore();
      store.set(makeSnapshot());
      store.clear();
      expect(store.hasValidToken()).toBe(false);
    });

    it('re-reads expiry on every set() — replacing with an expired snapshot flips to false', () => {
      /* hasValidToken is a `computed` that depends on the current
         signal value + Date.now() at evaluation. Angular's computed
         cache doesn't recompute on pure time changes — only on
         signal-graph changes — so the realistic transition is: a
         refresh sets a new (or expired) snapshot, dirtying the
         dependency and recomputing. That's what we test. */
      vi.useFakeTimers();
      try {
        vi.setSystemTime(new Date('2026-05-19T12:00:00Z'));
        const store = makeStore();
        store.set({ token: 'fresh', expiresAt: '2026-05-19T12:15:00Z' });
        expect(store.hasValidToken()).toBe(true);

        vi.setSystemTime(new Date('2026-05-19T12:16:00Z'));
        store.set({ token: 'fresh', expiresAt: '2026-05-19T12:15:00Z' });
        expect(store.hasValidToken()).toBe(false);
      } finally {
        vi.useRealTimers();
      }
    });
  });

  describe('clear()', () => {
    it('is idempotent — repeated calls remain a no-op', () => {
      const store = makeStore();
      store.set(makeSnapshot());
      store.clear();
      store.clear();
      store.clear();
      expect(store.current()).toBeNull();
    });
  });

  describe('getToken()', () => {
    it('returns the raw token string when set', () => {
      const store = makeStore();
      store.set({ token: 'specific.token', expiresAt: makeSnapshot().expiresAt });
      expect(store.getToken()).toBe('specific.token');
    });

    it('returns null when cleared', () => {
      const store = makeStore();
      store.set(makeSnapshot());
      store.clear();
      expect(store.getToken()).toBeNull();
    });
  });
});
