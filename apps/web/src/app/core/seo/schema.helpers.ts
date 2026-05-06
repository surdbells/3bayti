/**
 * Schema.org JSON-LD builders.
 *
 * These helpers produce well-formed schema.org objects suitable for
 * passing to `SeoService.setStructuredData()`. Keeps the structured
 * data declaration declarative + typed at call sites instead of
 * having to remember property names like `aggregateRating` vs
 * `aggregate_rating`.
 *
 * Reference: https://schema.org/docs/full.html and
 *            https://developers.google.com/search/docs/appearance/structured-data
 *
 * Add new builders here as we ship new pages with rich-result eligibility:
 *   - Product (PDP) — done
 *   - BreadcrumbList — done
 *   - Organization (home) — done
 *   - WebSite (home, with sitelinks search box) — done
 *   - LocalBusiness, Review, ItemList, etc. as needed
 */

export interface OrganizationSchemaOpts {
  name: string;
  url: string;
  logo?: string;
  sameAs?: string[];   // social profile URLs
}

export function organizationSchema(opts: OrganizationSchemaOpts) {
  return {
    '@type': 'Organization',
    name: opts.name,
    url: opts.url,
    ...(opts.logo ? { logo: opts.logo } : {}),
    ...(opts.sameAs?.length ? { sameAs: opts.sameAs } : {}),
  };
}

export interface WebSiteSchemaOpts {
  name: string;
  url: string;
  /** Enable Google's Sitelinks Search Box by providing the search URL template. */
  searchUrlTemplate?: string;  // e.g. 'https://example.com/search?q={search_term_string}'
}

export function websiteSchema(opts: WebSiteSchemaOpts) {
  return {
    '@type': 'WebSite',
    name: opts.name,
    url: opts.url,
    ...(opts.searchUrlTemplate
      ? {
          potentialAction: {
            '@type': 'SearchAction',
            target: {
              '@type': 'EntryPoint',
              urlTemplate: opts.searchUrlTemplate,
            },
            'query-input': 'required name=search_term_string',
          },
        }
      : {}),
  };
}

export interface ProductSchemaOpts {
  name: string;
  description: string;
  sku?: string;
  image: string | string[];
  url: string;
  brand?: string;
  price: number;
  priceCurrency: string;
  inStock: boolean;
  rating?: { value: number; count: number };
  reviews?: Array<{
    author: string;
    rating: number;
    body?: string;
    title?: string;
    date?: string;
  }>;
}

export function productSchema(opts: ProductSchemaOpts) {
  const out: Record<string, unknown> = {
    '@type': 'Product',
    name: opts.name,
    description: opts.description,
    image: opts.image,
    url: opts.url,
    offers: {
      '@type': 'Offer',
      url: opts.url,
      priceCurrency: opts.priceCurrency,
      price: opts.price,
      availability: opts.inStock
        ? 'https://schema.org/InStock'
        : 'https://schema.org/OutOfStock',
    },
  };

  if (opts.sku) out['sku'] = opts.sku;
  if (opts.brand) out['brand'] = { '@type': 'Brand', name: opts.brand };

  if (opts.rating && opts.rating.count > 0) {
    out['aggregateRating'] = {
      '@type': 'AggregateRating',
      ratingValue: opts.rating.value,
      reviewCount: opts.rating.count,
      bestRating: 5,
      worstRating: 1,
    };
  }

  if (opts.reviews?.length) {
    out['review'] = opts.reviews.map((r) => ({
      '@type': 'Review',
      author: { '@type': 'Person', name: r.author },
      reviewRating: {
        '@type': 'Rating',
        ratingValue: r.rating,
        bestRating: 5,
        worstRating: 1,
      },
      ...(r.body ? { reviewBody: r.body } : {}),
      ...(r.title ? { name: r.title } : {}),
      ...(r.date ? { datePublished: r.date } : {}),
    }));
  }

  return out;
}

export interface BreadcrumbItem {
  name: string;
  url: string;
}

export function breadcrumbSchema(items: BreadcrumbItem[]) {
  return {
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, idx) => ({
      '@type': 'ListItem',
      position: idx + 1,
      name: item.name,
      item: item.url,
    })),
  };
}

/**
 * An item in an ItemList (used for category landing pages, search
 * results, vendor storefronts). Position starts at 1.
 */
export interface ItemListEntry {
  position: number;
  name: string;
  url: string;
  image?: string;
}

/**
 * ItemList schema — lets Google understand that a page contains a
 * collection of related items. Helps with rich results for category
 * pages where users expect to see "all the abayas" type listings.
 *
 * Reference: https://schema.org/ItemList
 */
export function itemListSchema(items: ItemListEntry[]) {
  return {
    '@type': 'ItemList',
    itemListOrder: 'https://schema.org/ItemListOrderDescending',
    numberOfItems: items.length,
    itemListElement: items.map((item) => ({
      '@type': 'ListItem',
      position: item.position,
      name: item.name,
      url: item.url,
      ...(item.image ? { image: item.image } : {}),
    })),
  };
}
