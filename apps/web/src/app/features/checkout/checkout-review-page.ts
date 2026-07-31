import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { DecimalPipe, NgIf, NgFor } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { FormBuilder, FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CheckoutStepperComponent } from './checkout-stepper';
import { CartService } from '../../core/cart';
import type { CartItem, CartQuoteResponse } from '../../core/cart';
import { CheckoutService } from '../../core/checkout';
import { GiftCardService } from '../gift-cards/gift-card.service';
import type { GiftCardCartPreview, GiftWalletCartPreview } from '../gift-cards/gift-card.model';
import { AuthService } from '../../core/auth/auth.service';
import { AUTH_ERROR_CODES } from '../../core/auth/auth.types';
import { AddressService } from '../../core/addresses';
import type { Address } from '../../core/addresses';
import { ToastService } from '../../shared/forms';
import { CurrencyService } from '../../core/currency/currency.service';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';

/** Where verify-phone returns the user after they verify mid-checkout. */
const CHECKOUT_REVIEW_PATH = '/checkout/review';

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
  imports: [CfImagePipe, DecimalPipe, NgIf, NgFor, ReactiveFormsModule, TranslatePipe, CheckoutStepperComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="checkout-page checkout-page--review" data-testid="checkout-review-page">
      <div class="checkout-page__container checkout-page__container--wide">
        <h1 class="checkout-page__title">
          {{ 'checkout.review.title' | translate }}
        </h1>

        <app-checkout-stepper [activeStep]="1"></app-checkout-stepper>

        <div class="review-grid">
          <!-- Left column: delivery + items -->
          <div class="review-grid__main">

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
                  [src]="item.product_image | cfImage:'thumb'"
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

          </div>
          <!-- Right column: order summary (sticky on desktop) -->
          <aside class="review-grid__aside">

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

        @if (walletAvailable() || walletApplied()) {
          <section class="checkout-page__section" aria-labelledby="giftwallet-heading">
            <h2 id="giftwallet-heading" class="checkout-page__section-title">
              {{ 'checkout.review.giftWalletHeading' | translate }}
            </h2>

            @if (!walletApplied()) {
              <div class="review-giftwallet" data-testid="review-giftwallet">
                <div class="review-giftwallet__info">
                  <span class="review-giftwallet__balance" data-testid="review-giftwallet-balance">
                    {{ walletPreview()!.currency }} {{ walletPreview()!.wallet_balance }}
                  </span>
                  <span class="review-giftwallet__label">
                    {{ 'checkout.review.giftWalletAvailable' | translate }}
                  </span>
                </div>
                <button
                  type="button"
                  class="review-promo__btn"
                  (click)="onApplyWallet()"
                  [disabled]="isInitiating()"
                  data-testid="review-giftwallet-apply"
                >
                  {{ 'checkout.review.giftWalletApply' | translate }}
                </button>
              </div>
            } @else {
              <div class="review-giftcard-applied" data-testid="review-giftwallet-applied">
                <div class="review-giftcard-applied__head">
                  <span class="review-promo-applied__status">
                    {{ 'checkout.review.giftWalletApplied' | translate }}
                  </span>
                  <button
                    type="button"
                    class="review-promo-applied__remove"
                    (click)="onRemoveWallet()"
                    data-testid="review-giftwallet-remove"
                  >
                    {{ 'checkout.review.giftCardRemove' | translate }}
                  </button>
                </div>
                <dl class="review-giftcard-applied__amounts checkout-summary">
                  <div class="checkout-summary__line">
                    <dt>{{ 'checkout.review.giftCardCredit' | translate }}</dt>
                    <dd>−{{ walletPreview()!.currency }} {{ walletCredit() | number: '1.2-2' }}</dd>
                  </div>
                  <div class="checkout-summary__line checkout-summary__line--total">
                    <dt>{{ 'checkout.review.amountDue' | translate }}</dt>
                    <dd data-testid="review-giftwallet-due">
                      {{ walletPreview()!.currency }} {{ walletRemainingDue() | number: '1.2-2' }}
                    </dd>
                  </div>
                </dl>
                @if (walletCoversOrder()) {
                  <p class="review-giftwallet__covered" data-testid="review-giftwallet-covered">
                    {{ 'checkout.review.giftWalletCovers' | translate }}
                  </p>
                }
              </div>
            }
          </section>
        }

        <section class="checkout-page__section" aria-labelledby="giftcard-heading">
          <h2 id="giftcard-heading" class="checkout-page__section-title">
            {{ 'checkout.review.giftCardHeading' | translate }}
          </h2>

          @if (appliedGiftCode() === null) {
            <form
              [formGroup]="giftCardForm"
              (ngSubmit)="onApplyGiftCard()"
              class="review-promo"
              data-testid="review-giftcard-form"
            >
              <input
                type="text"
                formControlName="code"
                class="review-promo__input"
                autocapitalize="characters"
                autocomplete="off"
                spellcheck="false"
                [placeholder]="'checkout.review.giftCardPlaceholder' | translate"
                data-testid="review-giftcard-input"
              />
              <button
                type="submit"
                class="review-promo__btn"
                [disabled]="giftCardForm.invalid || giftApplying() || isInitiating()"
                data-testid="review-giftcard-apply"
              >
                {{ (giftApplying() ? 'common.loading' : 'checkout.review.giftCardApply') | translate }}
              </button>
            </form>

            @switch (giftError()) {
              @case ('notfound') {
                <p class="review-promo-applied__status review-promo-applied__status--invalid" role="alert" data-testid="review-giftcard-error">
                  {{ 'checkout.review.giftCardNotFound' | translate }}
                </p>
              }
              @case ('notApplicable') {
                <p class="review-promo-applied__status review-promo-applied__status--invalid" role="alert" data-testid="review-giftcard-error">
                  {{ 'checkout.review.giftCardNotApplicable' | translate }}
                </p>
              }
              @case ('error') {
                <p class="review-promo-applied__status review-promo-applied__status--invalid" role="alert" data-testid="review-giftcard-error">
                  {{ 'checkout.review.giftCardError' | translate }}
                </p>
              }
            }
          } @else {
            <div
              class="review-giftcard-applied"
              [attr.data-giftcard-code]="appliedGiftCode()"
              data-testid="review-giftcard-applied"
            >
              <div class="review-giftcard-applied__head">
                <span class="review-promo-applied__status">
                  {{ 'checkout.review.giftCardApplied' | translate : { code: appliedGiftCode() } }}
                </span>
                <button
                  type="button"
                  class="review-promo-applied__remove"
                  (click)="onRemoveGiftCard()"
                  data-testid="review-giftcard-remove"
                >
                  {{ 'checkout.review.giftCardRemove' | translate }}
                </button>
              </div>
              <dl class="review-giftcard-applied__amounts checkout-summary">
                <div class="checkout-summary__line">
                  <dt>{{ 'checkout.review.giftCardCredit' | translate }}</dt>
                  <dd>−{{ giftPreview()!.currency }} {{ giftPreview()!.applicable }}</dd>
                </div>
                <div class="checkout-summary__line checkout-summary__line--total">
                  <dt>{{ 'checkout.review.amountDue' | translate }}</dt>
                  <dd data-testid="review-giftcard-due">{{ giftPreview()!.currency }} {{ giftPreview()!.remaining_due }}</dd>
                </div>
              </dl>
            </div>
          }
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
          <p class="checkout-aed-notice" role="note">{{ 'checkout.review.priceNote' | translate }}</p>
        }

        <p
          *ngIf="needsPhoneVerification()"
          class="checkout-verify-note"
          role="note"
          data-testid="checkout-verify-note"
        >
          {{ 'checkout.review.verifyNote' | translate }}
          <button
            type="button"
            class="checkout-verify-note__link"
            (click)="goToPhoneVerification()"
            data-testid="checkout-verify-link"
          >
            {{ 'checkout.review.verifyLink' | translate }}
          </button>
        </p>

        <div class="review-actions">
          <div class="review-actions__recap" aria-hidden="true">
            <span class="review-actions__recap-label">{{ 'checkout.review.total' | translate }}</span>
            <span class="review-actions__recap-value">
              {{ currency() }} {{ breakdown()?.total ?? subtotal() }}
            </span>
          </div>
          <div class="review-actions__buttons">
            <button
              type="button"
              class="review-actions__back"
              (click)="onBack()"
              data-testid="review-back"
            >
              ← {{ 'checkout.review.backToAddress' | translate }}
            </button>
            <button
              type="button"
              class="review-actions__place-order"
              [disabled]="!canPlaceOrder()"
              (click)="onPlaceOrder()"
              data-testid="review-place-order"
            >
              {{ (isInitiating() ? 'common.loading' : 'checkout.review.placeOrder') | translate }}
            </button>
          </div>
        </div>

          </aside>
        </div>
      </div>
    </main>
  `,
  styleUrl: './checkout.scss',
})
export class CheckoutReviewPageComponent implements OnInit {
  private readonly cart = inject(CartService);
  private readonly checkout = inject(CheckoutService);
  private readonly giftCards = inject(GiftCardService);
  private readonly addressService = inject(AddressService);
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private readonly currencyService = inject(CurrencyService);
  /** True when the visitor has a non-AED display currency set. */
  protected readonly isDisplayConverted = this.currencyService.isConverted;

  /**
   * True when the signed-in shopper hasn't verified their phone. Phone
   * verification is required before placing an order; we surface an inline
   * note and block placement (the server enforces this too).
   */
  protected readonly needsPhoneVerification = computed(
    () => this.auth.currentUser()?.is_phone_verified === false,
  );

  protected readonly items = computed<CartItem[]>(() => this.cart.cart().items ?? []);
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

  /* -----------------------------------------------------------------
     Gift card (Phase E5) — apply an existing card to this cart order.
     Preview via POST /v3/cart/gift-card; the resolved code is sent to
     initiate, where the server debits the card and charges only the
     remainder. The totals dl is left untouched (server-authoritative);
     the applied block shows the credit + amount due from the preview.
     ----------------------------------------------------------------- */
  protected readonly giftCardForm: FormGroup<{ code: FormControl<string> }>;
  private readonly _giftPreview = signal<GiftCardCartPreview | null>(null);
  protected readonly giftPreview = this._giftPreview.asReadonly();
  protected readonly giftApplying = signal<boolean>(false);
  protected readonly giftError = signal<'none' | 'notfound' | 'notApplicable' | 'error'>('none');
  protected readonly appliedGiftCode = computed<string | null>(() => this._giftPreview()?.code ?? null);

  /* -----------------------------------------------------------------
     Gift WALLET — one-tap alternative to typing a single code. Previews
     GET /v3/cart/gift-wallet (aggregate balance across every spendable
     card the customer owns/redeemed) and, when applied, sends
     use_gift_wallet=true to initiate. Mutually exclusive with the single
     code above, mirroring the server rule that an explicit code wins.
     ----------------------------------------------------------------- */
  private readonly _walletPreview = signal<GiftWalletCartPreview | null>(null);
  protected readonly walletPreview = this._walletPreview.asReadonly();
  protected readonly walletApplied = signal<boolean>(false);

  /** Wallet has spendable balance and no single card is applied. */
  protected readonly walletAvailable = computed<boolean>(() => {
    const w = this._walletPreview();
    return w !== null && Number(w.wallet_balance) > 0 && this.appliedGiftCode() === null;
  });

  /**
   * The payable total the gift balance must cover — the server-authoritative
   * quote total (subtotal + delivery − promo), i.e. the "amount due" shown in
   * the summary before any gift credit.
   */
  protected readonly payableBeforeGift = computed<number>(() =>
    Number(this.breakdown()?.total ?? this.subtotal() ?? 0),
  );

  /**
   * Credit drawn from the wallet. Derived from the authoritative total rather
   * than echoing the preview's own `applied`, so the figure stays correct when
   * a promo is applied after the preview was fetched. The server caps the real
   * draw the same way at initiate.
   */
  protected readonly walletCredit = computed<number>(() => {
    const w = this._walletPreview();
    if (w === null) return 0;
    return Math.min(Number(w.wallet_balance), this.payableBeforeGift());
  });

  /** Remainder the gateway will be charged after the wallet credit. */
  protected readonly walletRemainingDue = computed<number>(() =>
    Math.max(0, this.payableBeforeGift() - this.walletCredit()),
  );

  /** True when the wallet covers the whole payable total. */
  protected readonly walletCoversOrder = computed<boolean>(() =>
    this.payableBeforeGift() > 0 && this.walletRemainingDue() === 0,
  );

  /** Load (or refresh) the wallet preview for the current cart. */
  private async loadWalletPreview(): Promise<void> {
    try {
      this._walletPreview.set(await this.giftCards.previewWalletApply());
    } catch {
      /* Non-fatal: the wallet section just stays hidden. */
      this._walletPreview.set(null);
    }
  }

  /** One-tap apply. Clears any applied single card (server: code wins). */
  protected onApplyWallet(): void {
    this._giftPreview.set(null);
    this.giftError.set('none');
    this.walletApplied.set(true);
  }

  protected onRemoveWallet(): void {
    this.walletApplied.set(false);
  }

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.promoForm = fb.group({
      code: fb.control('', [Validators.required, Validators.minLength(2)]),
    });
    this.giftCardForm = fb.group({
      code: fb.control('', [Validators.required, Validators.minLength(4)]),
    });
  }

  async ngOnInit(): Promise<void> {
    /* Bounce guards. */
    if ((this.cart.cart().items ?? []).length === 0) {
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

    /* Gift wallet availability for this cart (non-blocking). */
    await this.loadWalletPreview();
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
    } catch (err) {
      const code = this.extractApiErrorCode(err);
      /* A rejected promo (not found / expired / inactive / min-subtotal
         not met / limit reached / ...) comes back as a PROMO_* 422.
         Surface the actual reason instead of a generic network error, then
         re-quote WITHOUT the promo so the totals still render and the
         order can proceed. The promoCode guard prevents recursion. */
      if (promoCode !== null && code !== null && code.startsWith('PROMO_')) {
        this.toast.error('checkout.review.promoInvalid', { code: promoCode });
        this.checkout.setPromoCode(null);
        this.promoForm.controls.code.setValue('');
        await this.refreshQuote(null);
        return;
      }
      this.toast.error('checkout.errors.networkError');
    }
  }

  /* -----------------------------------------------------------------
     Gift card
     ----------------------------------------------------------------- */

  protected async onApplyGiftCard(): Promise<void> {
    this.giftCardForm.markAllAsTouched();
    if (this.giftCardForm.invalid || this.giftApplying() || this.isInitiating()) return;
    const code = this.giftCardForm.controls.code.value.trim();
    this.giftError.set('none');
    this.giftApplying.set(true);
    try {
      const preview = await this.giftCards.previewCartApply(code);
      this._giftPreview.set(preview);
    } catch (err) {
      if (err instanceof HttpErrorResponse && err.status === 404) {
        this.giftError.set('notfound');
      } else if (err instanceof HttpErrorResponse && (err.status === 400 || err.status === 422)) {
        this.giftError.set('notApplicable');
      } else {
        this.giftError.set('error');
      }
    } finally {
      this.giftApplying.set(false);
    }
  }

  protected onRemoveGiftCard(): void {
    this._giftPreview.set(null);
    this.giftError.set('none');
    this.giftCardForm.controls.code.setValue('');
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

    /* Phone verification is required before placing an order. Block here
       and route to verification (returning to checkout after); the server
       enforces the same rule, handled in the catch below as a backstop. */
    if (this.needsPhoneVerification()) {
      this.toast.error('checkout.errors.phoneNotVerified');
      await this.goToPhoneVerification();
      return;
    }

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
        gift_card_code: this.appliedGiftCode(),
        use_gift_wallet: this.walletApplied() ? true : undefined,
      });

      /* Gift card / wallet covered the whole total: the server skipped Noon
         and the order is already paid, so there is no checkout_url to hand
         off to. Go straight to the return page, which resolves the order
         state and shows the confirmation. */
      if (response.gateway_skipped === true || !response.checkout_url) {
        this.checkout.clear();
        await this.router.navigate(['/checkout/return'], {
          queryParams: { ref: response.order_reference },
        });
        return;
      }

      /* Hand off to the payment-handoff page with the URL via router
         state — query params would expose the redirect URL in browser
         history which is fine for Noon but better kept off the URL bar. */
      await this.router.navigate(['/checkout/payment'], {
        state: {
          checkout_url: response.checkout_url,
          order_reference: response.order_reference,
        },
      });
    } catch (err) {
      /* Backstop: the server rejects unverified phones at order
         placement. If our client state was stale, route to verification
         rather than showing a generic failure. */
      if (this.extractApiErrorCode(err) === AUTH_ERROR_CODES.PHONE_NOT_VERIFIED) {
        this.toast.error('checkout.errors.phoneNotVerified');
        await this.goToPhoneVerification();
        return;
      }
      this.toast.error('checkout.errors.initiateFailed');
    }
  }

  /** Route to phone verification, returning to checkout review after. */
  protected async goToPhoneVerification(): Promise<void> {
    await this.router.navigate(['/verify-phone'], {
      queryParams: { returnUrl: CHECKOUT_REVIEW_PATH },
    });
  }

  /**
   * Pull the API error code out of a failed request. Handles the API's
   * native nested envelope ({ error: { code } }) and the flat BFF-proxy
   * shape ({ error_code }), returning null for non-HTTP / opaque errors.
   */
  private extractApiErrorCode(err: unknown): string | null {
    if (!(err instanceof HttpErrorResponse)) return null;
    const body = err.error as
      | { error?: { code?: string }; error_code?: string }
      | string
      | null;
    if (body === null || typeof body !== 'object') return null;
    return body.error?.code ?? body.error_code ?? null;
  }
}
