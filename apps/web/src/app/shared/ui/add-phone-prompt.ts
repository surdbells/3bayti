import {
  Component,
  ChangeDetectionStrategy,
  computed,
  inject,
  signal,
} from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../core/auth/auth.service';

/**
 * Session-only dismissal, shared across every instance (home + account) via a
 * module-level signal. It resets on a full page reload / app re-open, so the
 * prompt returns next session until the user actually adds a phone.
 */
const dismissed = signal(false);

/**
 * "Add your phone number" prompt.
 *
 * Shows for a signed-in user who has NO phone on file and hasn't verified one
 * (the phone-after-social case: a Google/Apple sign-up arrives with phone === ''
 * and is_phone_verified === false). The CTA routes to the /verify-phone page in
 * its phone-after-social mode (from=social), which collects + OTP-verifies a
 * number via PhoneService (POST /me/phone → /me/phone/verify).
 *
 * Rendered on the home page and the account hub. Renders nothing when the
 * condition isn't met, so it's safe to drop into any authed surface.
 */
@Component({
  selector: 'app-add-phone-prompt',
  standalone: true,
  imports: [RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (show()) {
      <div class="add-phone" role="status" data-testid="add-phone-prompt">
        <span class="add-phone__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="20" height="20">
            <path
              d="M15.5 3.5h-7a1.5 1.5 0 0 0-1.5 1.5v14a1.5 1.5 0 0 0 1.5 1.5h7a1.5 1.5 0 0 0 1.5-1.5V5a1.5 1.5 0 0 0-1.5-1.5Zm-3.5 15.5h.01M9.5 5.5h5"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
            <path
              d="M19 3.5v4M17 5.5h4"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
            />
          </svg>
        </span>
        <div class="add-phone__body">
          <span class="add-phone__title">{{ 'account.addPhone.title' | translate }}</span>
          <span class="add-phone__desc">{{ 'account.addPhone.desc' | translate }}</span>
        </div>
        <div class="add-phone__actions">
          <a
            routerLink="/verify-phone"
            [queryParams]="{ from: 'social', returnUrl: returnUrl() }"
            class="add-phone__cta"
            data-testid="add-phone-cta"
          >
            {{ 'account.addPhone.cta' | translate }}
          </a>
          <button
            type="button"
            class="add-phone__dismiss"
            (click)="dismiss()"
            [attr.aria-label]="'common.dismiss' | translate"
            data-testid="add-phone-dismiss"
          >
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      </div>
    }
  `,
  styles: [
    `
      .add-phone {
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
      .add-phone__icon {
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
      .add-phone__body {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
        flex: 1 1 auto;
      }
      .add-phone__title {
        font-weight: 700;
        font-size: 0.975rem;
        color: var(--color-warning-text, #92400e);
      }
      .add-phone__desc {
        font-size: 0.875rem;
        color: var(--color-warning-text, #92400e);
        opacity: 0.85;
      }
      .add-phone__actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 0 0 auto;
      }
      .add-phone__cta {
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
      .add-phone__cta:hover,
      .add-phone__cta:focus-visible {
        background: var(--color-warning-cta-hover, #b45309);
      }
      .add-phone__dismiss {
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
      .add-phone__dismiss:hover,
      .add-phone__dismiss:focus-visible {
        background: rgba(146, 64, 14, 0.12);
      }
      @media (max-width: 560px) {
        .add-phone {
          flex-wrap: wrap;
        }
        .add-phone__actions {
          width: 100%;
          justify-content: space-between;
        }
        .add-phone__cta {
          flex: 1 1 auto;
          justify-content: center;
        }
      }
    `,
  ],
})
export class AddPhonePromptComponent {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

  /**
   * Show only for a signed-in user with no phone on file and no verified phone.
   * `!u.phone` covers both '' (the social-signup default) and any null. Users
   * who HAVE an unverified phone are handled by the account-hub verify banner.
   */
  protected readonly show = computed(() => {
    const u = this.auth.currentUser();
    return u !== null && !u.phone && u.is_phone_verified === false && !dismissed();
  });

  /** Come back here after verifying — the current page, sans query string. */
  protected returnUrl(): string {
    const url = this.router.url.split('?')[0];
    return url.startsWith('/') ? url : '/';
  }

  protected dismiss(): void {
    dismissed.set(true);
  }
}
