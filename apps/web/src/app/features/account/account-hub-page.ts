import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  signal,
} from '@angular/core';
import { NgIf } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthService } from '../../core/auth/auth.service';

/**
 * /account — account hub / dashboard.
 *
 * Auth-gated (authActivateGuard on the route). A simple landing page
 * linking to every account surface: profile, orders, addresses,
 * measurements, password. The header user-menu's "My Account" link
 * has pointed here since the menu shipped (it 404'd until Y.5-A).
 *
 * Greeting uses the cached currentUser first name when available; the
 * page renders fine for a brief moment before hydration with a
 * generic greeting.
 */
@Component({
  selector: 'app-account-hub',
  standalone: true,
  imports: [NgIf, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="account-hub" data-testid="account-hub-page">
      <div class="account-hub__container">
        <div
          *ngIf="showVerifyBanner()"
          class="verify-reminder"
          role="status"
          data-testid="account-verify-banner"
        >
          <div class="verify-reminder__body">
            <span class="verify-reminder__title">{{ 'account.hub.verify.title' | translate }}</span>
            <span class="verify-reminder__desc">{{ 'account.hub.verify.desc' | translate }}</span>
          </div>
          <div class="verify-reminder__actions">
            <a
              routerLink="/verify-phone"
              [queryParams]="{ returnUrl: '/account' }"
              class="verify-reminder__cta"
              data-testid="account-verify-cta"
            >
              {{ 'account.hub.verify.cta' | translate }}
            </a>
            <button
              type="button"
              class="verify-reminder__dismiss"
              (click)="dismissVerifyBanner()"
              [attr.aria-label]="'common.dismiss' | translate"
              data-testid="account-verify-dismiss"
            >
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
        </div>

        <header class="account-hub__header">
          <h1 class="account-hub__title">
            <ng-container *ngIf="firstName() !== null; else genericGreeting">
              <span data-testid="greeting-named">{{ 'account.hub.greetingNamed' | translate: { name: firstName() } }}</span>
            </ng-container>
            <ng-template #genericGreeting>
              <span data-testid="greeting-generic">{{ 'account.hub.greeting' | translate }}</span>
            </ng-template>
          </h1>
          <p class="account-hub__subtitle">
            {{ 'account.hub.subtitle' | translate }}
          </p>
        </header>

        <nav class="account-hub__grid" [attr.aria-label]="'account.hub.sectionsAria' | translate">
          <a routerLink="/account/profile" class="account-tile" data-testid="account-tile-profile">
            <span class="account-tile__title">{{ 'account.hub.profile.title' | translate }}</span>
            <span class="account-tile__desc">{{ 'account.hub.profile.desc' | translate }}</span>
          </a>
          <a routerLink="/account/orders" class="account-tile" data-testid="account-tile-orders">
            <span class="account-tile__title">{{ 'account.hub.orders.title' | translate }}</span>
            <span class="account-tile__desc">{{ 'account.hub.orders.desc' | translate }}</span>
          </a>
          <a routerLink="/account/addresses" class="account-tile" data-testid="account-tile-addresses">
            <span class="account-tile__title">{{ 'account.hub.addresses.title' | translate }}</span>
            <span class="account-tile__desc">{{ 'account.hub.addresses.desc' | translate }}</span>
          </a>
          <a routerLink="/account/measurements" class="account-tile" data-testid="account-tile-measurements">
            <span class="account-tile__title">{{ 'account.hub.measurements.title' | translate }}</span>
            <span class="account-tile__desc">{{ 'account.hub.measurements.desc' | translate }}</span>
          </a>
          <a routerLink="/account/wishlist" class="account-tile" data-testid="account-tile-wishlist">
            <span class="account-tile__title">{{ 'account.hub.wishlist.title' | translate }}</span>
            <span class="account-tile__desc">{{ 'account.hub.wishlist.desc' | translate }}</span>
          </a>
          <a routerLink="/account/gift-cards" class="account-tile" data-testid="account-tile-gift-cards">
            <span class="account-tile__title">{{ 'account.hub.giftCards.title' | translate }}</span>
            <span class="account-tile__desc">{{ 'account.hub.giftCards.desc' | translate }}</span>
          </a>
          <a routerLink="/account/password" class="account-tile" data-testid="account-tile-password">
            <span class="account-tile__title">{{ 'account.hub.password.title' | translate }}</span>
            <span class="account-tile__desc">{{ 'account.hub.password.desc' | translate }}</span>
          </a>
        </nav>
      </div>
    </main>
  `,
  styleUrl: './account-hub.scss',
})
export class AccountHubPageComponent {
  private readonly auth = inject(AuthService);

  /** Banner dismissed for this view (resets on navigation/reload). */
  private readonly bannerDismissed = signal(false);

  /** First name from the cached user, or null for a generic greeting. */
  protected firstName(): string | null {
    const name = this.auth.currentUser()?.first_name ?? null;
    return name !== null && name.trim() !== '' ? name : null;
  }

  /**
   * Show the phone-verification reminder when the signed-in user hasn't
   * verified their phone and hasn't dismissed the banner this session.
   * Verification is required before placing an order.
   */
  protected readonly showVerifyBanner = computed(
    () =>
      this.auth.currentUser()?.is_phone_verified === false &&
      !this.bannerDismissed(),
  );

  protected dismissVerifyBanner(): void {
    this.bannerDismissed.set(true);
  }
}
