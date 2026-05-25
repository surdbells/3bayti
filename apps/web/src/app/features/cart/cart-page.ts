import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { FormBuilder, FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CartService } from '../../core/cart';
import type { Cart, CartItem, CartQuoteResponse } from '../../core/cart';
import { ToastService } from '../../shared/forms';
import { AuthService } from '../../core/auth/auth.service';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';

/**
 * Cart page — `/cart`.
 *
 * Full cart view used by both guests and authenticated users.
 *
 * Sections
 * --------
 *   - Page heading + item count
 *   - Line item list (image + name + variants + qty stepper + remove)
 *   - Promo code field (auth-only; guests see a sign-in nudge)
 *   - Totals card (subtotal, shipping placeholder, total, checkout CTA)
 *   - Empty state when items.length === 0
 *
 * Promo code
 * ----------
 * For authenticated users only — the /v3/cart/quote endpoint requires
 * auth. Guests see a small "Sign in to apply a promo code" nudge in
 * place of the field. This avoids a confusing UX where the field is
 * visible but rejects every input.
 *
 * Quote state
 * -----------
 * We hold the latest CartQuoteResponse separately from the cart signal
 * so the breakdown (shipping, tax, discount, total) persists across
 * line-item changes. After any qty or remove, we re-quote with the
 * currently-applied promo code so the breakdown stays accurate.
 *
 * Quantity stepper
 * ----------------
 * +/- buttons and a numeric input. Min 1, max 99 (server-enforced too).
 * Each change calls CartService.updateQty which debounces locally per
 * line — adjacent rapid clicks coalesce into a single API call.
 *
 * a11y
 * ----
 *   - <h1> at top, <h2> per section
 *   - Qty stepper has aria-label per item
 *   - Remove button has aria-label including the product name
 *   - Error states use role='alert'
 */
@Component({
  selector: 'app-cart-page',
  standalone: true,
  imports: [CfImagePipe, NgIf, NgFor, ReactiveFormsModule, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="cart-page" data-testid="cart-page">
      <div class="cart-page__container">
        <h1 class="cart-page__title">
          {{ 'cart.page.title' | translate }}
          <span *ngIf="itemCount() > 0" class="cart-page__count">
            ({{ itemCount() }})
          </span>
        </h1>

        <ng-container *ngIf="items().length > 0; else emptyState">
          <div class="cart-page__layout">
            <section class="cart-page__items" aria-labelledby="cart-items-heading">
              <h2 id="cart-items-heading" class="visually-hidden">
                {{ 'cart.page.itemsHeading' | translate }}
              </h2>
              <ul class="cart-page__item-list" role="list">
                <li
                  *ngFor="let item of items(); trackBy: trackById"
                  class="cart-page__item"
                  data-testid="cart-page-item"
                >
                  <div class="cart-page__item-thumb">
                    <img
                      *ngIf="item.product_image !== ''; else thumbPlaceholder"
                      [src]="item.product_image | cfImage:'thumb'"
                      [alt]="''"
                      loading="lazy"
                    />
                    <ng-template #thumbPlaceholder>
                      <div class="cart-page__thumb-placeholder" aria-hidden="true"></div>
                    </ng-template>
                  </div>

                  <div class="cart-page__item-body">
                    <p class="cart-page__item-name">
                      {{ item.product_name || ('cart.drawer.unnamedItem' | translate) }}
                    </p>
                    <p
                      *ngIf="item.size !== null || item.color !== null"
                      class="cart-page__item-variant"
                    >
                      <ng-container *ngIf="item.size !== null">
                        {{ 'cart.page.size' | translate }}: {{ item.size }}
                      </ng-container>
                      <ng-container *ngIf="item.size !== null && item.color !== null"> · </ng-container>
                      <ng-container *ngIf="item.color !== null">
                        {{ 'cart.page.color' | translate }}: {{ item.color }}
                      </ng-container>
                    </p>
                    <p *ngIf="item.is_custom" class="cart-page__item-custom">
                      {{ 'cart.page.customMade' | translate }}
                    </p>

                    <div class="cart-page__item-controls">
                      <div class="qty-stepper" role="group" [attr.aria-label]="('cart.page.qtyAriaFor' | translate) + ' ' + item.product_name">
                        <button
                          type="button"
                          class="qty-stepper__btn"
                          [attr.aria-label]="'cart.page.decreaseQty' | translate"
                          [disabled]="item.quantity <= 1 || isLoading()"
                          (click)="onDecrement(item)"
                          [attr.data-testid]="'cart-qty-dec-' + item.id"
                        >
                          −
                        </button>
                        <span
                          class="qty-stepper__value"
                          aria-live="polite"
                          [attr.data-testid]="'cart-qty-value-' + item.id"
                        >
                          {{ item.quantity }}
                        </span>
                        <button
                          type="button"
                          class="qty-stepper__btn"
                          [attr.aria-label]="'cart.page.increaseQty' | translate"
                          [disabled]="item.quantity >= 99 || isLoading()"
                          (click)="onIncrement(item)"
                          [attr.data-testid]="'cart-qty-inc-' + item.id"
                        >
                          +
                        </button>
                      </div>

                      <button
                        type="button"
                        class="cart-page__item-remove"
                        [attr.aria-label]="('cart.drawer.removeAria' | translate : { name: item.product_name })"
                        [disabled]="isLoading()"
                        (click)="onRemove(item)"
                        [attr.data-testid]="'cart-remove-' + item.id"
                      >
                        {{ 'cart.drawer.remove' | translate }}
                      </button>
                    </div>
                  </div>

                  <div class="cart-page__item-price">
                    <p class="cart-page__item-price-line">
                      {{ currency() }} {{ item.line_subtotal }}
                    </p>
                    <p
                      *ngIf="item.quantity > 1"
                      class="cart-page__item-unit-price"
                    >
                      {{ currency() }} {{ item.unit_price }} {{ 'cart.page.each' | translate }}
                    </p>
                  </div>
                </li>
              </ul>
            </section>

            <aside class="cart-page__sidebar" aria-labelledby="cart-summary-heading">
              <div class="cart-page__summary">
                <h2 id="cart-summary-heading" class="cart-page__summary-title">
                  {{ 'cart.page.summaryTitle' | translate }}
                </h2>

                <ng-container *ngIf="isAuthenticated(); else guestPromo">
                  <form
                    [formGroup]="promoForm"
                    (ngSubmit)="onApplyPromo()"
                    class="cart-page__promo"
                    data-testid="cart-promo-form"
                  >
                    <label for="promo-code" class="cart-page__promo-label">
                      {{ 'cart.page.promoLabel' | translate }}
                    </label>
                    <div class="cart-page__promo-row">
                      <input
                        id="promo-code"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        formControlName="code"
                        class="cart-page__promo-input"
                        [placeholder]="'cart.page.promoPlaceholder' | translate"
                        data-testid="cart-promo-input"
                      />
                      <button
                        type="submit"
                        class="cart-page__promo-btn"
                        [disabled]="promoForm.invalid || isLoading()"
                        data-testid="cart-promo-apply"
                      >
                        {{ 'cart.page.promoApply' | translate }}
                      </button>
                    </div>
                    <p
                      *ngIf="appliedPromo() !== null && promoStatus() !== null"
                      class="cart-page__promo-status"
                      [class.cart-page__promo-status--valid]="promoStatus() === 'valid'"
                      [class.cart-page__promo-status--invalid]="promoStatus() === 'invalid'"
                      role="status"
                      data-testid="cart-promo-status"
                    >
                      {{ promoStatusKey() | translate : { code: appliedPromo() } }}
                    </p>
                  </form>
                </ng-container>

                <ng-template #guestPromo>
                  <p class="cart-page__guest-promo" data-testid="cart-guest-promo">
                    <a routerLink="/login" [queryParams]="{ returnUrl: '/cart' }" class="cart-page__guest-promo-link">
                      {{ 'cart.page.guestPromoSignInLink' | translate }}
                    </a>
                    {{ 'cart.page.guestPromoSuffix' | translate }}
                  </p>
                </ng-template>

                <dl class="cart-page__totals">
                  <div class="cart-page__totals-row">
                    <dt>{{ 'cart.page.subtotal' | translate }}</dt>
                    <dd data-testid="cart-summary-subtotal">{{ currency() }} {{ displaySubtotal() }}</dd>
                  </div>
                  <div *ngIf="hasQuote()" class="cart-page__totals-row">
                    <dt>{{ 'cart.page.shipping' | translate }}</dt>
                    <dd data-testid="cart-summary-shipping">
                      {{ currency() }} {{ quoteBreakdown()?.shipping }}
                    </dd>
                  </div>
                  <div *ngIf="hasQuote()" class="cart-page__totals-row">
                    <dt>{{ 'cart.page.tax' | translate }}</dt>
                    <dd data-testid="cart-summary-tax">
                      {{ currency() }} {{ quoteBreakdown()?.tax }}
                    </dd>
                  </div>
                  <div
                    *ngIf="hasQuote() && quoteHasDiscount()"
                    class="cart-page__totals-row cart-page__totals-row--discount"
                  >
                    <dt>{{ 'cart.page.discount' | translate }}</dt>
                    <dd data-testid="cart-summary-discount">
                      −{{ currency() }} {{ quoteBreakdown()?.promo_discount }}
                    </dd>
                  </div>
                  <div class="cart-page__totals-row cart-page__totals-row--total">
                    <dt>{{ 'cart.page.total' | translate }}</dt>
                    <dd data-testid="cart-summary-total">{{ currency() }} {{ displayTotal() }}</dd>
                  </div>
                </dl>

                <p *ngIf="!hasQuote()" class="cart-page__shipping-note">
                  {{ 'cart.drawer.shippingNote' | translate }}
                </p>

                <button
                  type="button"
                  class="cart-page__checkout"
                  [disabled]="isLoading()"
                  (click)="onCheckout()"
                  data-testid="cart-checkout-cta"
                >
                  {{ 'cart.page.checkout' | translate }}
                </button>

                <a routerLink="/category" class="cart-page__continue">
                  {{ 'cart.page.continueShopping' | translate }}
                </a>
              </div>
            </aside>
          </div>
        </ng-container>

        <ng-template #emptyState>
          <div class="cart-page__empty" data-testid="cart-page-empty">
            <h2 class="cart-page__empty-title">
              {{ 'cart.page.empty.title' | translate }}
            </h2>
            <p class="cart-page__empty-body">
              {{ 'cart.page.empty.body' | translate }}
            </p>
            <a routerLink="/category" class="cart-page__empty-cta">
              {{ 'cart.page.empty.cta' | translate }}
            </a>
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './cart-page.scss',
})
export class CartPageComponent implements OnInit {
  private readonly cart = inject(CartService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  protected readonly items = computed<CartItem[]>(() => this.cart.cart().items);
  protected readonly itemCount = this.cart.itemCount;
  protected readonly currency = this.cart.currency;
  protected readonly subtotal = this.cart.subtotal;
  protected readonly isLoading = this.cart.isLoading;
  protected readonly isAuthenticated = this.auth.isAuthenticated;

  /** Latest quote response — drives the breakdown when present. */
  private readonly _quote = signal<CartQuoteResponse | null>(null);
  protected readonly quoteBreakdown = computed(() => this._quote()?.breakdown ?? null);
  protected readonly hasQuote = computed(() => this._quote() !== null);
  protected readonly quoteHasDiscount = computed(() => {
    const d = this._quote()?.breakdown.promo_discount;
    return d !== undefined && parseFloat(d) > 0;
  });

  /** Currently applied promo code (if any). */
  protected readonly appliedPromo = computed(() => this._quote()?.promo_code ?? null);

  /** 'valid' | 'invalid' | null. Drives the styled status message. */
  protected readonly promoStatus = computed(() => {
    const q = this._quote();
    if (q === null || q.promo_code === null) return null;
    return q.promo_valid ? 'valid' : 'invalid';
  });

  protected readonly promoStatusKey = computed(() => {
    return this.promoStatus() === 'valid'
      ? 'cart.page.promoApplied'
      : 'cart.page.promoInvalid';
  });

  /** Display subtotal: prefer the quote's subtotal (server-authoritative)
   *  over the cart's locally computed subtotal when a quote exists. */
  protected readonly displaySubtotal = computed(() => {
    return this._quote()?.breakdown.subtotal ?? this.subtotal();
  });

  /** Display total: from quote breakdown when present; otherwise just
   *  the subtotal (shipping + tax appear at checkout). */
  protected readonly displayTotal = computed(() => {
    return this._quote()?.breakdown.total ?? this.subtotal();
  });

  protected readonly promoForm: FormGroup<{ code: FormControl<string> }>;

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.promoForm = fb.group({
      code: fb.control('', [Validators.required, Validators.minLength(2)]),
    });
  }

  ngOnInit(): void {
    /* Pull a fresh cart on landing. For guests this is a no-op (already
       in-signal from CartService construction); for authed users it
       picks up any cross-device updates. */
    void this.cart.refresh();
  }

  protected trackById(_idx: number, item: CartItem): number {
    return item.id;
  }

  /* -----------------------------------------------------------------
     Quantity controls
     ----------------------------------------------------------------- */

  protected async onIncrement(item: CartItem): Promise<void> {
    if (item.quantity >= 99) return;
    await this.updateQty(item, item.quantity + 1);
  }

  protected async onDecrement(item: CartItem): Promise<void> {
    if (item.quantity <= 1) return;
    await this.updateQty(item, item.quantity - 1);
  }

  private async updateQty(item: CartItem, qty: number): Promise<void> {
    try {
      await this.cart.updateQty(item.id, qty);
      /* After a quantity change, re-quote (auth only) with the active
         promo so the breakdown stays accurate. Silently no-op on
         failure — the toast UX would be noisy. */
      await this.refreshQuote();
    } catch {
      this.toast.error('cart.page.errors.updateFailed');
    }
  }

  /* -----------------------------------------------------------------
     Remove
     ----------------------------------------------------------------- */

  protected async onRemove(item: CartItem): Promise<void> {
    try {
      await this.cart.removeItem(item.id);
      await this.refreshQuote();
    } catch {
      this.toast.error('cart.drawer.errors.removeFailed');
    }
  }

  /* -----------------------------------------------------------------
     Promo code
     ----------------------------------------------------------------- */

  protected async onApplyPromo(): Promise<void> {
    this.promoForm.markAllAsTouched();
    if (this.promoForm.invalid || this.isLoading()) return;

    try {
      const quote = await this.cart.quoteWithPromo(this.promoForm.controls.code.value.trim());
      this._quote.set(quote);
      if (!quote.promo_valid) {
        /* The status pill already shows the invalid state; no toast
           needed. */
      }
    } catch {
      this.toast.error('cart.page.errors.promoFailed');
    }
  }

  /**
   * Re-quote with the currently-applied promo code (if any).
   * Auth-only path; silently no-op for guests.
   */
  private async refreshQuote(): Promise<void> {
    if (!this.isAuthenticated()) return;
    const code = this.appliedPromo();
    if (code === null) return;
    try {
      const quote = await this.cart.quoteWithPromo(code);
      this._quote.set(quote);
    } catch {
      /* Silently drop the stale quote rather than confuse the user.
         The breakdown disappears; the basic subtotal still renders. */
      this._quote.set(null);
    }
  }

  /* -----------------------------------------------------------------
     Checkout
     ----------------------------------------------------------------- */

  protected async onCheckout(): Promise<void> {
    if (!this.isAuthenticated()) {
      /* Guests must sign in before checkout (Q-CheckoutAuth=A). Carry
         the returnUrl so they land back on /checkout/address. */
      await this.router.navigate(['/login'], {
        queryParams: { returnUrl: '/checkout/address' },
      });
      return;
    }
    await this.router.navigateByUrl('/checkout/address');
  }
}
