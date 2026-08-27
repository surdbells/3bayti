import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { ActivatedRoute, RouterLink, Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { OrderService } from '../../core/orders';
import type { Order } from '../../core/orders';
import { CheckoutService } from '../../core/checkout';
import { CartService } from '../../core/cart';
import { ToastService } from '../../shared/forms';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';

/**
 * /checkout/success/:id, order placed confirmation.
 *
 * Lands here from:
 *   - Y.3's /checkout/return route after a successful payment poll
 *   - Direct URL entry (user bookmarked the page mid-checkout)
 *
 * Side-effects on landing
 * -----------------------
 *   1. Fetch the order via OrderService.getById
 *   2. If successful → clear CheckoutService (in-progress state no
 *      longer relevant) AND refresh the cart (the order conversion
 *      will have emptied the server cart)
 *   3. If failed → toast + Back to /account/orders
 *
 * We do NOT verify the order belongs to the current user, the API
 * enforces that and returns 404 for cross-user reads.
 *
 * Layout
 * ------
 *   - Hero card: ✓ icon, "Order placed!" heading, order reference,
 *     placed-on date
 *   - Order summary card: items, totals
 *   - Shipping address card
 *   - Action buttons: View all orders / Continue shopping
 *
 * The hero animation is intentionally restrained, a single subtle
 * scale-in on the checkmark. Users in the post-checkout flow want
 * confirmation, not fireworks.
 */
@Component({
  selector: 'app-checkout-success',
  standalone: true,
  imports: [CfImagePipe, NgIf, NgFor, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="checkout-page" data-testid="checkout-success-page">
      <div class="checkout-page__container">
        <ng-container *ngIf="order() !== null; else loadingOrError">
          <section class="success-hero" data-testid="success-hero">
            <div class="success-hero__check" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12" />
              </svg>
            </div>
            <h1 class="success-hero__title">
              {{ 'checkout.success.title' | translate }}
            </h1>
            <p class="success-hero__body">
              {{ 'checkout.success.body' | translate }}
            </p>
            <p class="success-hero__ref" data-testid="success-order-ref">
              {{ order()!.order_reference }}
            </p>
          </section>

          <section
            class="checkout-page__section"
            aria-labelledby="success-items-heading"
          >
            <h2 id="success-items-heading" class="checkout-page__section-title">
              {{ 'checkout.review.itemsHeading' | translate }}
            </h2>
            <ul class="review-items" role="list">
              <li
                *ngFor="let item of order()!.items; trackBy: trackById"
                class="review-item"
                data-testid="success-item"
              >
                <div class="review-item__thumb" aria-hidden="true">
                  <img
                    *ngIf="(item.product_image ?? '') !== ''; else thumbPlaceholder"
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
                  {{ order()!.currency }} {{ item.subtotal }}
                </p>
              </li>
            </ul>
          </section>

          <section
            class="checkout-page__section"
            aria-labelledby="success-totals-heading"
          >
            <h2 id="success-totals-heading" class="checkout-page__section-title">
              {{ 'checkout.review.totalsHeading' | translate }}
            </h2>
            <dl class="checkout-summary">
              <div class="checkout-summary__line">
                <dt>{{ 'checkout.review.subtotal' | translate }}</dt>
                <dd>{{ order()!.currency }} {{ order()!.subtotal }}</dd>
              </div>
              <div class="checkout-summary__line">
                <dt>{{ 'checkout.review.shipping' | translate }}</dt>
                <dd>{{ order()!.currency }} {{ order()!.delivery_fee }}</dd>
              </div>
              <div
                *ngIf="parseFloat(order()!.discount) > 0"
                class="checkout-summary__line"
              >
                <dt>{{ 'checkout.review.discount' | translate }}</dt>
                <dd>−{{ order()!.currency }} {{ order()!.discount }}</dd>
              </div>
              <div class="checkout-summary__line checkout-summary__line--total">
                <dt>{{ 'checkout.review.total' | translate }}</dt>
                <dd data-testid="success-total">
                  {{ order()!.currency }} {{ order()!.total }}
                </dd>
              </div>
            </dl>
          </section>

          <section
            *ngIf="order()!.shipping_address as addr"
            class="checkout-page__section"
            aria-labelledby="success-shipping-heading"
          >
            <h2 id="success-shipping-heading" class="checkout-page__section-title">
              {{ 'checkout.review.shippingHeading' | translate }}
            </h2>
            <p
              *ngIf="addr.first_name || addr.last_name"
              class="review-address__name"
            >
              {{ addr.first_name }} {{ addr.last_name }}
            </p>
            <p *ngIf="addr.street" class="review-address__line">
              {{ addr.street }}
            </p>
            <p *ngIf="addr.city || addr.state_province" class="review-address__line">
              {{ addr.city
              }}<ng-container *ngIf="addr.city && addr.state_province">, </ng-container
              >{{ addr.state_province }}
            </p>
            <p
              *ngIf="addr.country_code || addr.postal_code"
              class="review-address__line"
            >
              {{ addr.country_code
              }}<ng-container *ngIf="addr.postal_code"> {{ addr.postal_code }}</ng-container>
            </p>
            <p *ngIf="addr.phone" class="review-address__phone">
              {{ addr.phone }}
            </p>
          </section>

          <div class="checkout-page__actions">
            <a
              routerLink="/category"
              class="checkout-page__back"
              data-testid="success-continue-shopping"
            >
              {{ 'checkout.success.continueShopping' | translate }}
            </a>
            <a
              [routerLink]="['/account/orders', order()!.id]"
              class="checkout-page__continue"
              data-testid="success-view-order"
            >
              {{ 'checkout.success.viewOrder' | translate }}
            </a>
          </div>
        </ng-container>

        <ng-template #loadingOrError>
          <div
            *ngIf="isLoading()"
            class="checkout-page__loading"
            data-testid="success-loading"
          >
            {{ 'common.loading' | translate }}
          </div>
          <div
            *ngIf="!isLoading() && errored()"
            class="checkout-page__empty"
            data-testid="success-error"
          >
            <p>{{ 'checkout.errors.initiateFailed' | translate }}</p>
            <a routerLink="/account/orders" class="checkout-page__continue">
              {{ 'header.userMenu.orders' | translate }}
            </a>
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './checkout.scss',
})
export class CheckoutSuccessPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly orderService = inject(OrderService);
  private readonly checkoutService = inject(CheckoutService);
  private readonly cart = inject(CartService);
  private readonly toast = inject(ToastService);

  private readonly _order = signal<Order | null>(null);
  protected readonly order = this._order.asReadonly();
  protected readonly isLoading = this.orderService.isLoadingDetail;

  private readonly _errored = signal<boolean>(false);
  protected readonly errored = this._errored.asReadonly();

  /** Template helper, avoid pipe overhead for a single arithmetic check. */
  protected readonly parseFloat = parseFloat;

  async ngOnInit(): Promise<void> {
    const idParam = this.route.snapshot.paramMap.get('id');
    const id = idParam !== null ? Number(idParam) : NaN;
    if (!Number.isFinite(id) || id <= 0) {
      this._errored.set(true);
      return;
    }
    try {
      const order = await this.orderService.getById(id);
      this._order.set(order);
      /* Order confirmed → clear checkout in-progress state and
         refresh the cart (the server-side conversion will have
         marked it as ordered; refresh picks up the empty state). */
      this.checkoutService.clear();
      try {
        await this.cart.refresh();
      } catch {
        /* Non-critical; the cart drawer will pick up on next nav. */
      }
    } catch {
      this._errored.set(true);
      this.toast.error('checkout.errors.initiateFailed');
    }
  }

  protected trackById(_idx: number, item: { id: number }): number {
    return item.id;
  }
}
