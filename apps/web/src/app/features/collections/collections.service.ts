import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Collection } from './collection.model';

/**
 * CollectionsService, the public "shop by collection" list shared by the
 * home-page row and the /collection index.
 *
 * GET /collections resolves to /v3/collections (ENDPOINT_ROUTING). The API
 * already returns active collections in admin display order; we keep that
 * order and drop collections with no storefront-visible products so a
 * shopper never lands on an empty collection page from a card.
 *
 * Errors degrade to an empty list (the home row hides itself, the index
 * shows its empty state), matching HomeDataService's per-stream pattern.
 */
@Injectable({ providedIn: 'root' })
export class CollectionsService {
  private readonly routed = inject(RoutedHttpClient);

  /** Shoppable collections (product_count > 0), in API display order. */
  list$(): Observable<Collection[]> {
    return this.routed.get<Collection[]>('GET /collections').pipe(
      map((envelope) => (Array.isArray(envelope.data) ? envelope.data : [])),
      map((cols) => cols.filter((c) => (c?.product_count ?? 0) > 0 && !!c.slug)),
      catchError(() => of([] as Collection[])),
    );
  }
}

/** Canonical storefront URL for a collection (slug-based, never an id). */
export function collectionUrl(slug: string): string {
  return `/collection/${slug}`;
}
