import { Injectable, Signal, signal, computed, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import type {
  Order,
  OrderListItem,
  OrderListParams,
  OrderListResponse,
  SubmitReturnInput,
  ReturnRequestResponse,
} from './order.types';

/**
 * OrderService — auth-required reads + cancel against `/v3/orders`.
 *
 * Endpoints:
 *   GET    /v3/orders?limit=&offset=        → OrderListItem[]
 *   GET    /v3/orders/:id                   → Order (detail with addresses)
 *   POST   /v3/orders/:id/cancel            → Order (now cancelled)
 *
 * State surface (signals):
 *   - listItems()    Signal<OrderListItem[]>  — accumulating list for
 *                                              load-more pagination
 *   - hasMore()      computed boolean         — true while a subsequent
 *                                              page might exist
 *   - isLoadingList()
 *   - isLoadingDetail()
 *
 * Local state policy
 * ------------------
 * The list signal accumulates pages so the orders-list page can scroll
 * with load-more (Q-Pagination=B from Y.2 plan). reset() clears the
 * accumulator before a fresh load (e.g. on route re-entry).
 *
 * Detail isn't held in a service signal — pages own their detail
 * signal because two routes can be open simultaneously (e.g. orders
 * list cached while detail view is open in a tab).
 */

const V3_BASE = 'https://api-v3.3bayti.ae';

@Injectable({ providedIn: 'root' })
export class OrderService {
  private readonly _listItems = signal<OrderListItem[]>([]);
  private readonly _isLoadingList = signal<boolean>(false);
  private readonly _isLoadingDetail = signal<boolean>(false);

  /** Last page size received. We use this to decide hasMore: if the
   *  last fetch returned exactly `pageSize` items, there may be more
   *  to load; if it returned fewer (or zero), we're at the end. */
  private readonly _lastPageSize = signal<number>(0);
  private readonly _requestedPageSize = signal<number>(10);

  readonly listItems: Signal<OrderListItem[]> = this._listItems.asReadonly();
  readonly isLoadingList: Signal<boolean> = this._isLoadingList.asReadonly();
  readonly isLoadingDetail: Signal<boolean> = this._isLoadingDetail.asReadonly();

  readonly hasMore = computed(() => {
    return this._lastPageSize() === this._requestedPageSize() && this._lastPageSize() > 0;
  });

  /** Total number of orders currently loaded into the list accumulator. */
  readonly loadedCount = computed(() => this._listItems().length);

  private readonly http = inject(HttpClient);

  /**
   * Reset the list accumulator. Call before a fresh load (e.g. on
   * route re-entry after a return-from-detail).
   */
  reset(): void {
    this._listItems.set([]);
    this._lastPageSize.set(0);
  }

  /**
   * Load the next page of orders. Appends to the accumulator.
   * Pass { limit, offset } explicitly for the first call; subsequent
   * calls can omit offset (uses current loadedCount).
   */
  async loadMore(params: OrderListParams = {}): Promise<OrderListItem[]> {
    const limit = params.limit ?? this._requestedPageSize();
    const offset = params.offset ?? this.loadedCount();
    this._requestedPageSize.set(limit);

    return this.runWithLoadingList(async () => {
      const httpParams = new HttpParams()
        .set('limit', String(limit))
        .set('offset', String(offset));
      const response = await firstValueFrom(
        this.http.get<OrderListResponse>(`${V3_BASE}/v3/orders`, { params: httpParams }),
      );
      const page = Array.isArray(response) ? response : [];

      /* Append or replace based on offset. If offset === 0 we're
         doing a fresh load and clobber the accumulator; otherwise
         append. */
      if (offset === 0) {
        this._listItems.set(page);
      } else {
        this._listItems.set([...this._listItems(), ...page]);
      }
      this._lastPageSize.set(page.length);
      return page;
    });
  }

  /** Fetch a single order's full detail. */
  async getById(id: number): Promise<Order> {
    return this.runWithLoadingDetail(async () => {
      return firstValueFrom(this.http.get<Order>(`${V3_BASE}/v3/orders/${id}`));
    });
  }

  /**
   * Cancel an order (customer self-serve, only valid for
   * pending_payment status per the API).
   * Returns the updated order.
   */
  async cancel(id: number): Promise<Order> {
    return this.runWithLoadingDetail(async () => {
      const updated = await firstValueFrom(
        this.http.post<Order>(`${V3_BASE}/v3/orders/${id}/cancel`, {}),
      );
      /* Patch the cached list item if it's loaded. */
      const list = this._listItems();
      const idx = list.findIndex(o => o.id === id);
      if (idx >= 0) {
        const next = [...list];
        next[idx] = { ...next[idx], status: updated.status };
        this._listItems.set(next);
      }
      return updated;
    });
  }

  /**
   * Submit a customer return request for an order.
   *
   * Endpoint: POST /v3/orders/:id/returns (multipart/form-data)
   * Fields:
   *   - reason: one of ReturnReason
   *   - customer_notes: optional; required when reason='other'
   *   - order_item_ids[]: list of OrderItem ids to return
   *   - photos[]: 0-5 image files
   *
   * Returns the created return-request shape (currently typed as
   * unknown until Y.5 surfaces a return-detail UI — the caller only
   * needs to know the submission succeeded for now).
   */
  async submitReturn(
    orderId: number,
    input: SubmitReturnInput,
  ): Promise<ReturnRequestResponse> {
    return this.runWithLoadingDetail(async () => {
      const form = new FormData();
      form.set('reason', input.reason);
      if (input.customer_notes !== null && input.customer_notes !== undefined) {
        form.set('customer_notes', input.customer_notes);
      }
      for (const itemId of input.order_item_ids) {
        form.append('order_item_ids[]', String(itemId));
      }
      for (const photo of input.photos) {
        form.append('photos[]', photo, photo.name);
      }
      return firstValueFrom(
        this.http.post<ReturnRequestResponse>(
          `${V3_BASE}/v3/orders/${orderId}/returns`,
          form,
        ),
      );
    });
  }

  private async runWithLoadingList<T>(fn: () => Promise<T>): Promise<T> {
    this._isLoadingList.set(true);
    try {
      return await fn();
    } finally {
      this._isLoadingList.set(false);
    }
  }

  private async runWithLoadingDetail<T>(fn: () => Promise<T>): Promise<T> {
    this._isLoadingDetail.set(true);
    try {
      return await fn();
    } finally {
      this._isLoadingDetail.set(false);
    }
  }
}
