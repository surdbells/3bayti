/**
 * Catalog domain types — matched to the v2 OpenAPI spec
 * (docs/api-v2.openapi.yaml).
 *
 * The shape here is what GET /v2/products and GET /v2/products/:slug
 * return after envelope unwrapping. ApiClientService.getList<Product>()
 * + getOne<ProductDetail>() handle the unwrapping.
 *
 * `?` is used liberally because:
 *   - The OpenAPI spec marks several fields nullable
 *   - The backend may not yet populate every documented field (the
 *     existing data has gaps — vendors with no logo, products with no
 *     SKU, etc.). Defensive client code keeps rendering working when
 *     fields are missing, instead of throwing.
 */

/** Money amount paired with currency code. */
export interface Money {
  amount: number;
  currency: string; // e.g. 'AED'
}

/** Image with optional dimensions + alt text. */
export interface ApiImage {
  url: string;
  alt?: string;
  width?: number | null;
  height?: number | null;
}

/** Vendor reference embedded in product responses. */
export interface VendorRef {
  slug: string;
  name: string;
}

/**
 * Compact product representation used in lists (category, search,
 * vendor storefront). Doesn't include description or all images.
 */
export interface Product {
  id: number;
  slug: string;
  name: string;
  sku?: string;
  price: Money;
  sale_price?: Money | null;
  primary_image: ApiImage | null;
  category_slug?: string;
  vendor?: VendorRef;
  rating?: number | null;
  review_count?: number;
  in_stock: boolean;
  is_new?: boolean;
  is_bestseller?: boolean;
}

/** Available size + per-size stock indicator. */
export interface ProductSize {
  label: string;
  in_stock: boolean;
}

/** Available color + per-color stock indicator. */
export interface ProductColor {
  label: string;
  hex_code?: string;
  in_stock: boolean;
}

/** A single review entry as returned in product detail. */
export interface ProductReview {
  id: number;
  rating: number;
  title?: string | null;
  body: string;
  author: string;
  created_at?: string | null;
  verified?: boolean;
}

/**
 * Full product detail — what the PDP route receives.
 * Extends Product with the heavy fields not needed in card grids.
 */
export interface ProductDetail extends Product {
  description: string;
  images: ApiImage[];
  sizes?: ProductSize[];
  colors?: ProductColor[];
  fabric?: string | null;
  care_instructions?: string | null;
  materials?: string[];
  related_products?: Product[];
  recent_reviews?: ProductReview[];
}
