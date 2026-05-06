import type { Product } from '../catalog/product.model';

/**
 * Category — matches the GET /v2/categories response item shape.
 *
 * Note on the `image` field: the backend's category.icon column historically
 * stores Lucide icon names (e.g. '@tui.sparkles') rather than image
 * filenames. As of W2.0 the v2 endpoints detect this and return
 * `image: null` while surfacing the Lucide name in `icon_name`. Until real
 * cover images exist for categories, we render a letter-fallback in the UI.
 */
export interface Category {
  id: number;
  slug: string;            // e.g. "abayas-1"  (slugified-name-{id})
  name: string;
  description: string | null;
  image: { url: string } | null;
  /** Lucide icon name (e.g. "@tui.sparkles") if the backend stored one. */
  icon_name?: string | null;
  product_count: number;
}

/**
 * CategoryDetail — matches the GET /v2/categories/:slug response shape.
 *
 * Extends Category with the embedded first-page list of products that the
 * detail endpoint returns alongside the category metadata. This means
 * `/category/:slug` can render the full landing page from a single API
 * call — no follow-up `/products?category=...` request needed.
 *
 * `meta.total_products` ≠ `product_count`. `product_count` is the raw
 * count in the category JOIN (includes products from non-approved
 * vendors). `total_products` is the count after WHERE filters
 * (published + store_approved). The latter is what we display.
 */
export interface CategoryDetail extends Category {
  /** First page of products (typically 20). */
  products: Product[];
}

/** Pagination meta returned alongside CategoryDetail. */
export interface CategoryDetailMeta {
  /** Approved+published count — the number we display to users. */
  total_products: number;
  page_size: number;
}
