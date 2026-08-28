/**
 * Store (vendor) domain types, the customer-facing storefront view.
 *
 * "Store" is the customer-facing word; the API entity is `vendor`
 * (see apps/api VendorSerializer, whose storefront view is literally
 * called the "Store Spotlight"). These types mirror the v3
 * VendorSerializer::publicShape exactly.
 *
 * The directory + detail pages (Y.4) consume:
 *   GET /vendors                 → Store[]  (publicShape, paginated)
 *   GET /vendors/:slug           → Store    (publicShape)
 *   GET /vendors/:slug/products  → Product[]   (paginated)
 *   GET /featured-vendors        → FeaturedVendor[]  (Store Spotlight)
 *
 * FeaturedVendor (the spotlight shape with embedded products + rating)
 * already lives in features/catalog/store-card.ts and is re-exported
 * from the barrel; this file owns only the plain publicShape.
 */

import type { FeaturedVendor } from './store-card';

/** Storefront-facing store (vendor), mirrors VendorSerializer::publicShape. */
export interface Store {
  id: number;
  slug: string;
  name: string;
  /**
   * Short description / tagline. May contain inline HTML authored via
   * the admin app; the backend sanitises at write time. The detail
   * page renders it via [innerHTML].
   */
  description: string | null;
  logo_url: string | null;
  cover_image_url: string | null;
  is_verified: boolean;
}

/**
 * Directory store card (Stores H2.B), the shape GET /vendors now returns
 * (VendorSerializer::directoryShape): a FeaturedVendor (embedded products +
 * rating, rendered by StoreCard) PLUS the publicShape identity fields, so
 * the directory grid renders with the same <ui-store-card> as the Spotlight.
 */
export interface DirectoryStore extends FeaturedVendor {
  id: number;
  logo_url: string | null;
  cover_image_url: string | null;
  is_verified: boolean;
}

/** Pagination params for the directory + per-store product lists. */
export interface StoreListParams {
  limit?: number;
  offset?: number;
  /**
   * Optional label (collection) slug to filter a store's products by, the
   * storefront chip filter. When set, the product fetch routes through the
   * generic /products endpoint (?vendor=&label=) instead of the plain
   * vendor-products list. Absent/empty = the store's full catalogue.
   */
  label?: string;
}

/**
 * A store's merchandising collection ("label"), mirrors
 * VendorLabelSerializer::publicShape from GET /v3/vendors/{slug}/labels.
 * Rendered as filter chips above the product grid on the store detail page.
 */
export interface VendorLabel {
  id: number;
  slug: string;
  name: string;
  display_order: number | null;
  /** Active-product count in this collection, shown as a chip badge. */
  count: number;
}

/**
 * A public store review, what GET /v3/vendors/{slug}/reviews returns
 * (ReviewSerializer::publicShape). Covers both product reviews and the
 * migrated store-level reviews (product_id null). Never exposes status
 * or the reviewer's email.
 */
export interface StoreReview {
  id: number;
  /** Null for store-level (migrated) reviews. */
  product_id: number | null;
  product_name?: string | null;
  /** Reviewer display name. */
  reviewer: string;
  /** Star rating 1–5. */
  star: number;
  title?: string | null;
  comment?: string | null;
  /** Optional vendor reply to the review. */
  vendor_reply?: string | null;
  is_verified?: boolean;
  helpful_count?: number;
  created_at?: string | null;
}

/** Result of a paginated store-reviews fetch. */
export interface StoreReviewsPage {
  items: StoreReview[];
  total: number;
  hasMore: boolean;
}
