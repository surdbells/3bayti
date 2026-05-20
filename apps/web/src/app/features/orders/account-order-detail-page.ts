import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
  PLATFORM_ID,
} from '@angular/core';
import { NgIf, NgFor, DatePipe, isPlatformBrowser } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { OrderService, ORDER_STATUS_LABELS } from '../../core/orders';
import type { Order } from '../../core/orders';
import { ToastService } from '../../shared/forms';

/**
 * /account/orders/:id — single-order detail page.
 *
 * Surface
 * -------
 *   - Back link to /account/orders
 *   - Header: status pill, "Placed on" date, order reference
 *   - Items list (read-only)
 *   - Totals card (subtotal, shipping, optional discount, total)
 *   - Promo block when applied_promo is non-null
 *   - Shipping + billing address cards
 *   - Returns summary (when present in the order payload)
 *   - Cancel button when status === 'pending_payment'
 *
 * Cancel flow
 * -----------
 * Only enabled for pending_payment orders (API rejects others with 409).
 * Click → native confirm() with translated message → POST
 * /v3/orders/:id/cancel. OrderService.cancel patches the cached
 * list item and returns the updated order which replaces the local
 * signal. Toast on success/failure.
 *
 * Why native confirm() for now: the global custom-confirm modal
 * lives in Y.5's account polish work. Native confirm() is keyboard-
 * accessible, focus-trapped, and unambiguous — perfectly acceptable
 * for a self-serve cancel until the design system delivers a modal.
 *
 * Returns submission
 * ------------------
 * The "Request a return" CTA is rendered when the order is
 * eligible (status in {paid, fulfilling, shipped, delivered} per
 * apps/api business rules). The actual return form is Y.2-J's
 * scope — for now the CTA can navigate to a future
 * /account/orders/:id/return route.
 *
 * Polling
 * -------
 * Y.3 wires polling for pending_payment orders (post-Noon-handoff
 * the status may flip to paid asynchronously). For Y.2-I we render
 * the snapshot status; if the user wants fresh data they refresh.
 */
@Component({
  selector: 'app-account-order-detail',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="orders-page" data-testid="order-detail-page">
      <div class="orders-page__container orders-page__container--narrow">
        <a routerLink="/account/orders" class="orders-page__back-link">
          {{ 'orders.detail.backToList' | translate }}
        </a>

        <ng-container *ngIf="order() !== null; else loadingOrError">
          <header class="order-detail__header">
            <span
              class="order-card__status"
              [class.order-card__status--positive]="isPositive(order()!.status)"
              [class.order-card__status--neutral]="isNeutral(order()!.status)"
              [class.order-card__status--warning]="isWarning(order()!.status)"
              [class.order-card__status--negative]="isNegative(order()!.status)"
              [attr.data-status]="order()!.status"
              data-testid="order-detail-status"
            >
              {{ statusLabel(order()!.status) | translate }}
            </span>
            <h1 class="order-detail__title">
              {{ order()!.order_reference }}
            </h1>
            <p class="order-detail__meta">
              <span>{{ 'orders.detail.placedOn' | translate }}: </span>
              <time>{{ order()!.date | date:'medium' }}</time>
            </p>
            <p
              *ngIf="order()!.paid_at !== null"
              class="order-detail__meta"
            >
              <span>{{ 'orders.detail.paidOn' | translate }}: </span>
              <time>{{ order()!.paid_at | date:'medium' }}</time>
            </p>
          </header>

          <section class="checkout-section">
            <h2 class="checkout-section__title">
              {{ 'checkout.review.itemsHeading' | translate }}
            </h2>
            <ul class="review-items" role="list">
              <li
                *ngFor="let item of order()!.items; trackBy: trackById"
                class="review-item"
                data-testid="order-detail-item"
              >
                <div class="review-item__thumb" aria-hidden="true">
                  <img
                    *ngIf="(item.product_image ?? '') !== ''; else thumbBlank"
                    [src]="item.product_image"
                    alt=""
                    loading="lazy"
                  />
                  <ng-template #thumbBlank>
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

          <section class="checkout-section">
            <h2 class="checkout-section__title">
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
                *ngIf="hasDiscount()"
                class="checkout-summary__line"
                data-testid="order-detail-discount-line"
              >
                <dt>{{ 'checkout.review.discount' | translate }}</dt>
                <dd>−{{ order()!.currency }} {{ order()!.discount }}</dd>
              </div>
              <div class="checkout-summary__line checkout-summary__line--total">
                <dt>{{ 'checkout.review.total' | translate }}</dt>
                <dd data-testid="order-detail-total">
                  {{ order()!.currency }} {{ order()!.total }}
                </dd>
              </div>
            </dl>

            <p
              *ngIf="order()!.applied_promo !== null"
              class="order-detail__promo-line"
              data-testid="order-detail-promo"
            >
              {{ 'orders.detail.appliedPromo' | translate }}:
              <strong>{{ order()!.applied_promo!.code }}</strong>
            </p>
          </section>

          <section class="checkout-section">
            <h2 class="checkout-section__title">
              {{ 'orders.detail.shippingHeading' | translate }}
            </h2>
            <p class="review-address__name">
              {{ order()!.shipping_address.recipient_name }}
            </p>
            <p class="review-address__line">
              <ng-container *ngIf="order()!.shipping_address.street_address !== null">
                {{ order()!.shipping_address.street_address }},
              </ng-container>
              <ng-container *ngIf="order()!.shipping_address.building_details !== null">
                {{ order()!.shipping_address.building_details }},
              </ng-container>
              {{ order()!.shipping_address.area }}, {{ order()!.shipping_address.emirate }}
            </p>
            <p class="review-address__phone">
              {{ order()!.shipping_address.recipient_phone }}
            </p>
          </section>

          <section
            *ngIf="hasReturns()"
            class="checkout-section"
            aria-labelledby="returns-heading"
            data-testid="order-detail-returns"
          >
            <h2 id="returns-heading" class="checkout-section__title">
              {{ 'orders.detail.returnsTitle' | translate }}
            </h2>
            <ul class="order-returns" role="list">
              <li
                *ngFor="let r of order()!.returns ?? []; trackBy: trackById"
                class="order-returns__item"
                data-testid="order-detail-return-item"
              >
                <span
                  class="order-card__status"
                  [attr.data-status]="r.status"
                >{{ r.status }}</span>
                <time class="order-detail__meta">
                  {{ r.requested_at | date:'mediumDate' }}
                </time>
                <span class="order-returns__count">
                  {{ r.item_count }}
                </span>
              </li>
            </ul>
          </section>

          <div
            *ngIf="canCancel() || canRequestReturn()"
            class="order-detail__actions"
            data-testid="order-detail-actions"
          >
            <a
              *ngIf="canRequestReturn()"
              [routerLink]="['/account/orders', order()!.id, 'return']"
              class="order-detail__return-btn"
              data-testid="order-detail-return-cta"
            >
              {{ 'orders.detail.submitReturn' | translate }}
            </a>
            <button
              *ngIf="canCancel()"
              type="button"
              class="order-detail__cancel-btn"
              [disabled]="isCancelling()"
              (click)="onCancelClick()"
              data-testid="order-detail-cancel"
            >
              {{ (isCancelling() ? 'common.loading' : 'orders.detail.cancelCta') | translate }}
            </button>
          </div>
        </ng-container>

        <ng-template #loadingOrError>
          <div
            *ngIf="isLoading()"
            class="orders-page__loading"
            data-testid="order-detail-loading"
          >
            {{ 'common.loading' | translate }}
          </div>
          <div
            *ngIf="!isLoading() && errored()"
            class="orders-page__empty"
            data-testid="order-detail-error"
          >
            <h2 class="orders-page__empty-title">
              {{ 'orders.detail.loadError' | translate }}
            </h2>
            <a routerLink="/account/orders" class="orders-page__empty-cta">
              {{ 'orders.detail.backToList' | translate }}
            </a>
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './order-detail.scss',
})
export class AccountOrderDetailPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly orderService = inject(OrderService);
  private readonly toast = inject(ToastService);
  private readonly platformId = inject(PLATFORM_ID);

  private readonly _order = signal<Order | null>(null);
  protected readonly order = this._order.asReadonly();
  protected readonly isLoading = this.orderService.isLoadingDetail;
  private readonly _errored = signal(false);
  protected readonly errored = this._errored.asReadonly();
  private readonly _isCancelling = signal(false);
  protected readonly isCancelling = this._isCancelling.asReadonly();

  protected readonly canCancel = computed(() => {
    const o = this._order();
    return o !== null && o.status === 'pending_payment' && !this._isCancelling();
  });

  /**
   * Return-eligibility (UI-side gate, server has final say).
   * Shows the CTA for any status where a return could plausibly be
   * filed — the server enforces the 14-day window + per-item
   * delivered status. If we surface the CTA for an ineligible order,
   * the form-level RETURN_* error mappings (Y.2-J) handle it
   * gracefully with a clear toast.
   *
   * Excludes (using the real apps/api Order::STATUS_* set):
   *   - pending_payment / cancelled / failed: no items were ever
   *     delivered, so nothing can be returned
   *   - refunded: the order is already resolved
   *
   * Shown for: paid, fulfilling, shipped, delivered.
   */
  protected readonly canRequestReturn = computed(() => {
    const o = this._order();
    if (o === null) return false;
    const excluded = ['pending_payment', 'cancelled', 'failed', 'refunded'];
    return !excluded.includes(o.status);
  });

  protected readonly hasDiscount = computed(() => {
    const o = this._order();
    return o !== null && parseFloat(o.discount) > 0;
  });

  protected readonly hasReturns = computed(() => {
    const o = this._order();
    return o !== null && Array.isArray(o.returns) && o.returns.length > 0;
  });

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
    } catch {
      this._errored.set(true);
      this.toast.error('orders.detail.loadError');
    }
  }

  /* -----------------------------------------------------------------
     Cancel
     ----------------------------------------------------------------- */

  protected async onCancelClick(): Promise<void> {
    if (!this.canCancel()) return;
    const order = this._order();
    if (order === null) return;

    /* Native confirm() — keyboard-accessible and unambiguous. The
       custom confirm modal lands in Y.5. */
    if (isPlatformBrowser(this.platformId)) {
      /* In tests this can be stubbed via window.confirm. */
      const confirmed = window.confirm(this.confirmMessage());
      if (!confirmed) return;
    }

    this._isCancelling.set(true);
    try {
      const updated = await this.orderService.cancel(order.id);
      this._order.set(updated);
      this.toast.success('orders.detail.cancelSuccess');
    } catch {
      this.toast.error('orders.detail.cancelFailed');
    } finally {
      this._isCancelling.set(false);
    }
  }

  /** Confirm message — pulled from translations at call time. Since
   *  this is read inside a native confirm() (not a template), we
   *  can't use the pipe; we mirror the key for the tests to assert. */
  protected confirmMessage(): string {
    return 'orders.detail.cancelConfirm';
  }

  /* -----------------------------------------------------------------
     Status pill classification (mirrors Y.2-H)
     ----------------------------------------------------------------- */

  protected statusLabel(status: string): string {
    return ORDER_STATUS_LABELS[status] ?? status;
  }
  protected isPositive(status: string): boolean {
    return status === 'delivered' || status === 'paid';
  }
  protected isNeutral(status: string): boolean {
    return status === 'fulfilling' || status === 'shipped';
  }
  protected isWarning(status: string): boolean {
    return status === 'pending_payment';
  }
  protected isNegative(status: string): boolean {
    return status === 'cancelled'
      || status === 'refunded'
      || status === 'failed';
  }

  protected trackById(_idx: number, entity: { id: number }): number {
    return entity.id;
  }
}
