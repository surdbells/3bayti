import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of, throwError } from 'rxjs';

import { CollectionsService, collectionUrl } from './collections.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Collection } from './collection.model';

function makeCollection(o: Partial<Collection> = {}): Collection {
  return {
    id: 1, slug: 'eid-edit', name: 'Eid Edit', description: null,
    image_url: null, product_count: 3, display_order: 1, ...o,
  };
}

function setup(get: ReturnType<typeof vi.fn>): CollectionsService {
  TestBed.configureTestingModule({
    providers: [CollectionsService, { provide: RoutedHttpClient, useValue: { get } }],
  });
  return TestBed.inject(CollectionsService);
}

describe('CollectionsService', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('lists collections from GET /collections in API order, dropping empty ones', async () => {
    const get = vi.fn(() => of({
      data: [
        makeCollection({ id: 1, slug: 'b', product_count: 4 }),
        makeCollection({ id: 2, slug: 'empty', product_count: 0 }),
        makeCollection({ id: 3, slug: 'a', product_count: 1 }),
      ],
      meta: { total: 3 },
    }));
    const cols = await firstValueFrom(setup(get).list$());
    expect(get).toHaveBeenCalledWith('GET /collections');
    expect(cols.map((c) => c.slug)).toEqual(['b', 'a']);
  });

  it('degrades to an empty list on error', async () => {
    const get = vi.fn(() => throwError(() => new Error('boom')));
    expect(await firstValueFrom(setup(get).list$())).toEqual([]);
  });

  it('builds slug-based storefront URLs', () => {
    expect(collectionUrl('eid-edit')).toBe('/collection/eid-edit');
  });
});
