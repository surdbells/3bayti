import {
  Component,
  ChangeDetectionStrategy,
  Input,
  Output,
  EventEmitter,
  ElementRef,
  ViewChild,
  OnChanges,
  SimpleChanges,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * ConfirmModal — a reusable, accessible confirmation dialog.
 *
 * Replaces ad-hoc native window.confirm() calls (order cancel, return
 * request, address delete) with a styled, on-brand, keyboard- and
 * screen-reader-friendly dialog. Built in M3.2.Y.5-C; the future
 * account-deletion flow (Y.6) reuses it for its danger confirmation.
 *
 * Presentational + controlled
 * ----------------------------
 * The host owns the open/closed state via the `open` input and reacts
 * to (confirm)/(cancel) outputs. The modal itself holds no business
 * state — it just renders and emits intent. This keeps it trivially
 * reusable and testable.
 *
 * Accessibility
 * -------------
 *   - role="dialog" + aria-modal="true" + aria-labelledby/​describedby
 *   - Autofocuses the primary action when opened (focus moves into
 *     the dialog so keyboard + SR users land in context)
 *   - Escape key cancels
 *   - Backdrop click cancels
 *   - Confirm button can be styled as a danger action via [danger]
 *
 * Labels are i18n KEYS (translated by the TranslatePipe inside the
 * template), so callers pass keys like 'orders.detail.cancelConfirm'.
 * title/message are required; confirmLabel/cancelLabel default to
 * common.confirm / common.cancel.
 */
@Component({
  selector: 'ui-confirm-modal',
  standalone: true,
  imports: [NgIf, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      *ngIf="open"
      class="confirm-modal__backdrop"
      (click)="onBackdrop($event)"
      data-testid="confirm-modal-backdrop"
    >
      <div
        class="confirm-modal"
        role="dialog"
        aria-modal="true"
        [attr.aria-labelledby]="titleId"
        [attr.aria-describedby]="messageId"
        (keydown.escape)="onCancel()"
        data-testid="confirm-modal"
      >
        <h2 [id]="titleId" class="confirm-modal__title">
          {{ title | translate }}
        </h2>
        <p [id]="messageId" class="confirm-modal__message">
          {{ message | translate }}
        </p>
        <div class="confirm-modal__actions">
          <button
            type="button"
            class="confirm-modal__cancel"
            (click)="onCancel()"
            data-testid="confirm-modal-cancel"
          >
            {{ cancelLabel | translate }}
          </button>
          <button
            #confirmBtn
            type="button"
            class="confirm-modal__confirm"
            [class.confirm-modal__confirm--danger]="danger"
            [disabled]="busy"
            (click)="onConfirm()"
            data-testid="confirm-modal-confirm"
          >
            {{ (busy ? busyLabel : confirmLabel) | translate }}
          </button>
        </div>
      </div>
    </div>
  `,
  styleUrl: './confirm-modal.scss',
})
export class ConfirmModalComponent implements OnChanges {
  /** Whether the dialog is visible. Host-controlled. */
  @Input() open = false;

  /** i18n key for the heading. */
  @Input() title = '';
  /** i18n key for the body message. */
  @Input() message = '';
  /** i18n key for the confirm button (default: common.confirm). */
  @Input() confirmLabel = 'common.confirm';
  /** i18n key for the cancel button (default: common.cancel). */
  @Input() cancelLabel = 'common.cancel';
  /** i18n key shown on the confirm button while busy. */
  @Input() busyLabel = 'common.loading';
  /** Style the confirm action as destructive (red). */
  @Input() danger = false;
  /** Disable the confirm button + show busyLabel (e.g. request in flight). */
  @Input() busy = false;

  @Output() confirm = new EventEmitter<void>();
  @Output() cancel = new EventEmitter<void>();

  @ViewChild('confirmBtn') private confirmBtn?: ElementRef<HTMLButtonElement>;

  /** Stable ids for aria wiring (unique per instance). */
  private static seq = 0;
  private readonly uid = ConfirmModalComponent.seq++;
  protected readonly titleId = `confirm-modal-title-${this.uid}`;
  protected readonly messageId = `confirm-modal-message-${this.uid}`;

  ngOnChanges(changes: SimpleChanges): void {
    /* On the open→true transition, move focus to the primary action.
       Deferred to a macrotask so it runs AFTER this CD pass completes
       and the *ngIf has rendered the button (avoids
       ExpressionChangedAfterItHasBeenCheckedError + a null ViewChild). */
    const c = changes['open'];
    if (c !== undefined && c.currentValue === true && c.previousValue !== true) {
      setTimeout(() => this.confirmBtn?.nativeElement.focus(), 0);
    }
  }

  protected onConfirm(): void {
    if (this.busy) return;
    this.confirm.emit();
  }

  protected onCancel(): void {
    this.cancel.emit();
  }

  protected onBackdrop(event: MouseEvent): void {
    /* Only a click on the backdrop itself (not bubbled from the dialog)
       cancels. */
    if (event.target === event.currentTarget) {
      this.onCancel();
    }
  }
}
