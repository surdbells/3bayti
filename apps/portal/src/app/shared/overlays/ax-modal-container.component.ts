import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Standard modal chrome. Slot content into [header], [body], and [footer]
 * selectors, or just wrap body content directly between the tags.
 *
 * Usage inside a component opened by AxModalService:
 *
 *   <app-ax-modal-container title="Edit product" subtitle="SKU: 4238"
 *                           (closed)="modalRef.close()">
 *     <div body>...form content...</div>
 *     <ng-container footer>
 *       <button class="ax-btn ax-btn-ghost" (click)="modalRef.close()">Cancel</button>
 *       <button class="ax-btn ax-btn-primary" (click)="save()">Save</button>
 *     </ng-container>
 *   </app-ax-modal-container>
 */
@Component({
  selector: 'app-ax-modal-container',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="ax-modal-header" *ngIf="title || subtitle || showClose">
      <div class="ax-modal-header-content">
        <h2 class="ax-modal-title" *ngIf="title">{{ title }}</h2>
        <p class="ax-modal-subtitle" *ngIf="subtitle">{{ subtitle }}</p>
      </div>
      <button
        *ngIf="showClose"
        type="button"
        class="ax-modal-close"
        aria-label="Close dialog"
        (click)="closed.emit()"
      >
        <span class="material-symbols-outlined" aria-hidden="true">close</span>
      </button>
    </header>

    <div class="ax-modal-body" [class.ax-modal-body-flush]="bodyFlush">
      <ng-content select="[body]"></ng-content>
      <ng-content></ng-content>
    </div>

    <footer class="ax-modal-footer" [class.ax-modal-footer-between]="footerBetween" *ngIf="hasFooter">
      <ng-content select="[footer]"></ng-content>
    </footer>
  `,
})
export class AxModalContainerComponent {
  @Input() title?: string;
  @Input() subtitle?: string;
  @Input() showClose = true;
  @Input() bodyFlush = false;
  @Input() footerBetween = false;
  /** Whether to render the footer slot at all. Set false for modals without actions. */
  @Input() hasFooter = true;

  @Output() closed = new EventEmitter<void>();
}
