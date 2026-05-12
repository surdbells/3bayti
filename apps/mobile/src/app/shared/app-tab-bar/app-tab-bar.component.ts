import { Component, Input } from '@angular/core';
import { Router } from '@angular/router';
import { IonFooter, IonLabel, IonTabBar, IonTabButton } from '@ionic/angular/standalone';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../ax-mobile/icon';

/**
 * Shared bottom tab bar component (app-shell).
 *
 * Replaces the hand-rolled m6f-footer + ion-tab-bar markup that
 * was duplicated across 13 customer-side pages. Each consumer
 * just supplies the [active] tab name; routing is handled here.
 *
 * Usage:
 *   <app-tab-bar active="cart"></app-tab-bar>
 *   <app-tab-bar></app-tab-bar>            <!-- no tab highlighted -->
 *
 * The 'home' tab always navigates to /account (the customer
 * landing page). For account.page itself, the home tab does a
 * pull-to-refresh instead of routing — that page keeps its
 * footer inline rather than using this component.
 */
export type AppTabBarTab = 'home' | 'explore' | 'cart' | 'sketch' | 'profile';

@Component({
  selector: 'app-tab-bar',
  standalone: true,
  imports: [
    IonFooter,
    IonTabBar,
    IonTabButton,
    IonLabel,
    AxIconComponent,
    TranslatePipe,
  ],
  template: `
    <ion-footer class="m6f-footer ion-no-border">
      <ion-tab-bar class="m6f-tab-bar" slot="bottom">
        <ion-tab-button
          (click)="go('home')"
          tab="home"
          class="m6f-tab"
          [class.m6f-tab--active]="active === 'home'">
          <ax-icon name="house" />
          <ion-label>{{ 'nav_home' | translate }}</ion-label>
        </ion-tab-button>
        <ion-tab-button
          (click)="go('explore')"
          tab="explore"
          class="m6f-tab"
          [class.m6f-tab--active]="active === 'explore'">
          <ax-icon name="globe" />
          <ion-label>{{ 'explore' | translate }}</ion-label>
        </ion-tab-button>
        <ion-tab-button
          (click)="go('cart')"
          tab="cart"
          class="m6f-tab"
          [class.m6f-tab--active]="active === 'cart'">
          <ax-icon name="shopping-cart" />
          <ion-label>{{ 'title_my_cart' | translate }}</ion-label>
        </ion-tab-button>
        <ion-tab-button
          (click)="go('sketch')"
          tab="sketch"
          class="m6f-tab"
          [class.m6f-tab--active]="active === 'sketch'">
          <ax-icon name="blocks" />
          <ion-label>{{ 'nav_style_hub' | translate }}</ion-label>
        </ion-tab-button>
        <ion-tab-button
          (click)="go('profile')"
          tab="profile"
          class="m6f-tab"
          [class.m6f-tab--active]="active === 'profile'">
          <ax-icon name="user-pen" />
          <ion-label>{{ 'title_user_profile' | translate }}</ion-label>
        </ion-tab-button>
      </ion-tab-bar>
    </ion-footer>
  `,
})
export class AppTabBarComponent {
  /** Which tab to mark as active. Pass undefined for none. */
  @Input() active?: AppTabBarTab;

  /** Routes for each tab. Mirrors the per-page user_*() handlers
   *  that all 14 pages had duplicated.
   *
   *    home    -> /account (customer landing page)
   *    explore -> /explore
   *    cart    -> /cart
   *    sketch  -> /styles
   *    profile -> /settings
   */
  private static readonly ROUTES: Record<AppTabBarTab, string> = {
    home: '/account',
    explore: '/explore',
    cart: '/cart',
    sketch: '/styles',
    profile: '/settings',
  };

  constructor(private router: Router) {}

  go(tab: AppTabBarTab): void {
    const route = AppTabBarComponent.ROUTES[tab];
    this.router.navigate([route]);
  }
}
