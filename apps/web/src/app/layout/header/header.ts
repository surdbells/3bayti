import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  signal,
  viewChild,
  ElementRef,
  HostListener,
} from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { DOCUMENT, NgIf } from '@angular/common';
import { TranslateModule } from '@ngx-translate/core';
import { LocaleSwitcherComponent } from './locale-switcher';
import { UserMenuComponent } from './user-menu';
import { CartIconComponent } from './cart-icon';
import { CurrencySwitcherComponent } from './currency-switcher';
import { NavIconComponent } from './nav-icon';
import { AuthService } from '../../core/auth/auth.service';
import { VENDOR_APP_URL } from '../../core/auth/auth.tokens';

/** A single primary-navigation entry (shared by desktop nav + drawer). */
interface NavItem {
  /** Router path. */
  path: string;
  /** i18n key for the label. */
  labelKey: string;
  /** NavIcon key. */
  icon: string;
}

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
 * Primary navigation
 * ------------------
 * A shared `navItems` list renders the desktop nav (inline, ≥900px) and
 * the mobile drawer (off-canvas, &lt;900px, opened via the hamburger). It
 * includes Gift Cards (Phase E), linking to the gift-card storefront.
 *
 * Bound directly to AuthService.currentUser + isAuthenticated signals;
 * no manual subscription teardown needed.
 */
@Component({
  selector: 'app-header',
  standalone: true,
  imports: [
    NgIf,
    RouterLink,
    RouterLinkActive,
    TranslateModule,
    LocaleSwitcherComponent,
    UserMenuComponent,
    CartIconComponent,
    CurrencySwitcherComponent,
    NavIconComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './header.html',
  styleUrl: './header.scss',
})
export class HeaderComponent {
  private readonly auth = inject(AuthService);
  private readonly doc = inject(DOCUMENT);

  /** The seller app URL — the "Vendor" CTA target (external origin). */
  protected readonly vendorAppUrl = inject(VENDOR_APP_URL);

  /**
   * Primary navigation entries (H1.3). Order: Categories, Stores, New In,
   * Best Sellers, Gift Cards. Categories leads (browse-by-department is the
   * top of the IA); New In precedes Best Sellers so the freshest catalogue
   * reads first. Gift Cards links to the gift-card storefront.
   */
  protected readonly navItems: readonly NavItem[] = [
    { path: '/category', labelKey: 'nav.categories', icon: 'categories' },
    { path: '/stores', labelKey: 'nav.stores', icon: 'stores' },
    { path: '/new-arrivals', labelKey: 'nav.newArrivals', icon: 'newArrivals' },
    { path: '/best-sellers', labelKey: 'nav.bestSellers', icon: 'bestSellers' },
    { path: '/gift-cards', labelKey: 'nav.giftCards', icon: 'gift' },
  ];

  /** Mobile drawer open state. */
  protected readonly drawerOpen = signal(false);

  /** Close button inside the drawer — focused when the drawer opens. */
  private readonly drawerCloseBtn = viewChild<ElementRef<HTMLButtonElement>>('drawerClose');

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

  /** Toggle the mobile drawer. */
  protected toggleDrawer(): void {
    this.drawerOpen() ? this.closeDrawer() : this.openDrawer();
  }

  /** Open the drawer, lock body scroll, and move focus to its close button. */
  protected openDrawer(): void {
    this.drawerOpen.set(true);
    this.doc.body.style.overflow = 'hidden';
    // Focus the close button once the drawer is interactive (inert removed).
    setTimeout(() => this.drawerCloseBtn()?.nativeElement?.focus(), 0);
  }

  /** Close the drawer and restore body scroll. */
  protected closeDrawer(): void {
    if (!this.drawerOpen()) return;
    this.drawerOpen.set(false);
    this.doc.body.style.overflow = '';
  }

  /** Escape closes the drawer when open. */
  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    this.closeDrawer();
  }
}
