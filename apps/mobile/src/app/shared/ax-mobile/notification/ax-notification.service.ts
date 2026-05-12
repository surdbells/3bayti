/**
 * AxNotificationService
 *
 * Public service for showing in-app notifications. API surface is
 * drop-in compatible with @ngxpert/hot-toast for the methods we use:
 *   success / error / warning / info / loading (message, options)
 *
 * Internally manages a signal-backed list of active notifications.
 * The <ax-notification-host> component subscribes to that signal and
 * renders the stack.
 *
 * See docs/m3-notification-spec.md for design rationale.
 */

import { Injectable, signal, type WritableSignal } from '@angular/core';

import {
  AX_NOTIFICATION_DEFAULT_CONFIG,
  AX_NOTIFICATION_DEFAULT_DURATIONS,
  type AxNotificationConfig,
  type AxNotificationItem,
  type AxNotificationOptions,
  type AxNotificationPosition,
  type AxNotificationRef,
  type AxNotificationStatus,
} from './ax-notification.types';
import { axNotificationHaptic, axNotificationId } from './ax-notification.utils';

@Injectable({ providedIn: 'root' })
export class AxNotificationService {
  /** Internal config — mutable via setters. */
  private _config: Required<AxNotificationConfig> = { ...AX_NOTIFICATION_DEFAULT_CONFIG };

  /**
   * Signal of currently visible notifications.
   * The host component reads this directly via toSignal-style binding.
   */
  readonly items: WritableSignal<AxNotificationItem[]> = signal<AxNotificationItem[]>([]);

  // ---------- Public show methods ----------

  success(message: string, options?: AxNotificationOptions): AxNotificationRef {
    return this.show('success', message, options);
  }

  error(message: string, options?: AxNotificationOptions): AxNotificationRef {
    return this.show('error', message, options);
  }

  warning(message: string, options?: AxNotificationOptions): AxNotificationRef {
    return this.show('warning', message, options);
  }

  info(message: string, options?: AxNotificationOptions): AxNotificationRef {
    return this.show('info', message, options);
  }

  loading(message: string, options?: AxNotificationOptions): AxNotificationRef {
    return this.show('loading', message, options);
  }

  /**
   * Generic show. Useful when status is computed at runtime.
   */
  show(
    status: AxNotificationStatus,
    message: string,
    options?: AxNotificationOptions,
  ): AxNotificationRef {
    const id = options?.id ?? axNotificationId();

    // Dedupe identical messages within the dedupe window: if an existing
    // notification has the same message + status and was created < N ms
    // ago, refresh it instead of creating a new one.
    const dedupe = this.findRecentDuplicate(status, message);
    if (dedupe) {
      this.updateItem(dedupe.id, { duration: this.resolveDuration(status, options) });
      return this.refFor(dedupe.id);
    }

    const duration = this.resolveDuration(status, options);
    const position = options?.position ?? this._config.defaultPosition;

    const item: AxNotificationItem = {
      id,
      status,
      title: options?.title,
      message,
      position,
      className: options?.className,
      createdAt: Date.now(),
      duration,
      timerId: null,
      animState: 'entering',
    };

    // Push, capping at maxStack: if we'd exceed the cap, mark the oldest
    // visible at the same position as 'leaving' so it animates out.
    this.items.update((current) => {
      const samePosition = current.filter((n) => n.position === position);
      const next = [...current, item];

      if (samePosition.length >= this._config.maxStack) {
        // Find the oldest non-leaving item at this position and dismiss it.
        const oldest = samePosition
          .filter((n) => n.animState !== 'leaving')
          .sort((a, b) => a.createdAt - b.createdAt)[0];
        if (oldest) {
          this.beginLeaving(oldest.id);
        }
      }

      return next;
    });

    // Promote 'entering' -> 'visible' on next tick so the CSS enter
    // transition runs.
    setTimeout(() => this.promoteToVisible(id), 16);

    // Auto-dismiss timer (skipped when duration === 0).
    if (duration > 0) {
      const timerId = setTimeout(() => this.dismiss(id), duration);
      this.items.update((current) =>
        current.map((n) => (n.id === id ? { ...n, timerId } : n)),
      );
    }

    // Fire-and-forget haptic; don't block on it.
    void axNotificationHaptic(status, this._config.haptics);

    return this.refFor(id);
  }

  // ---------- Public controls ----------

  /**
   * Dismiss by id, or all if no id is supplied.
   * Animates out before removing.
   */
  dismiss(id?: string): void {
    if (id == null) {
      const all = this.items();
      all.forEach((n) => this.beginLeaving(n.id));
      return;
    }
    this.beginLeaving(id);
  }

  /**
   * Promise-style API for async operations. Mirrors hot-toast's
   * observe-on-Promise pattern.
   */
  async observe<T>(
    promise: Promise<T>,
    opts: {
      loading?: string;
      success?: string | ((result: T) => string);
      error?: string | ((err: unknown) => string);
    },
  ): Promise<T> {
    const ref = this.loading(opts.loading ?? 'Loading…');
    try {
      const result = await promise;
      const successMsg =
        typeof opts.success === 'function' ? opts.success(result) : (opts.success ?? 'Done');
      ref.update({ status: 'success', message: successMsg });
      return result;
    } catch (err) {
      const errorMsg =
        typeof opts.error === 'function' ? opts.error(err) : (opts.error ?? 'Something went wrong');
      ref.update({ status: 'error', message: errorMsg });
      throw err;
    }
  }

  /** Globally enable/disable haptics. */
  setHaptics(enabled: boolean): void {
    this._config.haptics = enabled;
  }

  /** Update config at runtime (rare; usually set once at boot). */
  configure(patch: AxNotificationConfig): void {
    this._config = { ...this._config, ...patch };
  }

  // ---------- Internal helpers ----------

  private resolveDuration(
    status: AxNotificationStatus,
    options?: AxNotificationOptions,
  ): number {
    if (options?.duration != null) return options.duration;
    return AX_NOTIFICATION_DEFAULT_DURATIONS[status];
  }

  private findRecentDuplicate(
    status: AxNotificationStatus,
    message: string,
  ): AxNotificationItem | undefined {
    const window = this._config.dedupeWindowMs;
    const now = Date.now();
    return this.items().find(
      (n) =>
        n.status === status &&
        n.message === message &&
        n.animState !== 'leaving' &&
        now - n.createdAt < window,
    );
  }

  private promoteToVisible(id: string): void {
    this.items.update((current) =>
      current.map((n) =>
        n.id === id && n.animState === 'entering' ? { ...n, animState: 'visible' } : n,
      ),
    );
  }

  private beginLeaving(id: string): void {
    this.items.update((current) =>
      current.map((n) => {
        if (n.id !== id) return n;
        if (n.timerId != null) clearTimeout(n.timerId);
        return { ...n, animState: 'leaving', timerId: null };
      }),
    );
    // Remove after exit animation completes (180ms per spec + 20ms buffer).
    setTimeout(() => this.removeItem(id), 200);
  }

  private removeItem(id: string): void {
    this.items.update((current) => current.filter((n) => n.id !== id));
  }

  private updateItem(id: string, patch: Partial<AxNotificationItem>): void {
    this.items.update((current) =>
      current.map((n) => {
        if (n.id !== id) return n;
        // Reset auto-dismiss timer when updating an active notification.
        if (n.timerId != null) clearTimeout(n.timerId);
        const merged: AxNotificationItem = { ...n, ...patch, timerId: null };
        if (merged.duration > 0 && merged.animState !== 'leaving') {
          merged.timerId = setTimeout(() => this.dismiss(id), merged.duration);
        }
        return merged;
      }),
    );
  }

  /** Build the public ref handed back to callers. */
  private refFor(id: string): AxNotificationRef {
    return {
      get id() {
        return id;
      },
      dismiss: () => this.dismiss(id),
      update: (patch) => {
        const current = this.items().find((n) => n.id === id);
        if (!current) return;
        const newStatus = patch.status ?? current.status;
        const newDuration = patch.duration ?? AX_NOTIFICATION_DEFAULT_DURATIONS[newStatus];
        this.updateItem(id, {
          status: patch.status ?? current.status,
          title: patch.title !== undefined ? patch.title : current.title,
          message: patch.message ?? current.message,
          duration: newDuration,
        });
        // On status transition, fire haptic for the new status.
        if (patch.status && patch.status !== current.status) {
          void axNotificationHaptic(patch.status, this._config.haptics);
        }
      },
    };
  }
}
