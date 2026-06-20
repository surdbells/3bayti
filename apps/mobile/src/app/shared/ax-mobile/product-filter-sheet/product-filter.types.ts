/**
 * Shared types for the mobile product-filter sheet (web-parity filtering).
 *
 * Mirrors the v3 GET /v3/products + GET /v3/products/facets vocabulary so
 * the three listing pages (best-sellers, new-arrivals, category) can offer
 * the same SORT / SIZE / COLOR / PRICE refinement apps/web exposes.
 */

/**
 * Sort values accepted by GET /v3/products. `relevance` is intentionally
 * omitted from the listing UI — it only makes sense with a search query,
 * which these listings never carry.
 */
export type ProductSort =
  | 'newest'
  | 'oldest'
  | 'price_asc'
  | 'price_desc'
  | 'best_seller';

/** The sort options offered in the listing filter UI (no `relevance`). */
export const LISTING_SORTS: readonly ProductSort[] = [
  'newest',
  'oldest',
  'price_asc',
  'price_desc',
  'best_seller',
] as const;

/** A single selectable facet value with its live count. */
export interface FacetValue {
  value: string;
  count: number;
  /** Price-band bounds (decimal strings from the API). Price facet only. */
  min?: string | number | null;
  max?: string | number | null;
}

/**
 * The facet set returned by GET /v3/products/facets (unwrapped `data`).
 * Only the axes the sheet renders are typed; vendor/category are ignored.
 */
export interface ProductFacets {
  size?: { values: FacetValue[] };
  color?: { values: FacetValue[] };
  price?: { values: FacetValue[] };
}

/**
 * The active filter selection owned by a listing page and emitted back
 * from the sheet on Apply / Clear all.
 */
export interface ProductFilterState {
  sort: ProductSort;
  sizes: string[];
  colors: string[];
  minPrice: number | null;
  maxPrice: number | null;
}
