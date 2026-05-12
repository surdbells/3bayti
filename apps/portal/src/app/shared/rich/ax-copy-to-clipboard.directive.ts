import {
  Directive,
  ElementRef,
  EventEmitter,
  HostListener,
  Input,
  Output,
  inject,
  Optional,
} from '@angular/core';
import { HotToastService } from '@ngneat/hot-toast';

/**
 * Copy-to-clipboard directive. Put on any clickable element.
 *
 *   <button class="ax-btn ax-btn-outline"
 *           axCopyToClipboard="ORDER-240098"
 *           axCopyLabel="Order reference">
 *     <span class="material-symbols-outlined">content_copy</span>
 *     Copy order number
 *   </button>
 *
 * Shows a success toast after copy (when HotToastService is available).
 * Emits (copied) on success. If the Clipboard API is unavailable (older
 * browsers, insecure contexts), falls back to execCommand.
 */
@Directive({
  selector: '[axCopyToClipboard]',
  standalone: true,
})
export class AxCopyToClipboardDirective {
  /** The string to copy. */
  @Input('axCopyToClipboard') value: string | null | undefined = '';

  /** Label used in toast: "Copied to clipboard" vs "Order reference copied". */
  @Input() axCopyLabel = 'Copied to clipboard';

  /** Suppress the toast if false. */
  @Input() axCopyShowToast = true;

  @Output() copied = new EventEmitter<string>();
  @Output() copyFailed = new EventEmitter<Error>();

  private readonly host = inject(ElementRef<HTMLElement>);

  // HotToastService is optional — don't throw if unavailable
  constructor(@Optional() private readonly toast: HotToastService | null) {}

  @HostListener('click', ['$event'])
  async onClick(event: MouseEvent): Promise<void> {
    event.preventDefault();
    event.stopPropagation();
    const text = this.value ?? '';
    if (!text) return;

    try {
      if (navigator?.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        this.fallbackCopy(text);
      }
      this.copied.emit(text);
      if (this.axCopyShowToast) {
        const message = this.axCopyLabel === 'Copied to clipboard'
          ? this.axCopyLabel
          : `${this.axCopyLabel} copied`;
        this.toast?.success(message, { duration: 2000 });
      }
    } catch (err) {
      const error = err instanceof Error ? err : new Error(String(err));
      this.copyFailed.emit(error);
      this.toast?.error('Unable to copy');
    }
  }

  private fallbackCopy(text: string): void {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
    } finally {
      document.body.removeChild(ta);
    }
  }
}
