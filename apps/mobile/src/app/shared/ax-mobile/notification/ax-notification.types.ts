/**
 * Public types for the abayti mobile notification primitive.
 *
 * See docs/m3-notification-spec.md for the full design rationale.
 */

export type AxNotificationStatus =
  | 'success'
  | 'error'
  | 'warning'
  | 'info'
  | 'loading';

export type AxNotificationPosition =
  | 'top-center'
  | 'top-left'
  | 'top-right'
  | 'bottom-center'
  | 'bottom-left'
  | 'bottom-right';

/**
 * Options accepted by every show method.
 * All fields optional; sensible defaults applied per status.
 */
export interface AxNotificationOptions {
  /** Optional headline; rendered in Poltawski display above the message. */
  title?: string;

  /**
   * Auto-dismiss after this many ms. 0 = sticky (must be dismissed
   * programmatically or by tap). Defaults per status:
   *   success 3000, info 4000, warning 5000, error 6000, loading 0 (sticky).
   */
  duration?: number;

  /** Where on screen to anchor. Defaults to 'top-center'. */
  position?: AxNotificationPosition;

  /** Stable id for deduplication / programmatic dismiss. Auto-generated if omitted. */
  id?: string;

  /** Extra className appended to the host element for one-off overrides. */
  className?: string;
}

/**
 * Reference to a live notification. Returned by every show method.
 * Lets callers dismiss or update the notification after creation.
 */
export interface AxNotificationRef {
  /** Stable id (auto-generated if not supplied to the show method). */
  readonly id: string;

  /** Dismiss this notification immediately (animates out). */
  dismiss(): void;

  /**
   * Update the notification in place. Useful for the
   * loading -> success/error pattern:
   *
   *   const ref = notify.loading('Placing your order…');
   *   try {
   *     await api.checkout();
   *     ref.update({ status: 'success', title: 'Order placed', message: '…' });
   *   } catch (err) {
   *     ref.update({ status: 'error', title: 'Payment failed', message: err.message });
   *   }
   *
   * Status transitions animate the dot color over 200ms.
   */
  update(patch: {
    status?: AxNotificationStatus;
    message?: string;
    title?: string;
    duration?: number;
  }): void;
}

/**
 * Internal notification record managed by the host component.
 * Not exposed via the public API.
 */
export interface AxNotificationItem {
  id: string;
  status: AxNotificationStatus;
  title?: string;
  message: string;
  position: AxNotificationPosition;
  className?: string;
  /** Wall-clock ms when the notification was created (for staleness/debounce). */
  createdAt: number;
  /** ms duration; 0 = sticky. */
  duration: number;
  /** Setter for the auto-dismiss timer; cleared on dismiss/update. */
  timerId: ReturnType<typeof setTimeout> | null;
  /** Animation state — drives enter/exit CSS classes. */
  animState: 'entering' | 'visible' | 'leaving';
}

/**
 * Options that allow the AxNotificationService to be tuned at app boot
 * (e.g. via APP_INITIALIZER). Currently small; designed to grow.
 */
export interface AxNotificationConfig {
  /** Globally enable/disable haptics. Default true; iOS native only. */
  haptics?: boolean;
  /** Default position when none specified per call. Default 'top-center'. */
  defaultPosition?: AxNotificationPosition;
  /** Maximum simultaneous visible notifications. Default 3. */
  maxStack?: number;
  /** Debounce window in ms — identical messages within this window update existing. Default 200. */
  dedupeWindowMs?: number;
}

export const AX_NOTIFICATION_DEFAULT_CONFIG: Required<AxNotificationConfig> = {
  haptics: true,
  defaultPosition: 'top-center',
  maxStack: 3,
  dedupeWindowMs: 200,
};

/**
 * Per-status default durations (ms). 0 = sticky.
 * These match the locked spec — keep in sync with docs/m3-notification-spec.md.
 */
export const AX_NOTIFICATION_DEFAULT_DURATIONS: Record<AxNotificationStatus, number> = {
  success: 3000,
  info:    4000,
  warning: 5000,
  error:   6000,
  loading: 0, // sticky
};
