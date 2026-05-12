/**
 * AxNotificationHostComponent
 *
 * Bootstrapped once at app start (in app.component.html). Subscribes
 * to the AxNotificationService.items signal and renders the active
 * notification stack at each supported position.
 *
 * Notifications are partitioned by position so each corner/center has
 * its own stack. Within a position, notifications stack newest-on-top.
 */

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { CommonModule } from '@angular/common';

import { AxNotificationComponent } from './ax-notification.component';
import { AxNotificationService } from './ax-notification.service';
import type { AxNotificationItem, AxNotificationPosition } from './ax-notification.types';

const POSITIONS: AxNotificationPosition[] = [
  'top-center',
  'top-left',
  'top-right',
  'bottom-center',
  'bottom-left',
  'bottom-right',
];

@Component({
  selector: 'ax-notification-host',
  standalone: true,
  imports: [CommonModule, AxNotificationComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-notification-host.component.html',
  styleUrl: './ax-notification-host.component.scss',
})
export class AxNotificationHostComponent {
  private notify = inject(AxNotificationService);

  readonly items = this.notify.items;

  readonly positions = POSITIONS;

  /** Items at a given position, ordered oldest -> newest (newest visually on top via flex-direction). */
  itemsAt(position: AxNotificationPosition): AxNotificationItem[] {
    return this.items().filter((n) => n.position === position);
  }

  /** Whether any position has items (so the host can self-hide when empty). */
  readonly hasAny = computed(() => this.items().length > 0);

  trackById(_index: number, item: AxNotificationItem): string {
    return item.id;
  }

  onTap(id: string): void {
    this.notify.dismiss(id);
  }
}
