import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';
import { ProductReview, ProductReviewPage } from '../../class/product-review';

/**
 * ProductReviewService (mobile), real product reviews for the PDP.
 *
 * Route-keys (resolved by MobileNetworkAdapter via @3bayti/api-client,
 * see feature-flags.ts):
 *   GET  /products/:productId/reviews[?limit=&offset=]  (public, approved-only)
 *   POST /products/:productId/reviews {star, title?, comment?}  (Bearer)
 *
 * The :productId is the NUMERIC v3 product id. The PDP loads the product by
 * legacy id, but ProductSerializer::detailShape surfaces the v3 PK as the
 * top-level `id`, which transformProductDetailResponse maps onto
 * `single.product`, pass THAT here, not the legacy id.
 *
 * GET returns ReviewSerializer::publicShape rows + a {total,limit,offset}
 * meta. POST upserts (one review per user+product) and always lands the
 * review as PENDING moderation, it is not visible until a moderator
 * approves it (201 new / 200 edited).
 */
@Injectable({ providedIn: 'root' })
export class ProductReviewService {
  constructor(private adapter: MobileNetworkAdapter) {}

  private ok(res: any): boolean {
    return res?.response_code >= 200 && res?.response_code < 300 && res?.status === 'success';
  }

  /** Normalise one publicShape row into the typed ProductReview. */
  private normalize(row: any): ProductReview {
    return {
      id: Number(row?.id ?? 0),
      product_id: Number(row?.product_id ?? 0),
      product_name: row?.product_name ?? null,
      reviewer: row?.reviewer ?? null,
      // v3 serialises star as a string; coerce + clamp to 0–5.
      star: Math.max(0, Math.min(5, Math.round(Number(row?.star ?? 0)) || 0)),
      title: row?.title ?? null,
      comment: row?.comment ?? null,
      vendor_reply: row?.vendor_reply ?? null,
      is_verified: row?.is_verified === true,
      helpful_count: Number(row?.helpful_count ?? 0),
      created_at: String(row?.created_at ?? ''),
    };
  }

  /**
   * List approved reviews for a product (newest first), paginated.
   * Public read, no auth token required.
   */
  async list(
    productId: number | string,
    opts: { limit?: number; offset?: number } = {},
  ): Promise<ProductReviewPage> {
    const limit = opts.limit ?? 10;
    const offset = opts.offset ?? 0;
    const res: any = await firstValueFrom(
      this.adapter.get_v3('GET /products/:productId/reviews', {
        pathParams: { productId: String(productId) },
        queryParams: { limit, offset },
      }),
    );
    if (this.ok(res)) {
      const rows = Array.isArray(res.data) ? res.data : [];
      const reviews = rows.map((r: any) => this.normalize(r));
      const total = Number(res.meta?.total ?? reviews.length);
      return { reviews, total, hasMore: offset + reviews.length < total };
    }
    return { reviews: [], total: 0, hasMore: false };
  }

  /**
   * Submit (upsert) a review. Bearer-authed; lands as PENDING moderation.
   * Returns true on a 2xx success envelope.
   */
  async submit(
    token: string,
    productId: number | string,
    input: { star: number; title?: string; comment?: string },
  ): Promise<boolean> {
    const body: Record<string, unknown> = { star: input.star };
    const title = (input.title ?? '').trim();
    const comment = (input.comment ?? '').trim();
    if (title) body['title'] = title;
    if (comment) body['comment'] = comment;
    const res: any = await firstValueFrom(
      this.adapter.post_v3('POST /products/:productId/reviews', body, {
        authToken: token,
        pathParams: { productId: String(productId) },
      }),
    );
    return this.ok(res);
  }
}
