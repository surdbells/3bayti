import { Injectable, signal, computed } from '@angular/core';

/**
 * Toast service — small global notification queue.
 *
 * Used by AuthService callers (login / register / etc.) to surface
 * "unmapped" API errors that don't fit into a specific FormControl's
 * inline error. Examples: 5xx upstream errors, network failures,
 * OTP_PROVIDER_ERROR.
 *
 * Per-field validation errors should NOT go through here — they go on
 * the FormField via mapApiErrors (api-error-mapping.ts).
 *
 * Why a signal-based queue rather than a CDK overlay
 * --------------------------------------------------
 * The auth pages only show one or two toasts at a time. A signal-array
 * + a simple visual container under app.html is cheaper than reaching
 * for @angular/cdk overlay or a 3rd-party hot-toast library that's
 * peer-incompatible with Angular 21 (apps/portal already runs into
 * this with @ngneat/hot-toast 7.0.0). When richer toast UX is needed
 * (action buttons, undo, etc.), Y.2 or beyond can swap in a library.
 *
 * Auto-dismiss
 * ------------
 * Each toast carries a `durationMs` (default 5000). The service sets a
 * timer on push and removes the toast on expiry. Consumers can pass
 * `durationMs: 0` for sticky toasts (e.g. "your session has expired"
 * with a click-to-dismiss).
 *
 * SSR
 * ---
 * Toasts created on the server would be lost on hydration. The
 * service is harmless to construct on the server, but the toast
 * container component conditionally renders on the browser only.
 */

export interface Toast {
  /** Stable identifier — used for keyed *ngFor and manual dismiss. */
  id: string;
  /** i18n key OR plain string. We don't enforce — consumers know. */
  message: string;
  /** Translation params for {{ }} interpolation. */
  params?: Record<string, unknown>;
  /** Visual variant — drives icon + colour. */
  kind: 'success' | 'error' | 'info' | 'warning';
  /** Milliseconds before auto-dismiss. 0 = sticky. */
  durationMs: number;
}

export interface ToastInput {
  message: string;
  params?: Record<string, unknown>;
  kind?: Toast['kind'];
  durationMs?: number;
}

@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly _toasts = signal<Toast[]>([]);

  /** Current visible toasts — for the container component to render. */
  readonly toasts = this._toasts.asReadonly();
  /** True when at least one toast is visible. Useful for screen-reader
   *  region rendering decisions. */
  readonly hasToasts = computed(() => this._toasts().length > 0);

  /** Auto-dismiss timers keyed by toast id so dismiss() can cancel. */
  private timers = new Map<string, ReturnType<typeof setTimeout>>();

  private idCounter = 0;

  /**
   * Push a new toast onto the stack. Returns the toast id so callers
   * can dismiss it explicitly (e.g. on retry click).
   */
  show(input: ToastInput): string {
    const id = `t-${++this.idCounter}-${Date.now().toString(36)}`;
    const toast: Toast = {
      id,
      message: input.message,
      params: input.params,
      kind: input.kind ?? 'info',
      durationMs: input.durationMs ?? 5000,
    };
    this._toasts.update(list => [...list, toast]);

    if (toast.durationMs > 0) {
      const handle = setTimeout(() => this.dismiss(id), toast.durationMs);
      this.timers.set(id, handle);
    }

    return id;
  }

  /** Convenience helpers — same as show() with the kind pre-set. */
  success(message: string, params?: Record<string, unknown>): string {
    return this.show({ message, params, kind: 'success' });
  }
  error(message: string, params?: Record<string, unknown>): string {
    return this.show({ message, params, kind: 'error' });
  }
  warning(message: string, params?: Record<string, unknown>): string {
    return this.show({ message, params, kind: 'warning' });
  }
  info(message: string, params?: Record<string, unknown>): string {
    return this.show({ message, params, kind: 'info' });
  }

  /** Dismiss by id. Idempotent. */
  dismiss(id: string): void {
    const timer = this.timers.get(id);
    if (timer !== undefined) {
      clearTimeout(timer);
      this.timers.delete(id);
    }
    this._toasts.update(list => list.filter(t => t.id !== id));
  }

  /** Clear all visible toasts. Useful on route navigation. */
  clearAll(): void {
    for (const handle of this.timers.values()) {
      clearTimeout(handle);
    }
    this.timers.clear();
    this._toasts.set([]);
  }
}
