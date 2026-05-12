import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { AxModalService } from './ax-modal.service';
import { AxConfirmDialogComponent } from './ax-confirm-dialog.component';

export type AxConfirmVariant = 'default' | 'danger';

export interface AxConfirmOptions {
  /** Dialog title shown in the header. Default 'Confirm'. */
  title?: string;
  /** Body text describing what will happen. Required. */
  message: string;
  /** Confirm button label. Default 'Confirm'. */
  confirmLabel?: string;
  /** Cancel button label. Default 'Cancel'. */
  cancelLabel?: string;
  /** 'danger' renders a red confirm button (for destructive actions). Default 'default'. */
  variant?: AxConfirmVariant;
}

/**
 * Promise-based confirm dialog.
 *
 * Usage:
 *   private readonly confirm = inject(AxConfirmService);
 *
 *   async deleteItem() {
 *     const ok = await this.confirm.confirm({
 *       title: 'Delete?',
 *       message: 'Are you sure?',
 *       confirmLabel: 'Delete',
 *       variant: 'danger',
 *     });
 *     if (ok) { ... }
 *   }
 */
@Injectable({ providedIn: 'root' })
export class AxConfirmService {
  private readonly modal = inject(AxModalService);

  confirm(opts: AxConfirmOptions): Promise<boolean> {
    const ref = this.modal.open<AxConfirmDialogComponent, boolean, AxConfirmOptions>(
      AxConfirmDialogComponent,
      {
        size: 'sm',
        data: opts,
        ariaLabel: opts.title ?? 'Confirm',
      },
    );
    return firstValueFrom(ref.afterClosed()).then((res) => res === true);
  }
}
