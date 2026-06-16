import { Component, ChangeDetectionStrategy, inject, computed } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf } from '@angular/common';
import { TranslateModule } from '@ngx-translate/core';
import { LocaleSwitcherComponent } from './locale-switcher';
import { UserMenuComponent } from './user-menu';
import { CartIconComponent } from './cart-icon';
import { CurrencySwitcherComponent } from './currency-switcher';
import { AuthService } from '../../core/auth/auth.service';
import { VENDOR_APP_URL } from '../../core/auth/auth.tokens';

/**
 * Site-wide header. Persistent across all pages, sticky to the viewport top.
 *
 * Auth-aware rendering
 * --------------------
 * Two visual states:
 *   1. Logged out → audience CTAs: a "Customer" button (→ /login, which
 *      also routes to registration) and a "Vendor" button (→ the seller
 *      app at VENDOR_APP_URL, an external origin).
 *   2. Logged in → UserMenuComponent dropdown (name, account, orders,
 *      sign-out), plus the phone-verification badge when unverified.
 *
 * Bound directly to AuthService.currentUser + isAuthenticated signals;
 * no manual subscription teardown needed.
 */
@Component({
  selector: 'app-header',
  standalone: true,
  imports: [NgIf, RouterLink, TranslateModule, LocaleSwitcherComponent, UserMenuComponent, CartIconComponent, CurrencySwitcherComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './header.html',
  styleUrl: './header.scss',
})
export class HeaderComponent {
  private readonly auth = inject(AuthService);

  /** The seller app URL — the "Vendor" CTA target (external origin). */
  protected readonly vendorAppUrl = inject(VENDOR_APP_URL);

  /** Current user (or null when logged out) — for the user menu. */
  protected readonly currentUser = this.auth.currentUser;
  /** Authenticated state — for choosing which CTAs to render. */
  protected readonly isAuthenticated = this.auth.isAuthenticated;

  /** True when the logged-out audience CTAs should be visible. */
  protected readonly showLoggedOutCta = computed(() => !this.isAuthenticated());

  /**
   * True when a signed-in user still needs to verify their phone. Drives
   * the header reminder badge — phone verification is required before
   * placing an order (mirrored by the /account reminder + checkout gate).
   */
  protected readonly needsPhoneVerification = computed(
    () =>
      this.isAuthenticated() &&
      this.currentUser()?.is_phone_verified === false,
  );
}
