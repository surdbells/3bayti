import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

/** A wishlist label ("closet"), mirroring WishlistLabelSerializer. */
export interface WishlistLabel {
  id: number;
  name: string;
  count: number;
  created_at: string;
}

/**
 * A saved product (ProductSerializer list shape — only the fields the
 * mobile wishlist UI needs are typed; the rest pass through).
 */
export interface WishlistProduct {
  id: number;
  slug?: string;
  name: string;
  price?: unknown;
  sale_price?: unknown;
  primary_image?: { url?: string } | null;
  vendor?: { id?: number; name?: string } | null;
  [key: string]: unknown;
}

export interface WishlistPage {
  products: WishlistProduct[];
  total: number;
  hasMore: boolean;
}

/**
 * WishlistService (mobile) — the v3 label-aware wishlist.
 *
 * Replaces the legacy GlobalComponent.readWishlist / addWishlistLabel /
 * readWishlistLabel "closets" endpoints with the v3 /v3/me/wishlist
 * surface (Y.6-C flat wishlist extended with labels in Z.3-API).
 *
 * Route-keys (resolved by MobileNetworkAdapter via @3bayti/api-client):
 *   GET    /me/wishlist[?label_id=]      → saved products (paginated)
 *   POST   /me/wishlist {product_id,label_id?}
 *   PATCH  /me/wishlist/:productId {label_id}   (move between labels)
 *   DELETE /me/wishlist/:productId
 *   GET    /me/wishlist/labels           → labels with counts
 *   POST   /me/wishlist/labels {name}
 *   PATCH  /me/wishlist/labels/:id {name}
 *   DELETE /me/wishlist/labels/:id
 */
@Injectable({ providedIn: 'root' })
export class WishlistService {
  constructor(private adapter: MobileNetworkAdapter) {}

  private ok(res: any): boolean {
    return res?.response_code >= 200 && res?.response_code < 300 && res?.status === 'success';
  }

  /** List saved products, optionally filtered by label. */
  async listProducts(
    token: string,
    opts: { labelId?: number | 'none' | null; limit?: number; offset?: number } = {},
  ): Promise<WishlistPage> {
    const query: Record<string, string | number> = {
      limit: opts.limit ?? 24,
      offset: opts.offset ?? 0,
    };
    if (opts.labelId === 'none' || opts.labelId === null) {
      query['label_id'] = 'none';
    } else if (typeof opts.labelId === 'number') {
      query['label_id'] = opts.labelId;
    }

    const res: any = await firstValueFrom(
      this.adapter.get_v3('GET /me/wishlist', { authToken: token, queryParams: query }),
    );
    if (this.ok(res)) {
      return {
        products: Array.isArray(res.data) ? res.data : [],
        total: res.meta?.total ?? (Array.isArray(res.data) ? res.data.length : 0),
        hasMore: res.meta?.has_more ?? false,
      };
    }
    return { products: [], total: 0, hasMore: false };
  }

  /** Save a product, optionally into a label. Idempotent server-side. */
  async add(token: string, productId: number, labelId?: number | null): Promise<boolean> {
    const body: Record<string, number> = { product_id: productId };
    if (typeof labelId === 'number' && labelId > 0) body['label_id'] = labelId;
    const res: any = await firstValueFrom(
      this.adapter.post_v3('POST /me/wishlist', body, { authToken: token }),
    );
    return this.ok(res);
  }

  /** Remove a product from the wishlist. Idempotent server-side. */
  async remove(token: string, productId: number): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.delete_v3('DELETE /me/wishlist/:productId', {
        authToken: token,
        pathParams: { productId: String(productId) },
      }),
    );
    return this.ok(res) || res?.response_code === 204;
  }

  /** Move a saved product to a label (labelId null/0 = uncategorized). */
  async move(token: string, productId: number, labelId: number | null): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.patch_v3('PATCH /me/wishlist/:productId', { label_id: labelId ?? 0 }, {
        authToken: token,
        pathParams: { productId: String(productId) },
      }),
    );
    return this.ok(res) || res?.response_code === 204;
  }

  /** List the user's labels with per-label counts. */
  async listLabels(token: string): Promise<WishlistLabel[]> {
    const res: any = await firstValueFrom(
      this.adapter.get_v3('GET /me/wishlist/labels', { authToken: token }),
    );
    if (this.ok(res)) {
      return Array.isArray(res.data) ? res.data : [];
    }
    return [];
  }

  /** Create a label. Idempotent on name server-side. */
  async createLabel(token: string, name: string): Promise<WishlistLabel | null> {
    const res: any = await firstValueFrom(
      this.adapter.post_v3('POST /me/wishlist/labels', { name }, { authToken: token }),
    );
    return this.ok(res) ? (res.data as WishlistLabel) : null;
  }

  /** Rename a label. */
  async renameLabel(token: string, id: number, name: string): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.patch_v3('PATCH /me/wishlist/labels/:id', { name }, {
        authToken: token,
        pathParams: { id: String(id) },
      }),
    );
    return this.ok(res);
  }

  /** Delete a label (its products fall back to uncategorized). */
  async deleteLabel(token: string, id: number): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.delete_v3('DELETE /me/wishlist/labels/:id', {
        authToken: token,
        pathParams: { id: String(id) },
      }),
    );
    return this.ok(res) || res?.response_code === 204;
  }
}
