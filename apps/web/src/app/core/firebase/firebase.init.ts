import { initializeApp, type FirebaseApp } from 'firebase/app';
import { getAuth, type Auth } from 'firebase/auth';
import { environment } from '../../../environments/environment';

/**
 * firebase.init — lazy, guarded Firebase Auth bootstrap.
 *
 * Powers Google + Apple social sign-in (SocialAuthService). Firebase is
 * initialised ONLY when `environment.firebase.apiKey` is non-empty, so an
 * un-configured build (the default — apiKey '') never touches the firebase
 * SDK and never crashes the app. Consumers MUST treat a `null` Auth as
 * "social sign-in unavailable" and hide the buttons.
 *
 * Why lazy
 * --------
 * initializeApp() + getAuth() are only run on first call to getFirebaseAuth(),
 * not at module load. That keeps the firebase SDK out of the critical path for
 * the (common) anonymous-browsing case and avoids any work during the
 * APP_INITIALIZER hydrate. The result is memoised — initializeApp must run at
 * most once per page.
 *
 * SSR / non-browser safety
 * ------------------------
 * getAuth() touches browser globals. We guard on `typeof window` so a
 * prerender / SSR pass returns null instead of throwing.
 */

let cachedAuth: Auth | null = null;
let initialised = false;

/** True when a Firebase Web config is present (apiKey set). */
export function isFirebaseConfigured(): boolean {
  return environment.firebase.apiKey.length > 0;
}

/**
 * Get the singleton Firebase Auth instance, or null when social sign-in is
 * not available (no config, or non-browser environment). Memoised: the
 * underlying initializeApp() runs at most once.
 */
export function getFirebaseAuth(): Auth | null {
  if (initialised) return cachedAuth;
  initialised = true;

  if (!isFirebaseConfigured()) return null;
  if (typeof window === 'undefined') return null;

  try {
    const app: FirebaseApp = initializeApp({
      apiKey: environment.firebase.apiKey,
      authDomain: environment.firebase.authDomain,
      projectId: environment.firebase.projectId,
      appId: environment.firebase.appId,
      messagingSenderId: environment.firebase.messagingSenderId,
    });
    cachedAuth = getAuth(app);
  } catch (err) {
    /* A malformed config shouldn't take the whole app down — degrade to
       "social sign-in unavailable". */
    if (typeof console !== 'undefined') {
      console.warn('[firebase.init] initialisation failed; social sign-in disabled', err);
    }
    cachedAuth = null;
  }
  return cachedAuth;
}
