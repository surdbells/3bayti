import {
  Component,
  ChangeDetectionStrategy,
  inject,
  OnInit,
  computed,
} from '@angular/core';
import { NgIf, NgFor, DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { OrderService, ORDER_STATUS_LABELS } from '../../core/orders';
import type { OrderListItem } from '../../core/orders';
import { ToastService } from '../../shared/forms';

/**
 * /account/orders — paginated order history.
 *
 * Auth-gated. Bottom-of-list "Load more" button advances the
 * accumulator (Q-Pagination=B from the Y.2 plan: load-more, not
 * page-numbers). 10 orders per page.
 *
 * Layout
 * ------
 *   - Page header
 *   - Order cards: status pill, date, item count, total, "View" CTA
 *     Each card is a routerLink to /account/orders/:id (Y.2-I)
 *   - Empty state when no orders
 *   - "Load more" button at the bottom of the list (when hasMore)
 *   - Spinner row when isLoadingList
 *
 * Page lifecycle
 * --------------
 *   - ngOnInit:
 *       reset()           - in case the user navigated here from a
 *                           context where prior pages were loaded
 *                           with a different filter (none today, but
 *                           defensive for future filters)
 *       loadMore()        - first page (offset=0, limit=10)
 *   - "Load more" click:
 *       loadMore()        - next page (offset=loadedCount, limit=10)
 *
 * Error handling
 * --------------
 * Failures toast common.errors.unknown. The user can retry by clicking
 * Load more again. We don't auto-retry to avoid hammering the API.
 */
@Component({
  selector: 'app-account-orders',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="orders-page" data-testid="orders-page">
      <div class="orders-page__container">
        <header class="orders-page__header">
          <h1 class="orders-page__title">
            {{ 'orders.list.title' | translate }}
          </h1>
          <p class="orders-page__subtitle">
            {{ 'orders.list.subtitle' | translate }}
          </p>
        </header>

        <ng-container *ngIf="orders().length > 0; else emptyOrLoading">
          <ul class="orders-list" role="list" data-testid="orders-list">
            <li
              *ngFor="let order of orders(); trackBy: trackById"
              class="orders-list__item"
              data-testid="orders-list-item"
            >
              <a
                [routerLink]="['/account/orders', order.id]"
                class="order-card"
                [attr.data-order-id]="order.id"
              >
                <div class="order-card__head">
                  <span
                    class="order-card__status"
                    [class.order-card__status--positive]="isPositive(order.status)"
                    [class.order-card__status--neutral]="isNeutral(order.status)"
                    [class.order-card__status--warning]="isWarning(order.status)"
                    [class.order-card__status--negative]="isNegative(order.status)"
                    [attr.data-status]="order.status"
                  >
                    {{ statusLabel(order.status) | translate }}
                  </span>
                  <time class="order-card__date">
                    {{ order.date | date:'mediumDate' }}
                  </time>
                </div>

                <p class="order-card__ref">{{ order.order_reference }}</p>

                <div class="order-card__items">
                  <ng-container *ngFor="let item of previewItems(order); trackBy: trackById">
                    <div class="order-card__thumb" aria-hidden="true">
                      <img
                        *ngIf="(item.product_image ?? '') !== ''; else thumbBlank"
                        [src]="item.product_image"
                        alt=""
                        loading="lazy"
                      />
                      <ng-template #thumbBlank>
                        <div class="order-card__thumb-blank"></div>
                      </ng-template>
                    </div>
                  </ng-container>
                  <span
                    *ngIf="moreItemsCount(order) > 0"
                    class="order-card__more"
                  >
                    +{{ moreItemsCount(order) }}
                  </span>
                </div>

                <div class="order-card__foot">
                  <span class="order-card__count">
                    {{ totalQuantity(order) }} {{ 'orders.list.itemCount' | translate }}
                  </span>
                  <span class="order-card__total">
                    {{ order.currency }} {{ order.total }}
                  </span>
                </div>
              </a>
            </li>
          </ul>

          <div
            *ngIf="hasMore()"
            class="orders-page__load-more"
            data-testid="orders-load-more-wrap"
          >
            <button
              type="button"
              class="orders-page__load-more-btn"
              [disabled]="isLoading()"
              (click)="onLoadMore()"
              data-testid="orders-load-more"
            >
              {{ (isLoading() ? 'common.loading' : 'orders.list.loadMore') | translate }}
            </button>
          </div>
        </ng-container>

        <ng-template #emptyOrLoading>
          <div
            *ngIf="isLoading()"
            class="orders-page__loading"
            data-testid="orders-loading"
          >
            {{ 'common.loading' | translate }}
          </div>
          <div
            *ngIf="!isLoading()"
            class="orders-page__empty"
            data-testid="orders-empty"
          >
            <h2 class="orders-page__empty-title">
              {{ 'orders.list.emptyTitle' | translate }}
            </h2>
            <p class="orders-page__empty-body">
              {{ 'orders.list.emptyBody' | translate }}
            </p>
            <a routerLink="/category" class="orders-page__empty-cta">
              {{ 'orders.list.emptyCta' | translate }}
            </a>
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './orders.scss',
})
export class AccountOrdersPageComponent implements OnInit {
  private readonly orderService = inject(OrderService);
  private readonly toast = inject(ToastService);

  protected readonly orders = this.orderService.listItems;
  protected readonly isLoading = this.orderService.isLoadingList;
  protected readonly hasMore = this.orderService.hasMore;

  /** True once we've completed at least one load (so the empty state
   *  doesn't flash on first paint while we're still fetching). */
  protected readonly hasLoadedOnce = computed(() => {
    return !this.isLoading() && (this.orders().length > 0 || this._didFirstLoad);
  });

  private _didFirstLoad = false;

  async ngOnInit(): Promise<void> {
    /* Reset the accumulator on every visit so we always start from
       offset=0. The cached list might be stale (we don't get push
       updates from the API). */
    this.orderService.reset();
    await this.onLoadMore();
  }

  protected async onLoadMore(): Promise<void> {
    try {
      await this.orderService.loadMore();
      this._didFirstLoad = true;
    } catch {
      this.toast.error('orders.list.loadError');
    }
  }

  /* -----------------------------------------------------------------
     Status pill classification
     ----------------------------------------------------------------- */

  protected statusLabel(status: string): string {
    return ORDER_STATUS_LABELS[status] ?? status;
  }

  protected isPositive(status: string): boolean {
    return status === 'delivered' || status === 'paid';
  }
  protected isNeutral(status: string): boolean {
    return status === 'preparing' || status === 'shipped';
  }
  protected isWarning(status: string): boolean {
    return status === 'pending_payment';
  }
  protected isNegative(status: string): boolean {
    return status === 'cancelled'
      || status === 'refunded'
      || status === 'partially_refunded';
  }

  /* -----------------------------------------------------------------
     Item thumbnail preview
     ----------------------------------------------------------------- */

  /** Show at most 3 thumbnails, with "+N" for the overflow. */
  protected previewItems(order: OrderListItem): OrderListItem['items'] {
    return order.items.slice(0, 3);
  }
  protected moreItemsCount(order: OrderListItem): number {
    return Math.max(0, order.items.length - 3);
  }

  /** Total quantity across all items (1 item × 3 qty = 3 items shown). */
  protected totalQuantity(order: OrderListItem): number {
    return order.items.reduce((sum, item) => sum + item.quantity, 0);
  }

  protected trackById(_idx: number, entity: { id: number }): number {
    return entity.id;
  }
}
