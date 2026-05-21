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
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CheckoutStepperComponent } from './checkout-stepper';
import { CartService } from '../../core/cart';
import type { CartItem, CartQuoteResponse } from '../../core/cart';
import { CheckoutService } from '../../core/checkout';
import { AddressService } from '../../core/addresses';
import type { Address } from '../../core/addresses';
import { ToastService } from '../../shared/forms';
import { CurrencyService } from '../../core/currency/currency.service';

/**
 * /checkout/review — step 2 of 3.
 *
 * Final review before payment handoff.
 *
 * Layout
 * ------
 *   - Stepper at top (activeStep=1)
 *   - "Delivery to" card showing the selected shipping address
 *     (with an inline link back to /checkout/address to change)
 *   - Items list — same line-item shape as the cart drawer, but
 *     read-only (no qty adjusters here; that's the cart page's job)
 *   - Promo code input — pre-populated from CheckoutService if a
 *     code was carried in. Apply → /v3/cart/quote; result drives
 *     the totals breakdown. Remove → unset code, refresh quote with
 *     null.
 *   - Totals: subtotal, shipping, tax, discount (when applicable),
 *     total
 *   - Back / Place order action bar
 *
 * Bounce guards
 * -------------
 * Several reasons we'd want to bounce out:
 *   - cart empty → /cart
 *   - no shipping address chosen in CheckoutService → /checkout/address
 * Both checked in ngOnInit.
 *
 * Initial quote
 * -------------
 * On landing we call quoteWithPromo(promoFromState) to populate the
 * breakdown. If that promo turns invalid (e.g. expired since /cart),
 * we silently clear the code and re-quote with null. The user can
 * apply a different code from here.
 *
 * Place order
 * -----------
 * On click:
 *   1. Mark form as touched (no-op if no errors)
 *   2. POST /v3/checkout/initiate with the resolved breakdown +
 *      addresses + promo code
 *   3. CheckoutService.clear() to reset the in-progress state
 *   4. Hand off to /checkout/payment with the returned checkout_url
 *      passed via router state. (We don't use a query param because
 *      the URL could be reflected back into a phishing context.)
 *
 * Failures
 * --------
 *   - 422 with VALIDATION_FAILED → mapped via the toast
 *     (the inputs here are server-derived; user can't fix from form)
 *   - PROMO_INVALID → clear local promo, re-quote without it,
 *     show the invalid status
 *   - Network errors → toast checkout.errors.networkError
 *   - Any other → toast checkout.errors.initiateFailed
 */
@Component({
  selector: 'app-checkout-review',
  standalone: true,
  imports: [NgIf, NgFor, ReactiveFormsModule, TranslatePipe, CheckoutStepperComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="checkout-page" data-testid="checkout-review-page">
      <div class="checkout-page__container">
        <h1 class="checkout-page__title">
          {{ 'checkout.review.title' | translate }}
        </h1>

        <app-checkout-stepper [activeStep]="1"></app-checkout-stepper>

        <section
          *ngIf="shippingAddress() !== null"
          class="checkout-page__section"
          aria-labelledby="shipping-heading"
        >
          <div class="review-section-header">
            <h2 id="shipping-heading" class="checkout-page__section-title">
              {{ 'checkout.review.shippingHeading' | translate }}
            </h2>
            <button
              type="button"
              class="review-section-header__edit"
              (click)="onEditShipping()"
              data-testid="review-edit-shipping"
            >
              {{ 'addresses.actions.edit' | translate }}
            </button>
          </div>
          <p class="review-address__name">{{ shippingAddress()!.recipient_name }}</p>
          <p class="review-address__line">
            <ng-container *ngIf="shippingAddress()!.street_address !== null">
              {{ shippingAddress()!.street_address }},
            </ng-container>
            <ng-container *ngIf="shippingAddress()!.building_details !== null">
              {{ shippingAddress()!.building_details }},
            </ng-container>
            {{ shippingAddress()!.area }}, {{ shippingAddress()!.emirate }}
          </p>
          <p class="review-address__phone">{{ shippingAddress()!.recipient_phone }}</p>
        </section>

        <section class="checkout-page__section" aria-labelledby="items-heading">
          <h2 id="items-heading" class="checkout-page__section-title">
            {{ 'checkout.review.itemsHeading' | translate }}
          </h2>
          <ul class="review-items" role="list">
            <li
              *ngFor="let item of items(); trackBy: trackById"
              class="review-item"
              data-testid="review-item"
            >
              <div class="review-item__thumb" aria-hidden="true">
                <img
                  *ngIf="item.product_image !== ''; else thumbPlaceholder"
                  [src]="item.product_image"
                  alt=""
                  loading="lazy"
                />
                <ng-template #thumbPlaceholder>
                  <div class="review-item__thumb-placeholder"></div>
                </ng-template>
              </div>
              <div class="review-item__body">
                <p class="review-item__name">
                  {{ item.product_name || ('cart.drawer.unnamedItem' | translate) }}
                </p>
                <p
                  *ngIf="item.size !== null || item.color !== null"
                  class="review-item__variant"
                >
                  <ng-container *ngIf="item.size !== null">{{ item.size }}</ng-container>
                  <ng-container *ngIf="item.size !== null && item.color !== null"> · </ng-container>
                  <ng-container *ngIf="item.color !== null">{{ item.color }}</ng-container>
                </p>
                <p class="review-item__qty">
                  {{ 'cart.drawer.qtyLabel' | translate }}: {{ item.quantity }}
                </p>
              </div>
              <p class="review-item__price">
                {{ currency() }} {{ item.line_subtotal }}
              </p>
            </li>
          </ul>
        </section>

        <section class="checkout-page__section" aria-labelledby="promo-heading">
          <h2 id="promo-heading" class="checkout-page__section-title">
            {{ 'checkout.review.promoHeading' | translate }}
          </h2>
          <form
            *ngIf="appliedPromo() === null; else appliedBlock"
            [formGroup]="promoForm"
            (ngSubmit)="onApplyPromo()"
            class="review-promo"
            data-testid="review-promo-form"
          >
            <input
              type="text"
              formControlName="code"
              class="review-promo__input"
              autocomplete="off"
              spellcheck="false"
              [placeholder]="'checkout.review.promoPlaceholder' | translate"
              data-testid="review-promo-input"
            />
            <button
              type="submit"
              class="review-promo__btn"
              [disabled]="promoForm.invalid || isInitiating()"
              data-testid="review-promo-apply"
            >
              {{ 'checkout.review.promoApply' | translate }}
            </button>
          </form>

          <ng-template #appliedBlock>
            <div
              class="review-promo-applied"
              [attr.data-promo-code]="appliedPromo()"
              [attr.data-promo-status]="promoStatus()"
              data-testid="review-promo-applied"
            >
              <span
                class="review-promo-applied__status"
                [class.review-promo-applied__status--invalid]="promoStatus() === 'invalid'"
              >
                {{ (promoStatus() === 'valid' ? 'checkout.review.promoApplied' : 'checkout.review.promoInvalid') | translate : { code: appliedPromo() } }}
              </span>
              <button
                type="button"
                class="review-promo-applied__remove"
                (click)="onRemovePromo()"
                data-testid="review-promo-remove"
              >
                {{ 'checkout.review.promoRemove' | translate }}
              </button>
            </div>
          </ng-template>
        </section>

        <section class="checkout-page__section" aria-labelledby="totals-heading">
          <h2 id="totals-heading" class="checkout-page__section-title">
            {{ 'checkout.review.totalsHeading' | translate }}
          </h2>
          <dl class="checkout-summary">
            <div class="checkout-summary__line">
              <dt>{{ 'checkout.review.subtotal' | translate }}</dt>
              <dd data-testid="review-subtotal">
                {{ currency() }} {{ breakdown()?.subtotal ?? '0.00' }}
              </dd>
            </div>
            <div class="checkout-summary__line">
              <dt>{{ 'checkout.review.shipping' | translate }}</dt>
              <dd data-testid="review-shipping">
                {{ currency() }} {{ breakdown()?.shipping ?? '0.00' }}
              </dd>
            </div>
            <div class="checkout-summary__line">
              <dt>{{ 'checkout.review.tax' | translate }}</dt>
              <dd data-testid="review-tax">
                {{ currency() }} {{ breakdown()?.tax ?? '0.00' }}
              </dd>
            </div>
            <div
              *ngIf="hasDiscount()"
              class="checkout-summary__line"
              data-testid="review-discount-line"
            >
              <dt>{{ 'checkout.review.discount' | translate }}</dt>
              <dd>−{{ currency() }} {{ breakdown()?.promo_discount }}</dd>
            </div>
            <div class="checkout-summary__line checkout-summary__line--total">
              <dt>{{ 'checkout.review.total' | translate }}</dt>
              <dd data-testid="review-total">
                {{ currency() }} {{ breakdown()?.total ?? subtotal() }}
              </dd>
            </div>
          </dl>
        </section>

        @if (isDisplayConverted()) {
          <p class="checkout-aed-notice" role="note">
            ﹡ Displayed prices are indicative. Your payment will be
            charged in <strong>AED</strong> at the rate set by your
            card issuer or payment provider.
          </p>
        }

        <div class="checkout-page__actions">
          <button
            type="button"
            class="checkout-page__back"
            (click)="onBack()"
            data-testid="review-back"
          >
            ← {{ 'checkout.review.backToAddress' | translate }}
          </button>
          <button
            type="button"
            class="checkout-page__continue"
            [disabled]="!canPlaceOrder()"
            (click)="onPlaceOrder()"
            data-testid="review-place-order"
          >
            {{ (isInitiating() ? 'common.loading' : 'checkout.review.placeOrder') | translate }}
          </button>
        </div>
      </div>
    </main>
  `,
  styleUrl: './checkout.scss',
})
export class CheckoutReviewPageComponent implements OnInit {
  private readonly cart = inject(CartService);
  private readonly checkout = inject(CheckoutService);
  private readonly addressService = inject(AddressService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private readonly currencyService = inject(CurrencyService);
  /** True when the visitor has a non-AED display currency set. */
  protected readonly isDisplayConverted = this.currencyService.isConverted;

  protected readonly items = computed<CartItem[]>(() => this.cart.cart().items);
  protected readonly currency = this.cart.currency;
  protected readonly subtotal = this.cart.subtotal;
  protected readonly isInitiating = this.checkout.isInitiating;

  private readonly _quote = signal<CartQuoteResponse | null>(null);
  protected readonly breakdown = computed(() => this._quote()?.breakdown ?? null);
  protected readonly appliedPromo = computed(() => this._quote()?.promo_code ?? null);
  protected readonly promoStatus = computed<'valid' | 'invalid' | null>(() => {
    const q = this._quote();
    if (q === null || q.promo_code === null) return null;
    return q.promo_valid ? 'valid' : 'invalid';
  });
  protected readonly hasDiscount = computed(() => {
    const d = this.breakdown()?.promo_discount;
    return d !== undefined && parseFloat(d) > 0;
  });

  private readonly _shippingAddress = signal<Address | null>(null);
  protected readonly shippingAddress = this._shippingAddress.asReadonly();

  protected readonly canPlaceOrder = computed(() => {
    return this.shippingAddress() !== null
      && this.items().length > 0
      && !this.isInitiating();
  });

  protected readonly promoForm: FormGroup<{ code: FormControl<string> }>;

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.promoForm = fb.group({
      code: fb.control('', [Validators.required, Validators.minLength(2)]),
    });
  }

  async ngOnInit(): Promise<void> {
    /* Bounce guards. */
    if (this.cart.cart().items.length === 0) {
      await this.router.navigateByUrl('/cart');
      return;
    }
    if (this.checkout.shippingAddressId() === null) {
      await this.router.navigateByUrl('/checkout/address');
      return;
    }

    /* Resolve the shipping address from the address book. */
    await this.resolveShippingAddress();

    /* Initial quote (may carry a promo code from /cart). */
    await this.refreshQuote(this.checkout.promoCode());
  }

  private async resolveShippingAddress(): Promise<void> {
    const id = this.checkout.shippingAddressId();
    if (id === null) return;
    try {
      const list = await this.addressService.list();
      const found = list.find(a => a.id === id);
      if (found === undefined) {
        /* The selected address was deleted between steps. Bounce
           back to address picker to choose another. */
        await this.router.navigateByUrl('/checkout/address');
        return;
      }
      this._shippingAddress.set(found);
    } catch {
      this.toast.error('addresses.errors.loadFailed');
    }
  }

  protected trackById(_idx: number, item: CartItem): number {
    return item.id;
  }

  /* -----------------------------------------------------------------
     Promo
     ----------------------------------------------------------------- */

  protected async onApplyPromo(): Promise<void> {
    this.promoForm.markAllAsTouched();
    if (this.promoForm.invalid || this.isInitiating()) return;
    const code = this.promoForm.controls.code.value.trim();
    await this.refreshQuote(code);
  }

  protected async onRemovePromo(): Promise<void> {
    this.checkout.setPromoCode(null);
    this.promoForm.controls.code.setValue('');
    await this.refreshQuote(null);
  }

  private async refreshQuote(promoCode: string | null): Promise<void> {
    try {
      const quote = await this.cart.quoteWithPromo(promoCode);
      this._quote.set(quote);
      /* Sync the carried promo code in CheckoutService. If the server
         deemed it invalid, we still mirror the user's selection so the
         status pill renders; place-order will resend it for the
         server to ultimately reject again, OR the user removes it
         first. */
      this.checkout.setPromoCode(quote.promo_valid ? quote.promo_code : null);
    } catch {
      this.toast.error('checkout.errors.networkError');
    }
  }

  /* -----------------------------------------------------------------
     Navigation
     ----------------------------------------------------------------- */

  protected async onEditShipping(): Promise<void> {
    await this.router.navigateByUrl('/checkout/address');
  }

  protected async onBack(): Promise<void> {
    await this.router.navigateByUrl('/checkout/address');
  }

  protected async onPlaceOrder(): Promise<void> {
    if (!this.canPlaceOrder()) return;
    const breakdown = this.breakdown();
    if (breakdown === null) {
      this.toast.error('checkout.errors.initiateFailed');
      return;
    }
    const shippingId = this.checkout.shippingAddressId();
    const billingId = this.checkout.billingAddressId() ?? shippingId;
    /* Only send promo if it's currently valid. */
    const validPromo = this.promoStatus() === 'valid' ? this.appliedPromo() : null;

    try {
      const response = await this.checkout.initiate({
        channel: 'web',
        delivery_fee: breakdown.shipping,
        discount: breakdown.promo_discount,
        promo_code: validPromo,
        billing_address_id: billingId,
        shipping_address_id: shippingId,
      });
      /* Hand off to the payment-handoff page with the URL via router
         state — query params would expose the redirect URL in browser
         history which is fine for Noon but better kept off the URL bar. */
      await this.router.navigate(['/checkout/payment'], {
        state: {
          checkout_url: response.checkout_url,
          order_reference: response.order_reference,
        },
      });
    } catch {
      this.toast.error('checkout.errors.initiateFailed');
    }
  }
}
