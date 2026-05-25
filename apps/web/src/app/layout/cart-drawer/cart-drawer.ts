import {
  Component,
  ChangeDetectionStrategy,
  inject,
  effect,
  ElementRef,
  ViewChild,
  AfterViewInit,
  signal,
  computed,
  HostListener,
} from '@angular/core';
import { NgIf, NgFor, isPlatformBrowser } from '@angular/common';
import { PLATFORM_ID } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CartService, CartDrawerService } from '../../core/cart';
import type { CartItem } from '../../core/cart';
import { ToastService } from '../../shared/forms';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';

/**
 * CartDrawerComponent — slide-out panel showing the user's cart.
 *
 * Mounted once at the app shell (sibling to <router-outlet>). The
 * CartIconComponent's click toggles open/close via CartDrawerService.
 *
 * UX
 * --
 *   - Slides in from the inline-end edge (right in LTR, left in RTL)
 *   - Backdrop dims the rest of the page; click-to-dismiss
 *   - Sticky footer with "View cart" + "Checkout" CTAs
 *   - Empty state: friendly message + "Continue shopping" link
 *   - When CartDrawerService.openWithHighlight(itemId) was used, that
 *     line gets a subtle attention pulse
 *
 * a11y
 * ----
 *   - role="dialog" + aria-modal="true"
 *   - aria-labelledby points to the heading
 *   - Focus moves to the close button on open
 *   - Focus is restored to the element that opened the drawer on close
 *     (we ask document.activeElement to refocus — works for the cart
 *     icon and most other triggers)
 *   - Escape closes the drawer
 *   - Focus trap keeps tab within the panel while open
 *
 * SSR
 * ---
 *   - Renders nothing on the server (drawer state is browser-only)
 */
@Component({
  selector: 'app-cart-drawer',
  standalone: true,
  imports: [CfImagePipe, NgIf, NgFor, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ng-container *ngIf="isBrowser">
      <div
        *ngIf="isOpen()"
        class="cart-drawer__backdrop"
        (click)="close()"
        data-testid="cart-drawer-backdrop"
      ></div>
      <aside
        *ngIf="isOpen()"
        #panel
        class="cart-drawer__panel"
        role="dialog"
        aria-modal="true"
        [attr.aria-labelledby]="headingId"
        data-testid="cart-drawer"
        (keydown)="onPanelKeydown($event)"
      >
        <header class="cart-drawer__header">
          <h2 [id]="headingId" class="cart-drawer__title">
            {{ 'cart.drawer.title' | translate }}
          </h2>
          <button
            #closeBtn
            type="button"
            class="cart-drawer__close"
            [attr.aria-label]="'common.close' | translate"
            (click)="close()"
            data-testid="cart-drawer-close"
          >
            ×
          </button>
        </header>

        <div class="cart-drawer__body">
          <ng-container *ngIf="items().length > 0; else emptyState">
            <ul class="cart-drawer__items" role="list">
              <li
                *ngFor="let item of items(); trackBy: trackById"
                class="cart-drawer__item"
                [class.cart-drawer__item--highlighted]="item.id === highlightedItemId()"
                data-testid="cart-drawer-item"
              >
                <div class="cart-drawer__item-thumb" aria-hidden="true">
                  <img
                    *ngIf="item.product_image !== ''; else thumbPlaceholder"
                    [src]="item.product_image | cfImage:'thumb'"
                    [alt]="''"
                    loading="lazy"
                  />
                  <ng-template #thumbPlaceholder>
                    <div class="cart-drawer__thumb-placeholder"></div>
                  </ng-template>
                </div>
                <div class="cart-drawer__item-info">
                  <p class="cart-drawer__item-name">
                    {{ item.product_name || ('cart.drawer.unnamedItem' | translate) }}
                  </p>
                  <p *ngIf="item.size !== null || item.color !== null" class="cart-drawer__item-variant">
                    <ng-container *ngIf="item.size !== null">{{ item.size }}</ng-container>
                    <ng-container *ngIf="item.size !== null && item.color !== null"> · </ng-container>
                    <ng-container *ngIf="item.color !== null">{{ item.color }}</ng-container>
                  </p>
                  <div class="cart-drawer__item-row">
                    <span class="cart-drawer__item-qty">
                      {{ 'cart.drawer.qtyLabel' | translate }}: {{ item.quantity }}
                    </span>
                    <button
                      type="button"
                      class="cart-drawer__item-remove"
                      (click)="onRemove(item.id)"
                      [attr.aria-label]="'cart.drawer.removeAria' | translate : { name: item.product_name }"
                      data-testid="cart-drawer-remove"
                    >
                      {{ 'cart.drawer.remove' | translate }}
                    </button>
                  </div>
                </div>
                <div class="cart-drawer__item-price">
                  {{ item.line_subtotal }}
                </div>
              </li>
            </ul>
          </ng-container>

          <ng-template #emptyState>
            <div class="cart-drawer__empty" data-testid="cart-drawer-empty">
              <p class="cart-drawer__empty-title">
                {{ 'cart.drawer.empty.title' | translate }}
              </p>
              <p class="cart-drawer__empty-body">
                {{ 'cart.drawer.empty.body' | translate }}
              </p>
              <a
                routerLink="/category"
                class="cart-drawer__empty-cta"
                (click)="close()"
              >
                {{ 'cart.drawer.empty.cta' | translate }}
              </a>
            </div>
          </ng-template>
        </div>

        <footer *ngIf="items().length > 0" class="cart-drawer__footer">
          <div class="cart-drawer__totals">
            <span class="cart-drawer__totals-label">
              {{ 'cart.drawer.subtotal' | translate }}
            </span>
            <span class="cart-drawer__totals-amount">
              {{ currency() }} {{ subtotal() }}
            </span>
          </div>
          <p class="cart-drawer__shipping-note">
            {{ 'cart.drawer.shippingNote' | translate }}
          </p>
          <div class="cart-drawer__actions">
            <a
              routerLink="/cart"
              class="cart-drawer__cta cart-drawer__cta--secondary"
              (click)="close()"
              data-testid="cart-drawer-view"
            >
              {{ 'cart.drawer.viewCart' | translate }}
            </a>
            <button
              #checkoutBtn
              type="button"
              class="cart-drawer__cta cart-drawer__cta--primary"
              (click)="onCheckout()"
              data-testid="cart-drawer-checkout"
            >
              {{ 'cart.drawer.checkout' | translate }}
            </button>
          </div>
        </footer>
      </aside>
    </ng-container>
  `,
  styleUrl: './cart-drawer.scss',
})
export class CartDrawerComponent implements AfterViewInit {
  @ViewChild('panel') protected panelRef?: ElementRef<HTMLElement>;
  @ViewChild('closeBtn') protected closeBtnRef?: ElementRef<HTMLButtonElement>;

  protected readonly headingId = 'cart-drawer-heading';

  private readonly cart = inject(CartService);
  private readonly drawer = inject(CartDrawerService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private readonly platformId = inject(PLATFORM_ID);

  protected readonly isBrowser = isPlatformBrowser(this.platformId);
  protected readonly isOpen = this.drawer.isOpen;
  protected readonly highlightedItemId = this.drawer.highlightedItemId;
  protected readonly items = computed<CartItem[]>(() => this.cart.cart().items);
  protected readonly subtotal = this.cart.subtotal;
  protected readonly currency = this.cart.currency;

  /** Element that had focus when the drawer opened; we restore to it on close. */
  private previousActiveElement: HTMLElement | null = null;

  constructor() {
    /* Track open transitions so we can move focus + restore. */
    if (this.isBrowser) {
      effect(() => {
        const open = this.drawer.isOpen();
        if (open) {
          /* Capture the element that had focus BEFORE the panel mounts.
             Using queueMicrotask defers the read until Angular's
             change-detection cycle hasn't yet swapped focus. */
          this.previousActiveElement = (document.activeElement as HTMLElement | null) ?? null;
          /* Move focus to the close button on next microtask so the
             ViewChild has resolved. */
          queueMicrotask(() => this.closeBtnRef?.nativeElement.focus());
        } else if (this.previousActiveElement !== null) {
          /* Restore focus on close. */
          const prev = this.previousActiveElement;
          this.previousActiveElement = null;
          /* Defer so any pending DOM teardown completes first. */
          queueMicrotask(() => prev.focus({ preventScroll: true }));
        }
      });
    }
  }

  ngAfterViewInit(): void {
    /* Nothing to do — the effect handles focus on every open. */
  }

  protected close(): void {
    this.drawer.close();
  }

  protected async onRemove(itemId: number): Promise<void> {
    try {
      await this.cart.removeItem(itemId);
    } catch (err) {
      if (typeof console !== 'undefined') console.warn('[CartDrawer] remove failed', err);
      this.toast.error('cart.drawer.errors.removeFailed');
    }
  }

  protected async onCheckout(): Promise<void> {
    this.close();
    /* Authenticated → /checkout/address; guest → /login?returnUrl=/checkout/address.
       AuthService.isAuthenticated check belongs here rather than on
       the link so RouterLink doesn't render an anchor that could be
       middle-clicked into the wrong page. */
    await this.router.navigateByUrl('/checkout/address');
  }

  protected trackById(_index: number, item: CartItem): number {
    return item.id;
  }

  /**
   * Focus-trap + Escape handling on the panel.
   *
   * - Escape → close
   * - Tab from last focusable → wrap to first
   * - Shift+Tab from first focusable → wrap to last
   */
  protected onPanelKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.close();
      return;
    }
    if (event.key !== 'Tab') return;

    const panel = this.panelRef?.nativeElement;
    if (panel === undefined) return;
    const focusables = panel.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
    );
    if (focusables.length === 0) return;
    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && active === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  /** Global Escape catcher in case keydown lands outside the panel. */
  @HostListener('document:keydown.escape')
  protected onEscapeDocument(): void {
    if (this.isOpen()) this.close();
  }
}
