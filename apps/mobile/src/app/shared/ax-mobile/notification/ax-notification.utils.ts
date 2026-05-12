/**
 * Internal helpers for the notification primitive.
 * Not part of the public API.
 */

import { Capacitor } from '@capacitor/core';
import { Haptics, ImpactStyle } from '@capacitor/haptics';

import type { AxNotificationStatus } from './ax-notification.types';

/**
 * Stable-ish id generator. Combines wall-clock + counter to avoid
 * collisions when multiple notifications fire in the same ms.
 */
let _idCounter = 0;
export function axNotificationId(): string {
  _idCounter = (_idCounter + 1) % 1_000_000;
  return `ax-notification-${Date.now().toString(36)}-${_idCounter.toString(36)}`;
}

/**
 * Trigger a haptic for the given status, but only:
 *   - on a native platform (Capacitor reports as native), and
 *   - for status types we've decided merit haptics (error, success).
 *
 * Errors are swallowed silently — haptics are a nice-to-have, not a
 * critical path. PWA / desktop / Android-with-quirky-vibration all
 * fall through to no-op.
 */
export async function axNotificationHaptic(
  status: AxNotificationStatus,
  enabled: boolean,
): Promise<void> {
  if (!enabled) return;
  if (!Capacitor.isNativePlatform()) return;

  try {
    if (status === 'error') {
      await Haptics.impact({ style: ImpactStyle.Medium });
    } else if (status === 'success') {
      await Haptics.impact({ style: ImpactStyle.Light });
    }
    // info / warning / loading — no haptic per spec
  } catch {
    // Swallow — haptics are non-critical.
  }
}

/**
 * Match the prefers-reduced-motion media query, with safe defaults
 * for SSR / non-browser environments.
 */
export function axPrefersReducedMotion(): boolean {
  if (typeof window === 'undefined' || !window.matchMedia) return false;
  try {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch {
    return false;
  }
}
