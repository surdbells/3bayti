import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  input,
  output,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../../core/auth/auth.service';
import { SocialAuthService, type SocialProvider } from '../../../core/auth/social-auth.service';
import { ToastService } from '../../../shared/forms';

/**
 * Reusable "Continue with Google / Apple" button row, shared by /login and
 * /register. Self-contained: drives the popup → BFF exchange via
 * AuthService.loginWithProvider, then either navigates (or, for a fresh
 * social account with an unverified phone, routes to the phone gate) or emits
 * an inline error.
 *
 * Hidden entirely when Firebase isn't configured (SocialAuthService.isAvailable
 * === false) so an un-configured build shows nothing rather than dead buttons.
 *
 * `returnUrl` (optional) is preserved across both the home navigation and the
 * phone-gate hop. The parent passes the page's safe returnUrl.
 *
 * a11y / RTL: a divider with a translated label, two buttons using the shared
 * .auth-* surface styling, logical-property spacing in the scss. Errors render
 * in a role="alert" block; a cancelled popup is silent.
 */
@Component({
  selector: 'app-social-auth-buttons',
  standalone: true,
  imports: [NgIf, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div *ngIf="available" class="social-auth" data-testid="social-auth">
      <div class="social-auth__divider" aria-hidden="true">
        <span class="social-auth__divider-label">{{ 'auth.social.dividerOr' | translate }}</span>
      </div>

      <button
        type="button"
        class="social-auth__button"
        [disabled]="busy() !== null"
        (click)="onProvider('google')"
        data-testid="social-google"
      >
        <span class="social-auth__icon social-auth__icon--google" aria-hidden="true"></span>
        {{ (busy() === 'google' ? 'common.loading' : 'auth.social.continueGoogle') | translate }}
      </button>

      <button
        type="button"
        class="social-auth__button"
        [disabled]="busy() !== null"
        (click)="onProvider('apple')"
        data-testid="social-apple"
      >
        <span class="social-auth__icon social-auth__icon--apple" aria-hidden="true"></span>
        {{ (busy() === 'apple' ? 'common.loading' : 'auth.social.continueApple') | translate }}
      </button>

      <p
        *ngIf="error() !== null"
        class="social-auth__error"
        role="alert"
        data-testid="social-error"
      >
        {{ error()! | translate }}
      </p>
    </div>
  `,
  styleUrl: './social-auth-buttons.scss',
})
export class SocialAuthButtonsComponent {
  /** Safe in-app path to return to after a successful social sign-in. */
  readonly returnUrl = input<string | null>(null);
  /** Emitted right before navigation, so a parent can do extra cleanup. */
  readonly signedIn = output<void>();

  private readonly auth = inject(AuthService);
  private readonly socialAuth = inject(SocialAuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  /** Whether to render at all (Firebase configured). */
  protected readonly available = this.socialAuth.isAvailable;

  /** Which provider is mid-flight (disables both buttons), or null. */
  protected readonly busy = signal<SocialProvider | null>(null);
  /** Inline error i18n key, or null. */
  protected readonly error = signal<string | null>(null);

  protected async onProvider(provider: SocialProvider): Promise<void> {
    if (this.busy() !== null) return;
    this.error.set(null);
    this.busy.set(provider);
    try {
      const result = await this.auth.loginWithProvider(provider);
      if (result.ok) {
        this.signedIn.emit();
        await this.navigateAfter(result.user.is_phone_verified);
        return;
      }
      /* A deliberately-cancelled popup is a normal outcome — stay silent. */
      if (result.reason === 'cancelled') return;
      this.error.set(this.errorKeyFor(result.reason));
    } finally {
      this.busy.set(null);
    }
  }

  /**
   * Fresh social accounts have is_phone_verified === false — gate them at
   * /verify-phone (the phone-after-social step) before the rest of the app.
   * Already-verified users go straight to the returnUrl / home.
   */
  private async navigateAfter(phoneVerified: boolean): Promise<void> {
    const target = this.safeReturn();
    if (!phoneVerified) {
      await this.router.navigate(['/verify-phone'], {
        queryParams: { from: 'social', returnUrl: target },
      });
      return;
    }
    await this.router.navigateByUrl(target);
  }

  private safeReturn(): string {
    const value = this.returnUrl();
    if (value === null || value.length === 0) return '/';
    if (value[0] !== '/') return '/';
    if (value.length > 1 && value[1] === '/') return '/';
    return value;
  }

  private errorKeyFor(reason: 'unavailable' | 'popup-blocked' | 'failed'): string {
    switch (reason) {
      case 'popup-blocked':
        return 'auth.social.errors.popupBlocked';
      case 'unavailable':
        return 'auth.social.errors.unavailable';
      default:
        return 'auth.social.errors.failed';
    }
  }
}
