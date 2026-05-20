import { Injectable } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { Preferences } from '@capacitor/preferences';
import {
  ActionPerformed,
  PushNotifications,
  PushNotificationSchema,
  Token,
} from '@capacitor/push-notifications';
import { PushRegistrationService } from './push-registration.service';

/** Minimal shape of the user object stored in Preferences('user'). */
interface StoredUser {
  id?: number;
  token?: string;
}

/** A handler invoked when a push notification is tapped (deep-link). */
export type PushTapHandler = (data: Record<string, unknown>) => void;

/**
 * Map a push notification's `data` payload to a router path (Q-Z5.3).
 *
 * All order lifecycle pushes carry { type, order_id, order_reference }
 * (see the backend PushNotificationService). Every order event
 * deep-links to the customer per-order detail page, `/orders/:id`.
 *
 * Pure + exported so it can be unit-tested without the plugin, Router,
 * or a native platform. Returns null when there's no usable target
 * (missing/blank order_id, or an unrecognised payload) — the caller
 * then does nothing rather than navigating somewhere wrong.
 */
export function resolvePushDeepLink(data: Record<string, unknown> | null | undefined): string | null {
  if (data === null || data === undefined) {
    return null;
  }
  const rawId = data['order_id'];
  // FCM delivers all data values as strings, but be tolerant of numbers.
  const orderId =
    typeof rawId === 'string' ? rawId.trim() : typeof rawId === 'number' ? String(rawId) : '';
  if (orderId === '') {
    return null;
  }
  return `/orders/${orderId}`;
}

/**
 * PushManager (mobile) — owns the push-notification lifecycle:
 * native listeners, permission request, and orchestration of
 * token register/deactivate via PushRegistrationService.
 *
 * Design (Q-Z5.1=A: register after sign-in)
 * -----------------------------------------
 * Listeners are attached ONCE on native app launch (initListeners,
 * called from AppComponent). The plugin's `registration` event can
 * fire at any time (initial register, OS token rotation), so the
 * manager caches the latest device token and, whenever it has both a
 * device token AND a signed-in auth token, registers with the backend.
 *
 * We do NOT request permission at launch. AppComponent only attaches
 * listeners; permission is requested from onSignedIn (i.e. after the
 * user authenticates), satisfying "not on first launch".
 *
 * Sign-in  → onSignedIn(authToken): request permission + register
 *            with the plugin; the resulting `registration` event
 *            sends the cached/just-arrived device token to the backend.
 * Sign-out → onSignedOut(authToken): deactivate the device token on
 *            the backend so a shared device stops receiving the
 *            previous user's pushes (Q-Z5.2).
 *
 * Everything is guarded on Capacitor.isNativePlatform() so the service
 * is inert on web and in unit tests (where the push plugin throws
 * "not implemented").
 *
 * Tap handling (deep-link) is delegated to a settable PushTapHandler
 * (wired in Z.5-C) so the routing concern lives with the component
 * that owns the Router, not in this service.
 */
@Injectable({ providedIn: 'root' })
export class PushManager {
  private listenersAttached = false;
  /** Latest device (FCM) token from the plugin, if any. */
  private deviceToken: string | null = null;
  /** Tap handler, set by AppComponent in Z.5-C. */
  private tapHandler: PushTapHandler | null = null;

  constructor(private readonly registration: PushRegistrationService) {}

  /**
   * Attach the push listeners once. Safe to call on every launch; a
   * no-op after the first call and on non-native platforms.
   */
  async initListeners(): Promise<void> {
    if (this.listenersAttached || !Capacitor.isNativePlatform()) {
      return;
    }
    this.listenersAttached = true;

    try {
      await PushNotifications.addListener('registration', (token: Token) => {
        this.deviceToken = token.value;
        // If a user is already signed in, push the token now. (On a
        // fresh sign-in, onSignedIn triggers register() which leads
        // here; on OS token rotation while signed in, this re-syncs.)
        void this.syncTokenIfSignedIn();
      });

      await PushNotifications.addListener('registrationError', (err) => {
        console.warn('[push] registration error', err);
      });

      await PushNotifications.addListener(
        'pushNotificationReceived',
        (_notification: PushNotificationSchema) => {
          // Foreground presentation is handled by the OS via
          // presentationOptions in capacitor.config; nothing to do
          // here. (Z.5-C may add in-app affordances.)
        },
      );

      await PushNotifications.addListener(
        'pushNotificationActionPerformed',
        (action: ActionPerformed) => {
          const data = (action?.notification?.data ?? {}) as Record<string, unknown>;
          if (this.tapHandler !== null) {
            this.tapHandler(data);
          }
        },
      );
    } catch (e) {
      console.warn('[push] initListeners failed', e);
    }
  }

  /** Register a deep-link tap handler (Z.5-C). */
  setTapHandler(handler: PushTapHandler): void {
    this.tapHandler = handler;
  }

  /**
   * Called after the user signs in. Requests permission (the moment
   * chosen per Q-Z5.1=A) and, if granted, registers with the plugin —
   * which fires `registration`, sending the token to the backend.
   * No-op on non-native platforms.
   */
  async onSignedIn(): Promise<void> {
    if (!Capacitor.isNativePlatform()) {
      return;
    }
    try {
      const perm = await PushNotifications.requestPermissions();
      if (perm.receive !== 'granted') {
        console.info('[push] permission not granted:', perm.receive);
        return;
      }
      await PushNotifications.register();
      // If the device token is already known (e.g. user signed out and
      // back in without an app restart), sync immediately too.
      await this.syncTokenIfSignedIn();
    } catch (e) {
      console.warn('[push] onSignedIn failed', e);
    }
  }

  /**
   * Called on sign-out. Deactivates this device's token on the backend
   * using the auth token that's still valid at logout time. No-op on
   * non-native platforms or when there's no device token.
   *
   * @param authToken the (still-valid) auth token captured before the
   *        session is cleared.
   */
  async onSignedOut(authToken: string): Promise<void> {
    if (!Capacitor.isNativePlatform() || this.deviceToken === null || authToken === '') {
      return;
    }
    try {
      await this.registration.deactivate(authToken, this.deviceToken);
    } catch (e) {
      console.warn('[push] onSignedOut deactivate failed', e);
    }
  }

  /**
   * Convenience for logout handlers: reads the still-valid auth token
   * from Preferences itself, then deactivates. MUST be awaited (or its
   * promise chained) BEFORE the caller removes the 'user' key, since it
   * needs the token to still be present. No-op on non-native or when no
   * token / device token is available.
   */
  async onSignedOutReadingToken(): Promise<void> {
    if (!Capacitor.isNativePlatform() || this.deviceToken === null) {
      return;
    }
    const authToken = await this.readAuthToken();
    await this.onSignedOut(authToken);
  }

  /**
   * If we have both a device token and a signed-in auth token, register
   * the token with the backend. Idempotent server-side, so calling it
   * more than once is harmless.
   */
  private async syncTokenIfSignedIn(): Promise<void> {
    if (this.deviceToken === null) {
      return;
    }
    const authToken = await this.readAuthToken();
    if (authToken === '') {
      return;
    }
    try {
      await this.registration.register(authToken, this.deviceToken);
    } catch (e) {
      console.warn('[push] token sync failed', e);
    }
  }

  /** Read the stored auth token from Preferences('user'); '' if none. */
  private async readAuthToken(): Promise<string> {
    try {
      const ret = await Preferences.get({ key: 'user' });
      if (ret.value === null || ret.value === undefined || ret.value === '') {
        return '';
      }
      const user = JSON.parse(ret.value) as StoredUser;
      return typeof user.token === 'string' ? user.token : '';
    } catch {
      return '';
    }
  }
}
