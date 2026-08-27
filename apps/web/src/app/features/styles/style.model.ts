/**
 * Style (Style Hub) domain types, the customer-facing curated-outfit view.
 *
 * A "style" is a curated outfit that bundles up to ~4 products. Two
 * provenances exist (style_type):
 *   - 'community' , submitted by shoppers (the "Community" tab).
 *   - 'editorial' , curated by 3bayti (the "3bayti" / "Editorial" tab).
 *
 * These mirror the v3 StyleSerializer shapes exactly.
 *
 * The hub + detail pages consume:
 *   GET /styles?type={community|editorial} → Style[]  (listShape, paginated)
 *   GET /styles/:slug                       → Style    (detailShape)
 *   GET /me/styles                          → Style[]  (owner-scoped, auth)
 *   POST /me/styles                         → Style    (create)
 *
 * IMPORTANT: `total_price` is a DISPLAY-ONLY string sum of the bundled
 * products' prices, it is NOT a checkout amount and must never be sent
 * to cart/checkout. Use each StyleProduct's v3 `id` for cart / wishlist /
 * PDP links (NOT `legacy_product_id`, which is only useful for legacy
 * deep-links).
 */

/** Style provenance, community-submitted vs 3bayti-curated. */
export type StyleType = 'community' | 'editorial';

/** A single product bundled into a style. */
export interface StyleProduct {
  /** v3 product id, use this for cart / wishlist / PDP. */
  id: number;
  /** Legacy product id, when present (deep-link compatibility only). */
  legacy_product_id?: number;
  slug: string;
  name: string;
  /** Primary product image URL; may be absent (card shows a fallback). */
  primary_image_url?: string | null;
  /** Display price string (e.g. "AED 320.00"). */
  price: string;
  /** Server-assigned position within the style (ascending). */
  display_order: number;
}

/** A curated outfit bundling up to ~4 products. */
export interface Style {
  id: number;
  slug: string;
  name: string;
  description?: string | null;
  /** Cover image URL; may be absent (the hub card collages product images). */
  cover_image_url?: string | null;
  style_type: StyleType;
  /** Display-only string sum of the bundled product prices. NOT a checkout amount. */
  total_price: string;
  /** The bundled products, in display order. */
  products: StyleProduct[];
}

/** Pagination params for the style lists. */
export interface StyleListParams {
  limit?: number;
  offset?: number;
}

/** Result of a paginated style fetch, items plus whether more exist. */
export interface StylesPage {
  items: Style[];
  hasMore: boolean;
}

/** Body for creating a community style. `products` = up to 4 v3 product ids. */
export interface CreateStyleInput {
  name: string;
  products: number[];
}
