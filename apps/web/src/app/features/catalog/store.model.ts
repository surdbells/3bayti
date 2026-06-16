/**
 * Store (vendor) domain types — the customer-facing storefront view.
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

/** Storefront-facing store (vendor) — mirrors VendorSerializer::publicShape. */
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

/** Pagination params for the directory + per-store product lists. */
export interface StoreListParams {
  limit?: number;
  offset?: number;
}
