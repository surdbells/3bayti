/**
 * Public API for the abayti mobile notification primitive.
 *
 * Callers import from this file:
 *
 *   import { AxNotificationService } from '@/shared/ax-mobile/notification';
 *
 * (or relative path; alias depends on tsconfig paths setup).
 */

export { AxNotificationService } from './ax-notification.service';
export { AxNotificationComponent } from './ax-notification.component';
export { AxNotificationHostComponent } from './ax-notification-host.component';

export type {
  AxNotificationStatus,
  AxNotificationPosition,
  AxNotificationOptions,
  AxNotificationRef,
  AxNotificationConfig,
} from './ax-notification.types';
