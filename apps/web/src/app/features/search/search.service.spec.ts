import { describe, it, expect, beforeEach } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { SearchService } from './search.service';
import { RoutedHttpClient } from '../../core/http/routed-http-client';

interface RecordedCall {
  route: string;
  query: Record<string, unknown>;
}

describe('SearchService', () => {
  let service: SearchService;
  let calls: RecordedCall[];

  beforeEach(() => {
    calls = [];
    const stub = {
      get(route: string, opts: { query?: Record<string, unknown> }) {
        calls.push({ route, query: opts?.query ?? {} });
        if (route === 'GET /products') {
          return of({ data: [{ slug: 'silk-dress', name: 'Silk Dress' }], meta: {} });
        }
        if (route === 'GET /vendors') {
          return of({ data: [{ slug: 'almas', name: 'Almas Fashion' }], meta: {} });
        }
        return of({ data: [], meta: {} });
      },
    };

    TestBed.configureTestingModule({
      providers: [
        SearchService,
        { provide: RoutedHttpClient, useValue: stub },
      ],
    });
    service = TestBed.inject(SearchService);
  });

  it('queries products + stores in parallel and returns both groups', async () => {
    const res = await service.search('silk');

    expect(calls.map((c) => c.route).sort()).toEqual(['GET /products', 'GET /vendors']);
    expect(calls.every((c) => c.query['q'] === 'silk')).toBe(true);
    expect(calls.every((c) => c.query['limit'] === 6)).toBe(true);

    expect(res.products).toHaveLength(1);
    expect(res.products[0].slug).toBe('silk-dress');
    expect(res.stores).toHaveLength(1);
    expect(res.stores[0].slug).toBe('almas');
  });

  it('trims the query before sending', async () => {
    await service.search('   shoes   ');
    expect(calls.every((c) => c.query['q'] === 'shoes')).toBe(true);
  });

  it('forwards a custom group limit', async () => {
    await service.search('bag', 3);
    expect(calls.every((c) => c.query['limit'] === 3)).toBe(true);
  });

  it('short-circuits a blank query without hitting the API', async () => {
    const res = await service.search('    ');
    expect(calls).toHaveLength(0);
    expect(res.products).toEqual([]);
    expect(res.stores).toEqual([]);
  });
});
