import {
  ChangeDetectionStrategy,
  Component,
  PLATFORM_ID,
  effect,
  inject,
  signal,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../core/auth/auth.service';

/**
 * How long the session has to stay 'unknown' before the banner shows.
 * Covers the first background retry (2s), so a single blip that heals
 * on its own never flashes the banner.
 */
export const SESSION_BANNER_DELAY_MS = 3_000;

/** How long one tap on "Try again" waits for the session to resolve. */
export const SESSION_BANNER_RETRY_WAIT_MS = 20_000;

/**
 * Session reconnect banner, the visible half of "a transient problem
 * never signs anyone out".
 *
 * When the first-load hydrate can't reach the server the session stays
 * 'unknown' (never "signed out") and AuthService retries in the
 * background. Meanwhile the protected-route guards hold a navigation to
 * /account/* or /checkout/* until the session resolves (they never bounce
 * a possibly signed-in customer to /login), which on its own would look
 * like a blank page. This banner explains the wait and offers a retry
 * that skips the remaining backoff. It disappears as soon as the session
 * resolves, and the held navigation then completes by itself.
 *
 * Mounted once in the app shell (app.html). Renders nothing on the
 * server, like the toast container.
 */
@Component({
  selector: 'app-session-banner',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (visible()) {
      <div class="session-banner" role="status" aria-live="polite" data-testid="session-banner">
        <p class="session-banner__message">{{ 'auth.session.unreachable' | translate }}</p>
        <button
          type="button"
          class="session-banner__retry"
          data-testid="session-banner-retry"
          [disabled]="retrying()"
          (click)="retry()"
        >
          {{ (retrying() ? 'auth.session.retrying' : 'auth.session.retry') | translate }}
        </button>
      </div>
    }
  `,
  styles: [
    `
      .session-banner {
        position: fixed;
        inset-block-end: 16px;
        inset-inline: 16px;
        z-index: 900;
        margin-inline: auto;
        max-width: 560px;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 8px;
        background: var(--color-text-primary, #2e241c);
        color: #fdfaf3;
        box-shadow: 0 8px 20px rgba(46, 36, 28, 0.18);
      }

      .session-banner__message {
        flex: 1;
        margin: 0;
        font-size: 14px;
        line-height: 1.4;
      }

      .session-banner__retry {
        appearance: none;
        flex-shrink: 0;
        border: 1px solid var(--color-brand-500, #b18f1f);
        border-radius: 999px;
        background: transparent;
        color: inherit;
        font: inherit;
        font-size: 13px;
        padding: 6px 14px;
        cursor: pointer;
      }

      .session-banner__retry:disabled {
        cursor: progress;
        opacity: 0.7;
      }

      .session-banner__retry:focus-visible {
        outline: 2px solid var(--color-brand-500, #b18f1f);
        outline-offset: 2px;
      }
    `,
  ],
})
export class SessionBannerComponent {
  private readonly auth = inject(AuthService);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  protected readonly visible = signal(false);
  protected readonly retrying = signal(false);

  constructor() {
    effect(onCleanup => {
      if (!this.isBrowser || this.auth.sessionStatus() !== 'unknown') {
        this.visible.set(false);
        return;
      }
      const timerId = setTimeout(() => this.visible.set(true), SESSION_BANNER_DELAY_MS);
      onCleanup(() => clearTimeout(timerId));
    });
  }

  /** Retry now (skips the remaining backoff); the banner hides on success. */
  protected async retry(): Promise<void> {
    if (this.retrying()) return;
    this.retrying.set(true);
    try {
      await this.auth.whenSessionKnown(SESSION_BANNER_RETRY_WAIT_MS);
    } finally {
      this.retrying.set(false);
    }
  }
}
