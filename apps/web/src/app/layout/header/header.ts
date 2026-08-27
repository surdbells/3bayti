import {
  Component,
  ChangeDetectionStrategy,
  inject,
  computed,
  signal,
  viewChild,
  ElementRef,
  HostListener,
  DestroyRef,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import {
  NavigationEnd,
  Router,
  RouterLink,
  RouterLinkActive,
} from '@angular/router';
import { filter, map, startWith } from 'rxjs/operators';
import { DOCUMENT, NgIf } from '@angular/common';
import { TranslateModule } from '@ngx-translate/core';
import { LocaleSwitcherComponent } from './locale-switcher';
import { UserMenuComponent } from './user-menu';
import { CartIconComponent } from './cart-icon';
import { CurrencySwitcherComponent } from './currency-switcher';
import { SearchOverlayComponent } from '../../features/search/search-overlay';
import { AuthService } from '../../core/auth/auth.service';
import { SaleCountService } from '../../core/catalog/sale-count.service';

/** A single primary-navigation entry (shared by desktop nav + drawer). */
interface NavItem {
  /** Router path. */
  path: string;
  /** i18n key for the label. */
  labelKey: string;
  /** Stable slug used for the item's `data-testid` (e.g. 'categories'). */
  key: string;
}

/**
 * Site-wide header. Persistent across all pages, sticky to the viewport top.
 *
 * Auth-aware rendering
 * --------------------
 * Two visual states:
 *   1. Logged out → audience CTAs: a "Sign in" button (→ /login) and a
 *      "Sell on 3bayti" button (→ the in-app /sell recruitment pitch).
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
    SearchOverlayComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './header.html',
  styleUrl: './header.scss',
})
export class HeaderComponent {
  private readonly auth = inject(AuthService);
  private readonly doc = inject(DOCUMENT);
  private readonly router = inject(Router);
  /** On-sale product count for the Discounted nav badge (shared, loaded once). */
  protected readonly saleCount = inject(SaleCountService);

  /**
   * Primary navigation entries (H1.3). Order: Categories, Styles, Stores,
   * New In, Best Sellers, Gift Cards. Categories leads (browse-by-department
   * is the top of the IA); New In precedes Best Sellers so the freshest
   * catalogue reads first. Gift Cards links to the gift-card storefront.
   *
   * Text-only in the tidied nav (icons removed) — `key` survives purely as
   * the stable `data-testid` slug. The "Discounted" entry is rendered
   * separately (after a divider) so it reads as a distinct, accented item.
   */
  protected readonly navItems: readonly NavItem[] = [
    { path: '/category', labelKey: 'nav.categories', key: 'categories' },
    { path: '/styles', labelKey: 'nav.styles', key: 'styles' },
    { path: '/stores', labelKey: 'nav.stores', key: 'stores' },
    { path: '/new-arrivals', labelKey: 'nav.newArrivals', key: 'newArrivals' },
    { path: '/best-sellers', labelKey: 'nav.bestSellers', key: 'bestSellers' },
    { path: '/gift-cards', labelKey: 'nav.giftCards', key: 'gift' },
  ];

  /** Mobile drawer open state. */
  protected readonly drawerOpen = signal(false);

  /** Global search overlay open state (driven by the header search trigger). */
  protected readonly searchOpen = signal(false);

  /** True once the page has scrolled past the top — drives the condensed,
   *  elevated header + the slightly smaller logo. */
  protected readonly scrolled = signal(false);

  /**
   * Current router URL, ignoring matrix/query params + fragments. Seeded
   * with the router's current URL so the very first render (and SSR) gets
   * the right value without waiting for the first NavigationEnd.
   */
  private readonly currentUrl = toSignal(
    this.router.events.pipe(
      filter((e): e is NavigationEnd => e instanceof NavigationEnd),
      map((e) => e.urlAfterRedirects),
      startWith(this.router.url),
    ),
    { initialValue: this.router.url },
  );

  /**
   * True only on the home route ("/") — drives the FLOATING transparent
   * header that overlays the hero. On every other route the header stays
   * solid as before. Strips query/fragment so "/?ref=x" still counts as
   * home. When scrolled, `.is-scrolled` overrides back to the solid
   * blurred floating bar regardless of this flag (see header.scss).
   */
  protected readonly transparentOverHero = computed(() => {
    const url = this.currentUrl().split(/[?#]/)[0];
    return url === '/' || url === '';
  });

  /** rAF handle for the scroll listener so we coalesce bursts of scroll
   *  events into a single read-per-frame (passive + throttled). */
  private scrollRaf = 0;

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

  /** Open the global search overlay. */
  protected openSearch(): void {
    this.searchOpen.set(true);
  }

  /** Close the global search overlay. */
  protected closeSearch(): void {
    this.searchOpen.set(false);
  }

  /** Escape closes the drawer when open. */
  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    this.closeDrawer();
  }

  constructor() {
    // Passive + rAF-throttled scroll listener. Registered manually (rather
    // than via @HostListener) so we can mark it `{ passive: true }` — the
    // handler never calls preventDefault, so this lets the browser keep
    // scrolling on the compositor thread. Guarded behind DOCUMENT.defaultView
    // so it stays inert during SSR. Torn down on destroy.
    // Load the on-sale count for the Discounted nav badge (idempotent; the
    // shared service fetches at most once across all consumers).
    this.saleCount.load();

    const win = this.doc.defaultView;
    if (!win) return;

    const onScroll = (): void => {
      if (this.scrollRaf) return; // already a frame pending — coalesce.
      this.scrollRaf = win.requestAnimationFrame(() => {
        this.scrollRaf = 0;
        // >12px keeps the floating bar from flickering on tiny scrolls /
        // overscroll bounce while still condensing early.
        this.scrolled.set((win.scrollY ?? 0) > 12);
      });
    };

    win.addEventListener('scroll', onScroll, { passive: true });
    // Seed the initial state (e.g. a deep-link that restores scroll position).
    onScroll();

    inject(DestroyRef).onDestroy(() => {
      win.removeEventListener('scroll', onScroll);
      if (this.scrollRaf) win.cancelAnimationFrame(this.scrollRaf);
    });
  }
}
