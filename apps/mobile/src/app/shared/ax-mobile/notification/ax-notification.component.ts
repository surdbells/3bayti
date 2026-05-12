/**
 * AxNotificationComponent — single notification card.
 *
 * Pure presentation. State is owned by AxNotificationService and the
 * stack is managed by AxNotificationHostComponent. This component only
 * receives an item and emits a 'tap' event when the user dismisses by
 * tapping the surface.
 */

import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';

import type { AxNotificationItem } from './ax-notification.types';

@Component({
  selector: 'ax-notification',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-notification.component.html',
  styleUrl: './ax-notification.component.scss',
})
export class AxNotificationComponent {
  @Input({ required: true }) item!: AxNotificationItem;
  @Output() tap = new EventEmitter<void>();

  /** Aria role/live for screen-reader announcements. */
  get ariaLive(): 'assertive' | 'polite' {
    return this.item.status === 'error' ? 'assertive' : 'polite';
  }

  onTap(): void {
    this.tap.emit();
  }

  onKeydown(event: KeyboardEvent): void {
    // Allow keyboard dismissal: Enter or Space.
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      this.tap.emit();
    }
  }
}
