import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { PLATFORM_ID, TransferState, makeStateKey, StateKey } from '@angular/core';
import { AccessTokenStore, AccessTokenSnapshot } from './access-token-store';

/**
 * Tests for AccessTokenStore — the in-memory access-token cache that
 * bridges SSR and the browser via Angular's TransferState mechanism.
 *
 * The store has three platform-sensitive paths:
 *   - On the SERVER: set() writes both the signal AND TransferState
 *   - On the BROWSER, at construction: read TransferState, validate
 *     expiry, seed signal, remove key
 *   - On the BROWSER, after construction: set() writes only the signal
 *     (TransferState writes from the browser would never be consumed)
 *
 * Plus the universal:
 *   - clear() is idempotent on both platforms
 *   - hasValidToken() derives correctly from current + Date.now()
 *
 * The TransferState injection token's value is a real TransferState
 * instance — we let Angular's real implementation manage the
 * key-value-store contract rather than mocking it. The platform
 * choice is the only thing we override.
 */

const STATE_KEY: StateKey<AccessTokenSnapshot | null> = makeStateKey<AccessTokenSnapshot | null>('AUTH_ACCESS_TOKEN');

function makeSnapshot(secondsUntilExpiry = 600): AccessTokenSnapshot {
  return {
    token: 'fake.jwt.access',
    expiresAt: new Date(Date.now() + secondsUntilExpiry * 1000).toISOString(),
  };
}

/** Build a TestBed with a chosen platform. AccessTokenStore is created
 *  lazily on inject(), which is the moment the constructor's hydration
 *  logic fires. */
function configure(platform: 'browser' | 'server', preloadTransferState?: AccessTokenSnapshot | null): {
  store: AccessTokenStore;
  transferState: TransferState;
} {
  TestBed.configureTestingModule({
    providers: [
      { provide: PLATFORM_ID, useValue: platform },
      TransferState,
    ],
  });

  /* If the caller asked for a preloaded TransferState, seed it BEFORE
     AccessTokenStore is constructed — otherwise the constructor's read
     would see an empty store. */
  const transferState = TestBed.inject(TransferState);
  if (preloadTransferState !== undefined) {
    transferState.set(STATE_KEY, preloadTransferState);
  }

  const store = TestBed.inject(AccessTokenStore);
  return { store, transferState };
}

describe('AccessTokenStore', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  /* ===================================================================
     Initial state
     =================================================================== */
  describe('initial state', () => {
    it('starts empty on the server', () => {
      const { store } = configure('server');
      expect(store.current()).toBeNull();
      expect(store.hasValidToken()).toBe(false);
      expect(store.getToken()).toBeNull();
    });

    it('starts empty on the browser when TransferState has no key', () => {
      const { store } = configure('browser');
      expect(store.current()).toBeNull();
      expect(store.hasValidToken()).toBe(false);
      expect(store.getToken()).toBeNull();
    });
  });

  /* ===================================================================
     Server-side set()
     =================================================================== */
  describe('server-side set()', () => {
    it('updates the signal AND writes to TransferState', () => {
      const { store, transferState } = configure('server');
      const snap = makeSnapshot();

      store.set(snap);

      expect(store.current()).toEqual(snap);
      expect(transferState.get(STATE_KEY, null)).toEqual(snap);
    });

    it('overwrites a previous TransferState entry on re-set', () => {
      const { store, transferState } = configure('server');
      store.set(makeSnapshot(60));

      const replacement = makeSnapshot(900);
      replacement.token = 'second.jwt';
      store.set(replacement);

      expect(transferState.get(STATE_KEY, null)).toEqual(replacement);
      expect(store.getToken()).toBe('second.jwt');
    });
  });

  /* ===================================================================
     Browser hydration from TransferState
     =================================================================== */
  describe('browser hydration', () => {
    it('seeds signal from TransferState and removes the key', () => {
      const preload = makeSnapshot(600);
      const { store, transferState } = configure('browser', preload);

      expect(store.current()).toEqual(preload);
      /* Key should be REMOVED after hydration so a subsequent render
         pass (e.g. dev hot reload) doesn't see a stale value. */
      expect(transferState.hasKey(STATE_KEY)).toBe(false);
    });

    it('does NOT seed if TransferState entry is already expired', () => {
      /* A slow-arriving hydration where the token expired between SSR
         and client should not paint the user as logged-in. */
      const expired: AccessTokenSnapshot = {
        token: 'expired.jwt',
        expiresAt: new Date(Date.now() - 5000).toISOString(),
      };
      const { store, transferState } = configure('browser', expired);

      expect(store.current()).toBeNull();
      /* The key is STILL removed (cleanup), even though we didn't seed. */
      expect(transferState.hasKey(STATE_KEY)).toBe(false);
    });

    it('does not seed when TransferState entry is null', () => {
      const { store } = configure('browser', null);
      expect(store.current()).toBeNull();
    });
  });

  /* ===================================================================
     Browser-side set()
     =================================================================== */
  describe('browser-side set()', () => {
    it('updates the signal but does NOT write to TransferState', () => {
      const { store, transferState } = configure('browser');
      const snap = makeSnapshot();

      store.set(snap);

      expect(store.current()).toEqual(snap);
      /* Writing to TransferState from the browser would never be read
         by anyone — the SSR phase is done. The set() must be a no-op
         for TransferState in this direction. */
      expect(transferState.hasKey(STATE_KEY)).toBe(false);
    });
  });

  /* ===================================================================
     hasValidToken / getToken derivations
     =================================================================== */
  describe('hasValidToken computed', () => {
    it('returns true for a future-expiring snapshot', () => {
      const { store } = configure('browser');
      store.set(makeSnapshot(600));
      expect(store.hasValidToken()).toBe(true);
    });

    it('returns false for an already-expired snapshot', () => {
      const { store } = configure('browser');
      store.set({
        token: 'expired.jwt',
        expiresAt: new Date(Date.now() - 1000).toISOString(),
      });
      expect(store.hasValidToken()).toBe(false);
    });

    it('returns false after clear() even with prior valid token', () => {
      const { store } = configure('browser');
      store.set(makeSnapshot());
      store.clear();
      expect(store.hasValidToken()).toBe(false);
    });

    it('re-reads expiry on every set() — replacing with an expired snapshot flips to false', () => {
      /* hasValidToken is a `computed` that depends on the current
         signal value + Date.now() at the moment of evaluation. Angular's
         computed cache doesn't recompute on pure time changes — only
         on signal-graph changes — so the realistic transition path
         is: a refresh attempt sets a new (or expired) snapshot, which
         dirties the dependency and recomputes. That's what we test. */
      vi.useFakeTimers();
      try {
        vi.setSystemTime(new Date('2026-05-19T12:00:00Z'));
        const { store } = configure('browser');
        store.set({
          token: 'fresh',
          expiresAt: '2026-05-19T12:15:00Z', // 15 minutes ahead
        });
        expect(store.hasValidToken()).toBe(true);

        /* Simulate the world: time advances 16 minutes (token now stale),
           and the refresh path runs `set()` again with the OLD snapshot's
           expiry. The signal write dirties the computed; recomputation
           sees Date.now() > expiresAt → false. */
        vi.setSystemTime(new Date('2026-05-19T12:16:00Z'));
        store.set({
          token: 'fresh',
          expiresAt: '2026-05-19T12:15:00Z',
        });
        expect(store.hasValidToken()).toBe(false);
      } finally {
        vi.useRealTimers();
      }
    });
  });

  /* ===================================================================
     clear() idempotence
     =================================================================== */
  describe('clear()', () => {
    it('is idempotent — repeated calls remain a no-op', () => {
      const { store } = configure('browser');
      store.set(makeSnapshot());
      store.clear();
      store.clear();
      store.clear();
      expect(store.current()).toBeNull();
    });

    it('removes the TransferState key on the server', () => {
      const { store, transferState } = configure('server');
      store.set(makeSnapshot());
      expect(transferState.hasKey(STATE_KEY)).toBe(true);

      store.clear();
      expect(transferState.hasKey(STATE_KEY)).toBe(false);
    });
  });

  /* ===================================================================
     getToken
     =================================================================== */
  describe('getToken()', () => {
    it('returns the raw token string when set', () => {
      const { store } = configure('browser');
      store.set({ token: 'specific.token', expiresAt: makeSnapshot().expiresAt });
      expect(store.getToken()).toBe('specific.token');
    });

    it('returns null when cleared', () => {
      const { store } = configure('browser');
      store.set(makeSnapshot());
      store.clear();
      expect(store.getToken()).toBeNull();
    });
  });
});
