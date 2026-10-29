import { describe, it, expect, vi, type Mock } from 'vitest';
import { signal, type WritableSignal } from '@angular/core';
import type { SessionStatus } from './auth.service';
import { gateSignedInAction, SESSION_ACTION_WAIT_MS } from './session-gate';

/**
 * Tests for gateSignedInAction(), the page-level gate for signed-in-only
 * actions (wishlist heart, store follow).
 *
 * The rule under test: only a DEFINITIVELY signed-out visitor is sent to
 * /login. While the session is still 'unknown' after a transient
 * failure, the gate waits briefly for the background retry and, if the
 * server is still unreachable, reports a connection problem instead of
 * a sign-in prompt.
 */

function stubAuth(initial: SessionStatus): {
  auth: Parameters<typeof gateSignedInAction>[0];
  status: WritableSignal<SessionStatus>;
  waits: Array<number | undefined>;
  settle: (s: SessionStatus) => void;
} {
  const status = signal<SessionStatus>(initial);
  const waits: Array<number | undefined> = [];
  let resolveWait: ((s: SessionStatus) => void) | null = null;
  const auth = {
    isAuthenticated: () => status() === 'authenticated',
    sessionStatus: status.asReadonly(),
    whenSessionKnown: (timeoutMs?: number) => {
      waits.push(timeoutMs);
      return new Promise<SessionStatus>(resolve => {
        resolveWait = resolve;
      });
    },
  } as unknown as Parameters<typeof gateSignedInAction>[0];
  return {
    auth,
    status,
    waits,
    settle: (s: SessionStatus) => {
      status.set(s);
      resolveWait?.(s);
    },
  };
}

function handlers(): {
  signedIn: Mock<() => void>;
  signedOut: Mock<() => void>;
  unavailable: Mock<() => void>;
} {
  return {
    signedIn: vi.fn<() => void>(),
    signedOut: vi.fn<() => void>(),
    unavailable: vi.fn<() => void>(),
  };
}

describe('gateSignedInAction()', () => {
  it('runs the action synchronously for a signed-in customer', () => {
    const { auth, waits } = stubAuth('authenticated');
    const h = handlers();
    void gateSignedInAction(auth, h);
    expect(h.signedIn).toHaveBeenCalledTimes(1);
    expect(h.signedOut).not.toHaveBeenCalled();
    expect(waits).toEqual([]);
  });

  it('routes a definitively signed-out visitor to sign-in, synchronously', () => {
    const { auth, waits } = stubAuth('anonymous');
    const h = handlers();
    void gateSignedInAction(auth, h);
    expect(h.signedOut).toHaveBeenCalledTimes(1);
    expect(h.signedIn).not.toHaveBeenCalled();
    expect(waits).toEqual([]);
  });

  it("while 'unknown', waits for the retry and runs the action once the session is restored", async () => {
    const { auth, waits, settle } = stubAuth('unknown');
    const h = handlers();
    const done = gateSignedInAction(auth, h);

    expect(waits).toEqual([SESSION_ACTION_WAIT_MS]);
    expect(h.signedOut).not.toHaveBeenCalled();

    settle('authenticated');
    await done;
    expect(h.signedIn).toHaveBeenCalledTimes(1);
    expect(h.signedOut).not.toHaveBeenCalled();
  });

  it("while 'unknown', still sends to sign-in if the retry settles as anonymous", async () => {
    const { auth, settle } = stubAuth('unknown');
    const h = handlers();
    const done = gateSignedInAction(auth, h);
    settle('anonymous');
    await done;
    expect(h.signedOut).toHaveBeenCalledTimes(1);
  });

  it("while 'unknown' past the wait, reports a connection problem, NEVER a sign-in prompt", async () => {
    const { auth, settle } = stubAuth('unknown');
    const h = handlers();
    const done = gateSignedInAction(auth, h);
    settle('unknown');
    await done;
    expect(h.unavailable).toHaveBeenCalledTimes(1);
    expect(h.signedOut).not.toHaveBeenCalled();
    expect(h.signedIn).not.toHaveBeenCalled();
  });

  it('resolves once an async action has finished', async () => {
    const { auth } = stubAuth('authenticated');
    let finished = false;
    await gateSignedInAction(auth, {
      signedIn: async () => {
        await Promise.resolve();
        finished = true;
      },
      signedOut: () => undefined,
      unavailable: () => undefined,
    });
    expect(finished).toBe(true);
  });
});
