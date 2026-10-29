import type { AuthService } from './auth.service';

/**
 * How long a signed-in-only page action (wishlist heart, store follow)
 * waits for a still-'unknown' session before giving up with a
 * connection error.
 */
export const SESSION_ACTION_WAIT_MS = 5_000;

/**
 * What to do for each resolution of the session. A handler may return a
 * promise; gateSignedInAction() settles once it does.
 */
export interface SignedInActionHandlers {
  /** A session exists: run the action. */
  signedIn: () => unknown;
  /** Definitively signed out: route to /login. */
  signedOut: () => unknown;
  /**
   * The session is still unresolved after a transient failure (the
   * first-load hydrate couldn't reach the server). The customer may well
   * be signed in, so this must show a connection error, NEVER a sign-in
   * prompt.
   */
  unavailable: () => unknown;
}

/**
 * Gate a page action that needs a signed-in customer.
 *
 * `isAuthenticated()` is false both for a signed-out visitor AND while
 * the session is 'unknown' (a transient first-load failure being
 * retried). Treating the second case as signed out would send a
 * signed-in customer to /login during an API blip, so this tells the two
 * apart:
 *   - signed in   → handlers.signedIn()   (called synchronously)
 *   - 'anonymous' → handlers.signedOut()  (called synchronously)
 *   - 'unknown'   → waits up to SESSION_ACTION_WAIT_MS for a retry, then
 *     signedIn / signedOut, or unavailable if it is still unknown.
 *
 * Never rejects on its own; resolves once the chosen handler is done.
 */
export function gateSignedInAction(
  auth: Pick<AuthService, 'isAuthenticated' | 'sessionStatus' | 'whenSessionKnown'>,
  handlers: SignedInActionHandlers,
): Promise<void> {
  if (auth.isAuthenticated()) {
    return settled(handlers.signedIn());
  }
  if (auth.sessionStatus() !== 'unknown') {
    return settled(handlers.signedOut());
  }
  return auth.whenSessionKnown(SESSION_ACTION_WAIT_MS).then(status => {
    if (auth.isAuthenticated()) return settled(handlers.signedIn());
    if (status === 'unknown') return settled(handlers.unavailable());
    return settled(handlers.signedOut());
  });
}

function settled(result: unknown): Promise<void> {
  return Promise.resolve(result).then(() => undefined);
}
