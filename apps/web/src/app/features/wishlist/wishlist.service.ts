import { Injectable, signal, computed, Signal, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import type { Product } from '../catalog/product.model';

const V3_BASE = 'https://api-v3.3bayti.ae';

/** Paginated envelope meta, as returned by the API. */
export interface WishlistMeta {
  total: number;
  limit: number;
  offset: number;
  has_more: boolean;
}

/**
 * WishlistService — the authenticated user's saved products.
 *
 * Uses the direct Bearer HttpClient (auth attached by the interceptor),
 * consistent with MeasurementService / account deletion — these
 * endpoints aren't in the RoutedHttpClient registry.
 *
 * State model
 * -----------
 * A signal-backed Set of saved product ids drives the "is this saved?"
 * UI (the heart on ProductCard) across the app, so toggling on one
 * surface reflects everywhere instantly. The full product list is held
 * separately for the /account/wishlist page.
 *
 * add/remove are optimistic-friendly but here kept simple: the saved
 * set is updated after the API confirms. toggle() picks add or remove
 * based on the current set.
 *
 * Endpoints:
 *   GET    /v3/me/wishlist?limit=&offset= → { data: Product[], meta }
 *   POST   /v3/me/wishlist { product_id }  → { data: Product } (idempotent)
 *   DELETE /v3/me/wishlist/{productId}      → 204 (idempotent)
 */
@Injectable({ providedIn: 'root' })
export class WishlistService {
  private readonly http = inject(HttpClient);

  /** Saved product ids — the source of truth for the heart UI. */
  private readonly _savedIds = signal<ReadonlySet<number>>(new Set());
  readonly savedIds: Signal<ReadonlySet<number>> = this._savedIds.asReadonly();

  /** Accumulated product list for the wishlist page. */
  private readonly _products = signal<Product[]>([]);
  readonly products: Signal<Product[]> = this._products.asReadonly();

  private readonly _meta = signal<WishlistMeta | null>(null);
  readonly meta: Signal<WishlistMeta | null> = this._meta.asReadonly();

  private readonly _isLoading = signal<boolean>(false);
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();

  /** Count of saved items (from the latest meta, else the page list). */
  readonly count = computed<number>(() => this._meta()?.total ?? this._products().length);

  /** Whether a given product id is currently saved. */
  isSaved(productId: number): boolean {
    return this._savedIds().has(productId);
  }

  /**
   * Load a page of saved products. offset=0 replaces the list (fresh
   * load); offset>0 appends (load-more). The saved-id set is updated
   * from the returned products.
   */
  async load(limit = 24, offset = 0): Promise<void> {
    this._isLoading.set(true);
    try {
      const res = await firstValueFrom(
        this.http.get<{ data: Product[]; meta: WishlistMeta }>(
          `${V3_BASE}/v3/me/wishlist?limit=${limit}&offset=${offset}`,
        ),
      );
      const page = res.data ?? [];
      const list = offset === 0 ? page : [...this._products(), ...page];
      this._products.set(list);
      this._meta.set(res.meta ?? null);
      /* Merge the page's ids into the saved set. */
      const next = new Set(this._savedIds());
      for (const p of page) next.add(p.id);
      this._savedIds.set(next);
    } finally {
      this._isLoading.set(false);
    }
  }

  /** Load the next page if there's more. No-op otherwise. */
  async loadMore(): Promise<void> {
    const meta = this._meta();
    if (meta === null || !meta.has_more) return;
    await this.load(meta.limit, meta.offset + meta.limit);
  }

  /** Save a product. Idempotent server-side; updates the saved set. */
  async add(product: Product): Promise<void> {
    await firstValueFrom(
      this.http.post<{ data: Product }>(`${V3_BASE}/v3/me/wishlist`, {
        product_id: product.id,
      }),
    );
    const next = new Set(this._savedIds());
    next.add(product.id);
    this._savedIds.set(next);
  }

  /**
   * Un-save a product. Idempotent server-side; updates the saved set
   * and prunes the product from the page list if present.
   */
  async remove(productId: number): Promise<void> {
    await firstValueFrom(
      this.http.delete<void>(`${V3_BASE}/v3/me/wishlist/${productId}`),
    );
    const next = new Set(this._savedIds());
    next.delete(productId);
    this._savedIds.set(next);
    this._products.set(this._products().filter(p => p.id !== productId));
    const meta = this._meta();
    if (meta !== null) {
      this._meta.set({ ...meta, total: Math.max(0, meta.total - 1) });
    }
  }

  /** Toggle saved state based on the current set. */
  async toggle(product: Product): Promise<void> {
    if (this.isSaved(product.id)) {
      await this.remove(product.id);
    } else {
      await this.add(product);
    }
  }

  /** Reset all local state (e.g. on logout). */
  reset(): void {
    this._savedIds.set(new Set());
    this._products.set([]);
    this._meta.set(null);
  }
}
