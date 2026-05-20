import { Component, ChangeDetectionStrategy, inject, computed } from '@angular/core';
import { RouterLink } from '@angular/router';
import { NgIf } from '@angular/common';
import { TranslateModule } from '@ngx-translate/core';
import { LocaleSwitcherComponent } from './locale-switcher';
import { UserMenuComponent } from './user-menu';
import { CartIconComponent } from './cart-icon';
import { AuthService } from '../../core/auth/auth.service';
import { FEATURE_AUTH_HEADER_CTA } from '../../core/auth/auth.tokens';

/**
 * Site-wide header. Persistent across all pages, sticky to the viewport top.
 *
 * Phase 1 (M3.2.0): brand mark + minimal nav placeholder.
 * Phase Y.1-A: + locale switcher (EN ⇄ AR).
 * Phase Y.1-I: + auth-aware CTAs.
 *
 * Auth-aware rendering
 * --------------------
 * Three visual states:
 *   1. Logged out, FEATURE_AUTH_HEADER_CTA=false (default) →
 *      no auth CTAs, just locale switcher. Y.2 will flip the flag
 *      once cart/checkout flows make sign-in genuinely useful.
 *
 *   2. Logged out, FEATURE_AUTH_HEADER_CTA=true →
 *      "Sign in" link + "Register" button.
 *
 *   3. Logged in →
 *      UserMenuComponent dropdown with name, account, orders, sign-out.
 *      The flag does NOT gate this state: a signed-in user always
 *      sees their session indicator. The flag is for the LOGGED-OUT
 *      affordance only.
 *
 * Bound directly to AuthService.currentUser + isAuthenticated signals;
 * no manual subscription teardown needed.
 */
@Component({
  selector: 'app-header',
  standalone: true,
  imports: [NgIf, RouterLink, TranslateModule, LocaleSwitcherComponent, UserMenuComponent, CartIconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './header.html',
  styleUrl: './header.scss',
})
export class HeaderComponent {
  private readonly auth = inject(AuthService);
  protected readonly featureCta = inject(FEATURE_AUTH_HEADER_CTA);

  /** Current user (or null when logged out) — for the user menu. */
  protected readonly currentUser = this.auth.currentUser;
  /** Authenticated state — for choosing which CTAs to render. */
  protected readonly isAuthenticated = this.auth.isAuthenticated;

  /** True when the logged-out CTAs should be visible. */
  protected readonly showLoggedOutCta = computed(
    () => !this.isAuthenticated() && this.featureCta,
  );
}
