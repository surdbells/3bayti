import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { AxModalContainerComponent } from './ax-modal-container.component';
import { AxModalRef, AX_MODAL_DATA } from './ax-modal.service';
import type { AxConfirmOptions } from './ax-confirm.service';

/**
 * Confirm dialog rendered by AxConfirmService.
 * Closes with `true` on confirm, `false` on cancel/backdrop/ESC.
 */
@Component({
  selector: 'app-ax-confirm-dialog',
  standalone: true,
  imports: [AxModalContainerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-ax-modal-container
      [title]="title"
      [showClose]="false"
      (closed)="onCancel()">
      <p class="ax-confirm-message">{{ message }}</p>
      <ng-container footer>
        <button type="button" class="ax-btn ax-btn-ghost" (click)="onCancel()">
          {{ cancelLabel }}
        </button>
        <button
          type="button"
          [class]="confirmBtnClass"
          (click)="onConfirm()">
          {{ confirmLabel }}
        </button>
      </ng-container>
    </app-ax-modal-container>
  `,
  styles: [`
    .ax-confirm-message {
      margin: 0;
      color: var(--ax-text-secondary, #4b5563);
      font-size: 0.95rem;
      line-height: 1.5;
    }
  `],
})
export class AxConfirmDialogComponent {
  private readonly data = inject(AX_MODAL_DATA) as AxConfirmOptions;
  private readonly modalRef = inject<AxModalRef<AxConfirmDialogComponent, boolean>>(AxModalRef);

  readonly title = this.data.title ?? 'Confirm';
  readonly message = this.data.message;
  readonly confirmLabel = this.data.confirmLabel ?? 'Confirm';
  readonly cancelLabel = this.data.cancelLabel ?? 'Cancel';
  readonly confirmBtnClass =
    this.data.variant === 'danger'
      ? 'ax-btn ax-btn-danger'
      : 'ax-btn ax-btn-primary';

  onConfirm(): void {
    this.modalRef.close(true);
  }

  onCancel(): void {
    this.modalRef.close(false);
  }
}
