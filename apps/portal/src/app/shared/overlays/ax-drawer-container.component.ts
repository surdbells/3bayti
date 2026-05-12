import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Standard drawer chrome. Same slot pattern as AxModalContainer.
 */
@Component({
  selector: 'app-ax-drawer-container',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="ax-drawer-header" *ngIf="title || subtitle || showClose">
      <div class="ax-drawer-header-content">
        <h2 class="ax-drawer-title" *ngIf="title">{{ title }}</h2>
        <p class="ax-drawer-subtitle" *ngIf="subtitle">{{ subtitle }}</p>
      </div>
      <button
        *ngIf="showClose"
        type="button"
        class="ax-drawer-close"
        aria-label="Close drawer"
        (click)="closed.emit()"
      >
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </header>

    <div class="ax-drawer-body" [class.ax-drawer-body-flush]="bodyFlush">
      <ng-content select="[body]"></ng-content>
      <ng-content></ng-content>
    </div>

    <footer class="ax-drawer-footer" *ngIf="hasFooter">
      <ng-content select="[footer]"></ng-content>
    </footer>
  `,
})
export class AxDrawerContainerComponent {
  @Input() title?: string;
  @Input() subtitle?: string;
  @Input() showClose = true;
  @Input() bodyFlush = false;
  @Input() hasFooter = true;

  @Output() closed = new EventEmitter<void>();
}
