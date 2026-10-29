import { Injectable, InjectionToken, inject } from '@angular/core';
import { Preferences } from '@capacitor/preferences';
import { Observable, Subject } from 'rxjs';

import { mapV3UserFields } from '../../public/login/login-response.transform';

/** Capacitor Preferences key holding the signed-in session blob. */
export const SESSION_STORAGE_KEY = 'user';

/**
 * The session blob stored under Preferences('user'). Shape is the legacy
 * `single_user` built by transformV3LoginResponse: the access JWT lives in
 * `token`, the rotating refresh token in `refresh_token`, plus profile fields
 * pages bind directly (first_name, avatar, billing_*, ...).
 */
export interface SessionBlob {
  id?: number;
  token?: string;
  refresh_token?: string;
  [field: string]: unknown;
}

/**
 * Why a session ended:
 *   - logout   , the customer tapped "Log out"
 *   - deleted  , the customer deleted their account
 *   - rejected , the server said the session is over (401
 *                AUTH_REFRESH_TOKEN_INVALID on refresh, or a blob with no
 *                refresh token hit a token 401)
 */
export type SessionEndReason = 'logout' | 'deleted' | 'rejected';

/** Token pair from POST /v3/auth/refresh (or any login-shaped response). */
export interface SessionTokenPair {
  access_token: string;
  refresh_token?: string;
}

/** Durable backing store for the blob. Swappable so specs can use memory. */
export interface SessionPersistence {
  read(): Promise<string | null>;
  write(value: string): Promise<void>;
  remove(): Promise<void>;
}

export const SESSION_PERSISTENCE = new InjectionToken<SessionPersistence>('SESSION_PERSISTENCE', {
  providedIn: 'root',
  factory: () => ({
    read: async () => (await Preferences.get({ key: SESSION_STORAGE_KEY })).value ?? null,
    write: (value: string) => Preferences.set({ key: SESSION_STORAGE_KEY, value }),
    remove: () => Preferences.remove({ key: SESSION_STORAGE_KEY }),
  }),
});

/** Fields a page write-back may never overwrite: the session itself. */
const TOKEN_FIELDS = ['token', 'refresh_token'] as const;

/**
 * Back-off before each extra attempt when Preferences can't be READ (so up
 * to 3 reads per load). A failed read is not "signed out", see load().
 */
const READ_RETRY_DELAYS_MS: readonly number[] = [100, 300];

/**
 * SessionStore, the ONLY code that writes or removes Preferences('user').
 *
 * Why this exists
 * ===============
 * Pages used to JSON.parse the blob in ngOnInit, keep the copy for the
 * page's lifetime, and write the WHOLE copy back after a profile edit. When
 * the adapter had rotated the refresh token in the meantime, that write-back
 * restored an already-rotated refresh token (and a late refresh could even
 * resurrect a logged-out session). Every write now goes through here:
 *
 *   signIn(blob)                 , login / register / social / claim swap
 *   saveProfile(updated)         , page write-backs; tokens always kept
 *   patchProfile(fields)         , atomic field updates (phone verified, ...)
 *   replaceTokens(used, pair, u) , adapter after a refresh, compare-and-set
 *   endSession(reason, used?)    , logout / deletion / server rejection
 *
 * Consistency model
 * =================
 * The in-memory copy is the source of truth once loaded. Every operation
 * reads and writes it synchronously (no await between the read and the
 * write), so read-modify-write operations can never interleave. Persistence
 * is a serialized promise chain, so the writes land in Preferences in the
 * same order the operations happened. Operations issued before the initial
 * load finishes wait for it, in call order.
 *
 * Unknown is not signed out
 * =========================
 * If Preferences can't be read at all (not the same as an empty or corrupt
 * blob), the store stays NOT loaded and the next ready()/get()/write tries
 * again. Meanwhile hasSession() is false but isSignedOut() is false too:
 * sign-in prompts must check isSignedOut(), so a storage glitch never asks
 * a signed-in customer to sign in.
 *
 * Readers may keep using Preferences.get('user') directly; the adapter
 * always swaps in the current token from here, so a stale copy is harmless.
 */
@Injectable({ providedIn: 'root' })
export class SessionStore {
  private readonly persistence = inject(SESSION_PERSISTENCE);

  private blob: SessionBlob | null = null;
  private loaded = false;
  /** The load in progress; null once it settled (a failed read retries on demand). */
  private loading: Promise<void> | null = null;
  private writeChain: Promise<void> = Promise.resolve();
  private pendingWrites = 0;
  private readonly ended = new Subject<SessionEndReason>();

  /** Emits the reason each time a stored session is actually removed. */
  readonly sessionEnded$: Observable<SessionEndReason> = this.ended.asObservable();

  constructor() {
    void this.ensureLoaded();
  }

  /**
   * Resolves once a load attempt settled. Normally the blob is then in
   * memory; if Preferences could not be read, isLoaded() stays false and
   * the next call tries again.
   */
  ready(): Promise<void> {
    return this.ensureLoaded();
  }

  isLoaded(): boolean {
    return this.loaded;
  }

  /** Current blob (a copy) or null when signed out (or still unreadable). */
  async get(): Promise<SessionBlob | null> {
    if (!this.loaded) await this.ensureLoaded();
    return this.snapshot();
  }

  /** Synchronous copy of the current blob; null when signed out or not loaded yet. */
  snapshot(): SessionBlob | null {
    return this.blob ? { ...this.blob } : null;
  }

  /** True while a session blob is stored. */
  hasSession(): boolean {
    return this.blob !== null;
  }

  /**
   * True only when the store KNOWS there is no session (loaded and empty).
   * Use this, not !hasSession(), before showing a sign-in prompt: while the
   * blob is still unreadable neither is true.
   */
  isSignedOut(): boolean {
    return this.loaded && this.blob === null;
  }

  /** The live access token ('' when signed out). */
  currentAccessToken(): string {
    return stringField(this.blob, 'token');
  }

  /** The live refresh token ('' when signed out or the blob has none). */
  currentRefreshToken(): string {
    return stringField(this.blob, 'refresh_token');
  }

  /** Start a session from a login-shaped blob, replacing any previous one. */
  async signIn(blob: SessionBlob): Promise<void> {
    if (!this.loaded) await this.ensureLoaded();
    if (!isPlainObject(blob)) return;
    this.blob = { ...blob };
    // Replaces whatever was stored, so the state is known even if the
    // previous blob could never be read.
    this.loaded = true;
    await this.persist();
  }

  /**
   * Persist a page's updated profile copy. No-op when signed out or when the
   * copy belongs to a different user (a stale page from another account or
   * from before a logout). Profile fields are merged over the stored blob;
   * the stored token + refresh_token always win over the page's copies.
   */
  async saveProfile(updated: SessionBlob): Promise<boolean> {
    if (!this.loaded) await this.ensureLoaded();
    const current = this.blob;
    if (current === null || !isPlainObject(updated) || !sameUserId(current['id'], updated['id'])) {
      return false;
    }
    const next: SessionBlob = { ...current, ...updated };
    keepTokens(next, current);
    this.blob = next;
    await this.persist();
    return true;
  }

  /**
   * Merge a few profile fields into the stored blob (e.g. phone verified).
   * Token fields and the user id are ignored. No-op when signed out.
   */
  async patchProfile(fields: Record<string, unknown>): Promise<boolean> {
    if (!this.loaded) await this.ensureLoaded();
    const current = this.blob;
    if (current === null || !isPlainObject(fields)) return false;
    const next: SessionBlob = { ...current };
    for (const [key, value] of Object.entries(fields)) {
      if (key === 'id' || (TOKEN_FIELDS as readonly string[]).includes(key)) continue;
      next[key] = value;
    }
    this.blob = next;
    await this.persist();
    return true;
  }

  /**
   * Store a refreshed token pair. Compare-and-set: applies only while the
   * stored refresh_token is still `usedRefreshToken`, so a late refresh can
   * never overwrite a newer login or resurrect a logged-out session. When the
   * refresh response carries `user`, identity/profile fields are updated with
   * the login transform's mapping (a phone-claim merge can change the id).
   */
  async replaceTokens(usedRefreshToken: string, pair: SessionTokenPair, serverUser?: unknown): Promise<boolean> {
    if (!this.loaded) await this.ensureLoaded();
    const current = this.blob;
    if (current === null || stringField(current, 'refresh_token') !== usedRefreshToken) return false;
    if (typeof pair?.access_token !== 'string' || pair.access_token.length === 0) return false;

    const next: SessionBlob = { ...current };
    if (isPlainObject(serverUser)) {
      Object.assign(next, mapV3UserFields(serverUser));
    }
    next.token = pair.access_token;
    if (typeof pair.refresh_token === 'string' && pair.refresh_token.length > 0) {
      next.refresh_token = pair.refresh_token;
    }
    this.blob = next;
    await this.persist();
    return true;
  }

  /**
   * End the stored session and emit sessionEnded$.
   *
   * With `usedRefreshToken` (server rejection): removes only while the stored
   * refresh_token still equals it ('' matches a blob without one), so a
   * rejection of an old session never signs out a newer login. Without it
   * (explicit logout / account deletion): removes unconditionally, even a
   * blob that could not be read. Resolves true when a session was removed.
   */
  async endSession(reason: SessionEndReason, usedRefreshToken?: string): Promise<boolean> {
    if (!this.loaded) await this.ensureLoaded();
    const current = this.blob;
    if (usedRefreshToken !== undefined
      && (current === null || stringField(current, 'refresh_token') !== usedRefreshToken)) {
      return false;
    }
    this.blob = null;
    this.loaded = true;
    const written = this.persist();
    if (current !== null) {
      this.ended.next(reason);
    }
    await written;
    return current !== null;
  }

  /* ------ Internals ----------------------------------------------- */

  /** Single-flight load; starts a new attempt when the last one could not read. */
  private ensureLoaded(): Promise<void> {
    if (this.loaded) return Promise.resolve();
    if (this.loading === null) {
      const run: Promise<void> = this.load().finally(() => {
        if (this.loading === run) {
          this.loading = null;
        }
      });
      this.loading = run;
    }
    return this.loading;
  }

  /**
   * Read the blob into memory. Two failures, handled differently:
   *   - the blob is corrupt (not JSON / not an object): start signed out;
   *   - Preferences itself can't be read (after READ_RETRY_DELAYS_MS):
   *     stay NOT loaded so the next call retries. Treating it as signed
   *     out would show a sign-in prompt to a signed-in customer.
   * Never rejects.
   */
  private async load(): Promise<void> {
    let raw: string | null;
    try {
      raw = await this.readWithRetry();
    } catch (e) {
      console.warn('[session] reading the stored session failed', e);
      return;
    }
    if (this.loaded) {
      // A signIn / logout settled the state while this (retry) read was in
      // flight: what it read may predate that write, so drop it.
      return;
    }
    try {
      const parsed = raw ? JSON.parse(raw) : null;
      this.blob = isPlainObject(parsed) ? (parsed as SessionBlob) : null;
    } catch {
      // Corrupt blob: start signed out rather than crash.
      this.blob = null;
    }
    this.loaded = true;
  }

  private async readWithRetry(): Promise<string | null> {
    for (let attempt = 0; ; attempt++) {
      try {
        return await this.persistence.read();
      } catch (e) {
        if (attempt >= READ_RETRY_DELAYS_MS.length) throw e;
        await new Promise<void>((resolve) => setTimeout(resolve, READ_RETRY_DELAYS_MS[attempt]));
      }
    }
  }

  /**
   * Queue a write of the CURRENT in-memory state. Snapshot taken now, so
   * the chain persists each state in order. When nothing is pending the
   * write is dispatched immediately (same tick as the in-memory change), so
   * a Preferences.get('user') issued right after, e.g. by the page a login
   * navigates to, sees it, exactly like the direct Preferences.set calls
   * this replaces. Failures are logged and never reject: the in-memory
   * session stays authoritative for this run.
   */
  private persist(): Promise<void> {
    const value = this.blob === null ? null : JSON.stringify(this.blob);
    const write = (): Promise<void> =>
      value === null ? this.persistence.remove() : this.persistence.write(value);
    const run = this.pendingWrites === 0 ? invoke(write) : this.writeChain.then(write);
    this.pendingWrites++;
    this.writeChain = run.then(
      () => {
        this.pendingWrites--;
      },
      (e) => {
        this.pendingWrites--;
        console.warn('[session] persisting the session failed', e);
      },
    );
    return this.writeChain;
  }
}

/** Call `fn` now, turning a synchronous throw into a rejection. */
function invoke(fn: () => Promise<void>): Promise<void> {
  try {
    return Promise.resolve(fn());
  } catch (e) {
    return Promise.reject(e);
  }
}

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function stringField(blob: SessionBlob | null, key: string): string {
  const v = blob?.[key];
  return typeof v === 'string' ? v : '';
}

/** Ids compare loosely (number vs numeric string); a missing/0 id never matches. */
function sameUserId(a: unknown, b: unknown): boolean {
  const x = Number(a);
  const y = Number(b);
  return Number.isFinite(x) && x !== 0 && x === y;
}

/** Restore the stored token fields onto `next` (dropping the page's copies). */
function keepTokens(next: SessionBlob, stored: SessionBlob): void {
  for (const key of TOKEN_FIELDS) {
    if (stored[key] === undefined) {
      delete next[key];
    } else {
      next[key] = stored[key];
    }
  }
}
