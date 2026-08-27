import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor, NgClass, DatePipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { OrderService, ORDER_STATUS_LABELS } from '../../core/orders';
import type { Order, OrderTimelineEvent } from '../../core/orders';
import { ToastService } from '../../shared/forms';
import { ConfirmModalComponent } from '../../shared/ui';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { decodeHtmlEntities } from '../../shared/util/decode-html-entities';

/** One step in the client-derived fallback stepper (used only when the
 *  server timeline feed is unavailable). */
interface TimelineStep {
  key: string;
  labelKey: string;
  /** done = reached earlier, current = latest reached, upcoming = not yet,
   *  ended = a terminal state (cancelled/refunded/failed). */
  state: 'done' | 'current' | 'upcoming' | 'ended';
  /** ISO timestamp when known (placed + paid only); null otherwise. */
  at: string | null;
}

/** A server timeline event, normalized for rendering (adds a marker state). */
interface TimelineEventView {
  id: string;
  occurred_at: string | null;
  summary: string;
  /** 'ended' = a terminal/negative event (cancel/refund/denied) → danger marker. */
  state: 'done' | 'ended';
}

/**
 * /account/orders/:id, single-order detail page.
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
 * accessible, focus-trapped, and unambiguous, perfectly acceptable
 * for a self-serve cancel until the design system delivers a modal.
 *
 * Returns submission
 * ------------------
 * The "Request a return" CTA is rendered when the order is
 * eligible (status in {paid, fulfilling, shipped, delivered} per
 * apps/api business rules). The actual return form is Y.2-J's
 * scope, for now the CTA can navigate to a future
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
  imports: [CfImagePipe, NgIf, NgFor, NgClass, DatePipe, RouterLink, TranslatePipe, ConfirmModalComponent],
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

          <section class="checkout-section" data-testid="order-detail-timeline">
            <h2 class="checkout-section__title">
              {{ 'orders.timeline.heading' | translate }}
            </h2>
            <ol class="order-timeline" role="list">
              <ng-container *ngIf="timelineEvents().length > 0; else derivedSteps">
                <!-- Full server event feed (chronological, oldest→newest). -->
                <li
                  *ngFor="let ev of timelineEvents()"
                  class="order-timeline__step"
                  [class.order-timeline__step--ended]="ev.state === 'ended'"
                  [class.order-timeline__step--done]="ev.state === 'done'"
                >
                  <span class="order-timeline__marker" aria-hidden="true"></span>
                  <div class="order-timeline__body">
                    <span class="order-timeline__label">{{ ev.summary }}</span>
                    <time *ngIf="ev.occurred_at" class="order-timeline__time">
                      {{ ev.occurred_at | date:'medium' }}
                    </time>
                  </div>
                </li>
              </ng-container>
              <ng-template #derivedSteps>
                <!-- Fallback: client-derived status stepper. -->
                <li
                  *ngFor="let step of timelineFallback()"
                  class="order-timeline__step"
                  [ngClass]="'order-timeline__step--' + step.state"
                >
                  <span class="order-timeline__marker" aria-hidden="true"></span>
                  <div class="order-timeline__body">
                    <span class="order-timeline__label">{{ step.labelKey | translate }}</span>
                    <time *ngIf="step.at" class="order-timeline__time">
                      {{ step.at | date:'medium' }}
                    </time>
                  </div>
                </li>
              </ng-template>
            </ol>
          </section>

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
                    [src]="item.product_image | cfImage:'thumb'"
                    alt=""
                    loading="lazy"
                  />
                  <ng-template #thumbBlank>
                    <div class="review-item__thumb-placeholder"></div>
                  </ng-template>
                </div>
                <div class="review-item__body">
                  <p class="review-item__name">
                    {{ displayName(item.product_name) || ('cart.drawer.unnamedItem' | translate) }}
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

          <section
            *ngIf="order()!.shipping_address as addr"
            class="checkout-section"
            data-testid="order-detail-shipping"
          >
            <h2 class="checkout-section__title">
              {{ 'orders.detail.shippingHeading' | translate }}
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

          <section
            *ngIf="order()!.billing_address as addr"
            class="checkout-section"
            data-testid="order-detail-billing"
          >
            <h2 class="checkout-section__title">
              {{ 'orders.detail.billingHeading' | translate }}
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

      <ui-confirm-modal
        [open]="showCancelConfirm()"
        [title]="'orders.detail.cancelConfirmTitle'"
        [message]="confirmMessage()"
        [confirmLabel]="'orders.detail.cancelConfirmYes'"
        [cancelLabel]="'orders.detail.cancelConfirmNo'"
        [danger]="true"
        [busy]="isCancelling()"
        (confirm)="onCancelConfirmed()"
        (cancel)="onCancelDismissed()"
      ></ui-confirm-modal>
    </main>
  `,
  styleUrl: './order-detail.scss',
})
export class AccountOrderDetailPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly orderService = inject(OrderService);
  private readonly toast = inject(ToastService);

  private readonly _order = signal<Order | null>(null);
  protected readonly order = this._order.asReadonly();
  protected readonly isLoading = this.orderService.isLoadingDetail;

  /** Full server-side event feed. When populated it replaces the derived
   *  stepper; empty → the template falls back to timelineFallback(). */
  private readonly _timelineEvents = signal<TimelineEventView[]>([]);
  protected readonly timelineEvents = this._timelineEvents.asReadonly();
  private readonly _errored = signal(false);
  protected readonly errored = this._errored.asReadonly();
  private readonly _isCancelling = signal(false);
  protected readonly isCancelling = this._isCancelling.asReadonly();

  private readonly _showCancelConfirm = signal(false);
  protected readonly showCancelConfirm = this._showCancelConfirm.asReadonly();

  protected readonly canCancel = computed(() => {
    const o = this._order();
    return o !== null && o.status === 'pending_payment' && !this._isCancelling();
  });

  /**
   * Return-eligibility (UI-side gate, server has final say).
   * Shows the CTA for any status where a return could plausibly be
   * filed, the server enforces the 14-day window + per-item
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
      /* Best-effort event feed, never blocks the detail render, and the
         template gracefully falls back to the derived stepper on empty. */
      void this.loadTimeline(id);
    } catch {
      this._errored.set(true);
      this.toast.error('orders.detail.loadError');
    }
  }

  /**
   * Fetch the customer event feed (GET /v3/orders/:id/timeline) and map it
   * to view models. Terminal/negative events (cancel/refund/denied/failed)
   * get an 'ended' marker; everything else is 'done'. Summaries are decoded
   * for the same HTML-entity reason as product names.
   */
  private async loadTimeline(id: number): Promise<void> {
    const events = await this.orderService.getTimeline(id);
    this._timelineEvents.set(
      events.map((e: OrderTimelineEvent) => ({
        id: String(e.id ?? ''),
        occurred_at: e.occurred_at ?? null,
        summary: decodeHtmlEntities(e.summary),
        state: /refund|denied|cancel|failed/i.test(e.type ?? '') ? 'ended' : 'done',
      })),
    );
  }

  /* -----------------------------------------------------------------
     Timeline, client-derived fallback stepper
     -----------------------------------------------------------------
     Only rendered when the server feed is empty (endpoint unavailable).
     Honest by design: only "placed" + "paid" carry real timestamps; later
     stages render as reached/current/upcoming without a time. Terminal
     states (cancelled/refunded/failed) end the track. Mirrors the mobile
     order-detail stepper. */

  private static readonly LIFECYCLE: { key: string; labelKey: string; statuses: string[] }[] = [
    { key: 'paid', labelKey: 'orders.status.paid', statuses: ['paid', 'fulfilling', 'shipping', 'shipped', 'delivered'] },
    { key: 'fulfilling', labelKey: 'orders.status.fulfilling', statuses: ['fulfilling', 'shipping', 'shipped', 'delivered'] },
    { key: 'shipped', labelKey: 'orders.status.shipped', statuses: ['shipping', 'shipped', 'delivered'] },
    { key: 'delivered', labelKey: 'orders.status.delivered', statuses: ['delivered'] },
  ];
  private static readonly TERMINAL: Record<string, string> = {
    cancelled: 'orders.status.cancelled',
    refunded: 'orders.status.refunded',
    failed: 'orders.status.failed',
  };

  protected readonly timelineFallback = computed<TimelineStep[]>(() => {
    const o = this._order();
    if (o === null) return [];
    const status = o.status;
    const steps: TimelineStep[] = [
      { key: 'placed', labelKey: 'orders.timeline.placed', state: 'done', at: o.date },
    ];

    const terminalKey = AccountOrderDetailPageComponent.TERMINAL[status];
    if (terminalKey) {
      if (o.paid_at) {
        steps.push({ key: 'paid', labelKey: 'orders.status.paid', state: 'done', at: o.paid_at });
      }
      steps.push({ key: status, labelKey: terminalKey, state: 'ended', at: null });
      return steps;
    }

    const doneFlags = AccountOrderDetailPageComponent.LIFECYCLE.map((s) => s.statuses.includes(status));
    const lastDone = doneFlags.lastIndexOf(true);
    AccountOrderDetailPageComponent.LIFECYCLE.forEach((s, i) => {
      const state: TimelineStep['state'] = doneFlags[i]
        ? (i === lastDone ? 'current' : 'done')
        : 'upcoming';
      steps.push({ key: s.key, labelKey: s.labelKey, state, at: s.key === 'paid' ? o.paid_at : null });
    });
    if (lastDone === -1) {
      steps[0].state = 'current';
    }
    return steps;
  });

  /** Decode HTML entities in a product/line name (fixes literal `&amp;`). */
  protected displayName(name: string | null | undefined): string {
    return decodeHtmlEntities(name);
  }

  /* -----------------------------------------------------------------
     Cancel
     ----------------------------------------------------------------- */

  /**
   * Cancel button → open the confirm modal. The actual cancel happens
   * in onCancelConfirmed when the user confirms. (Replaced the native
   * window.confirm() with the reusable ConfirmModal in Y.5-C.)
   */
  protected onCancelClick(): void {
    if (!this.canCancel()) return;
    if (this._order() === null) return;
    this._showCancelConfirm.set(true);
  }

  /** User dismissed the confirm modal, close it, do nothing. */
  protected onCancelDismissed(): void {
    this._showCancelConfirm.set(false);
  }

  /** User confirmed cancellation in the modal, perform the cancel. */
  protected async onCancelConfirmed(): Promise<void> {
    const order = this._order();
    if (order === null) {
      this._showCancelConfirm.set(false);
      return;
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
      this._showCancelConfirm.set(false);
    }
  }

  /** Confirm message i18n key, used by the modal's [message]. */
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
