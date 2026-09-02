import {
  Component,
  ChangeDetectionStrategy,
  computed,
  inject,
  signal,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../core/auth/auth.service';

/**
 * Session-only dismissal, shared across every instance (home + account) via a
 * module-level signal. Resets on a full page reload / re-open, so the prompt
 * returns next session until the user actually updates their email.
 */
const dismissed = signal(false);

/**
 * "Update your email" prompt.
 *
 * Shows for a signed-in user whose account email can't receive our
 * transactional mail (Apple private-relay / social-placeholder address) —
 * i.e. `needs_email_update === true`. The CTA routes to /account/profile with
 * `editEmail=1`, which auto-opens the inline email-change (OTP) flow.
 *
 * Rendered on the home page and the account hub. Renders nothing when the
 * condition isn't met, so it's safe to drop into any authed surface.
 */
@Component({
  selector: 'app-update-email-prompt',
  standalone: true,
  imports: [RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (show()) {
      <div class="update-email" role="status" data-testid="update-email-prompt">
        <span class="update-email__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="20" height="20">
            <path
              d="M4 6.5h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1Z"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linejoin="round"
            />
            <path
              d="m3.5 7.5 8.5 6 8.5-6"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
        <div class="update-email__body">
          <span class="update-email__title">{{ 'account.updateEmail.title' | translate }}</span>
          <span class="update-email__desc">{{ 'account.updateEmail.desc' | translate }}</span>
        </div>
        <div class="update-email__actions">
          <a
            routerLink="/account/profile"
            [queryParams]="{ editEmail: 1 }"
            class="update-email__cta"
            data-testid="update-email-cta"
          >
            {{ 'account.updateEmail.cta' | translate }}
          </a>
          <button
            type="button"
            class="update-email__dismiss"
            (click)="dismiss()"
            [attr.aria-label]="'common.dismiss' | translate"
            data-testid="update-email-dismiss"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      </div>
    }
  `,
  styles: [
    `
      .update-email {
        max-width: var(--page-max-width, 1280px);
        margin: 1.5rem auto;
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 20px;
        border-radius: 14px;
        background: var(--color-warning-bg, #fef3c7);
        border: 1px solid var(--color-warning-border, #fde68a);
      }
      .update-email__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        flex: 0 0 auto;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.65);
        color: var(--color-warning-dot, #d97706);
      }
      .update-email__body {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
        flex: 1 1 auto;
      }
      .update-email__title {
        font-weight: 700;
        font-size: 0.975rem;
        color: var(--color-warning-text, #92400e);
      }
      .update-email__desc {
        font-size: 0.875rem;
        color: var(--color-warning-text, #92400e);
        opacity: 0.85;
      }
      .update-email__actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 0 0 auto;
      }
      .update-email__cta {
        display: inline-flex;
        align-items: center;
        padding: 9px 16px;
        border-radius: 999px;
        font-size: 0.875rem;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        color: #fff;
        background: var(--color-warning-dot, #d97706);
        transition: background-color 0.15s ease;
      }
      .update-email__cta:hover,
      .update-email__cta:focus-visible {
        background: var(--color-warning-cta-hover, #b45309);
      }
      .update-email__dismiss {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border: none;
        border-radius: 999px;
        background: transparent;
        color: var(--color-warning-text, #92400e);
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
        transition: background-color 0.15s ease;
      }
      .update-email__dismiss:hover,
      .update-email__dismiss:focus-visible {
        background: rgba(146, 64, 14, 0.12);
      }
      @media (max-width: 560px) {
        .update-email {
          flex-wrap: wrap;
        }
        .update-email__actions {
          width: 100%;
          justify-content: space-between;
        }
        .update-email__cta {
          flex: 1 1 auto;
          justify-content: center;
        }
      }
    `,
  ],
})
export class UpdateEmailPromptComponent {
  private readonly auth = inject(AuthService);

  /** Show only for a signed-in user whose email can't receive our mail. */
  protected readonly show = computed(() => {
    const u = this.auth.currentUser();
    return u !== null && u.needs_email_update === true && !dismissed();
  });

  protected dismiss(): void {
    dismissed.set(true);
  }
}
