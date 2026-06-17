import { Component, ChangeDetectionStrategy, inject, PLATFORM_ID } from '@angular/core';
import { NgFor, NgIf, isPlatformBrowser } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';
import { ToastService, Toast } from './toast.service';

/**
 * Toast container — renders the current toast stack as a fixed overlay.
 *
 * Mount once at the app shell (app.html). The component listens to
 * ToastService and renders whatever's there.
 *
 * Positioning
 * -----------
 * Top-right on LTR pages, top-left on RTL pages — handled by
 * inset-inline-start (CSS logical property that flips with `dir`).
 *
 * Accessibility
 * -------------
 * The container is a role="region" with aria-live="polite" so screen
 * readers announce new toasts without interrupting the user mid-task.
 * Critical errors that demand attention should use aria-live="assertive"
 * — but for Y.1's "session expired"-class messages, polite is fine.
 *
 * Each toast has a close button with an aria-label so it's reachable
 * by keyboard.
 *
 * SSR
 * ---
 * Renders nothing on the server (no toasts can be queued during
 * prerender anyway, and SSR'd toasts would never reach the user).
 */
@Component({
  selector: 'ui-toast-container',
  standalone: true,
  imports: [NgIf, NgFor, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      *ngIf="isBrowser && toasts().length > 0"
      class="toast-container"
      role="region"
      [attr.aria-label]="'common.notifications' | translate"
      aria-live="polite"
    >
      <div
        *ngFor="let toast of toasts(); trackBy: trackById"
        class="toast"
        [class.toast--success]="toast.kind === 'success'"
        [class.toast--error]="toast.kind === 'error'"
        [class.toast--warning]="toast.kind === 'warning'"
        [class.toast--info]="toast.kind === 'info'"
      >
        <p class="toast__message">{{ toast.message | translate : toast.params || {} }}</p>
        <button
          type="button"
          class="toast__close"
          [attr.aria-label]="'common.close' | translate"
          (click)="dismiss(toast.id)"
        >
          ×
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .toast-container {
        position: fixed;
        top: 16px;
        inset-inline-end: 16px;
        z-index: 1000;
        display: flex;
        flex-direction: column;
        gap: 8px;
        max-width: 360px;
        pointer-events: none;
      }

      .toast {
        background: #fff;
        border-radius: 8px;
        padding: 12px 14px;
        box-shadow: 0 8px 20px rgba(46, 36, 28, 0.18);
        display: flex;
        align-items: flex-start;
        gap: 12px;
        pointer-events: auto;
        border-inline-start: 4px solid var(--color-text-muted, #6b6056);
        animation: slide-in 0.2s ease-out;
      }

      @keyframes slide-in {
        from {
          transform: translateY(-8px);
          opacity: 0;
        }
        to {
          transform: translateY(0);
          opacity: 1;
        }
      }

      .toast--success {
        border-inline-start-color: #2e7d32;
      }
      .toast--error {
        border-inline-start-color: #b42218;
      }
      .toast--warning {
        border-inline-start-color: #c47a08;
      }
      .toast--info {
        border-inline-start-color: var(--color-brand-500, #b18f1f);
      }

      .toast__message {
        flex: 1;
        margin: 0;
        font-size: 14px;
        color: var(--color-text-primary, #2e241c);
        line-height: 1.4;
      }

      .toast__close {
        appearance: none;
        background: transparent;
        border: 0;
        cursor: pointer;
        font-size: 22px;
        line-height: 1;
        color: var(--color-text-muted, #6b6056);
        padding: 0 4px;
        margin: -4px -4px -4px 0;
      }

      .toast__close:hover,
      .toast__close:focus-visible {
        color: var(--color-text-primary, #2e241c);
        outline: none;
      }
    `,
  ],
})
export class ToastContainerComponent {
  private readonly toastService = inject(ToastService);
  private readonly platformId = inject(PLATFORM_ID);

  protected readonly toasts = this.toastService.toasts;
  protected readonly isBrowser = isPlatformBrowser(this.platformId);

  protected dismiss(id: string): void {
    this.toastService.dismiss(id);
  }

  protected trackById(_index: number, toast: Toast): string {
    return toast.id;
  }
}
