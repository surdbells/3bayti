import type { Product } from '../catalog/product.model';

/**
 * Collection, an admin-curated storefront edit. Matches one item of the
 * public GET /v3/collections response (active collections only, already in
 * admin display order).
 *
 * `image_url` is the image of the FIRST curated live product (falling back
 * to the collection's cover image, else null), so the storefront card is
 * fronted by whatever the admin put first. `product_count` counts only
 * storefront-visible products.
 */
export interface Collection {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  image_url: string | null;
  product_count: number;
  display_order: number | null;
}

/**
 * CollectionDetail, the GET /v3/collections/:slug payload. Same header
 * fields as the list item plus the embedded first page of product list
 * cards (same shape as GET /v3/products items), so `/collection/:slug` can
 * render a full grid from a single call before the filterable catalog
 * listing takes over in the browser.
 */
export interface CollectionDetail {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  image_url: string | null;
  product_count: number;
  products: Product[];
}

/** Meta returned alongside CollectionDetail. */
export interface CollectionDetailMeta {
  total_products: number;
  page_size: number;
}
