import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';
import { CollectionPage } from './collection.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';

/** Records every get_v3 call; serves a configurable listing + facets reply. */
class AdapterStub {
  calls: { routeKey: string; opts: any }[] = [];
  listingResponse: any = {
    response_code: 200,
    status: 'success',
    data: [{ product_id: 11, product_name: 'Abaya', image_1: 'u', price: '100', sale_price: null, store_name: 'Store' }],
  };
  facetsResponse: any = { response_code: 200, status: 'success', data: { sizes: [], colors: [] } };

  get_v3(routeKey: string, opts: any) {
    this.calls.push({ routeKey, opts });
    if (routeKey === 'GET /products/facets') {
      return of(this.facetsResponse);
    }
    return of(this.listingResponse);
  }

  callsFor(routeKey: string) {
    return this.calls.filter((c) => c.routeKey === routeKey);
  }
}

class ToastStub {
  error() {}
  success() {}
}

function setup(params: Record<string, string | null> = { slug: 'eid-edit', name: 'Eid Edit' }) {
  const adapter = new AdapterStub();
  TestBed.configureTestingModule({
    imports: [CollectionPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: new ToastStub() },
      {
        provide: ActivatedRoute,
        useValue: { snapshot: { queryParamMap: { get: (k: string) => params[k] ?? null } } },
      },
    ],
  });
  const fixture = TestBed.createComponent(CollectionPage);
  const router = TestBed.inject(Router);
  spyOn(router, 'navigate');
  return { fixture, component: fixture.componentInstance, adapter, router };
}

describe('CollectionPage', () => {
  /* Capacitor Preferences (web) reads localStorage under the
     'CapacitorStorage.' prefix, seed the signed-in user there. */
  const USER_KEY = 'CapacitorStorage.user';

  beforeEach(() => {
    window.localStorage.setItem(USER_KEY, JSON.stringify({ id: 7, token: 'tok', is_customer: true }));
  });

  afterEach(() => {
    window.localStorage.removeItem(USER_KEY);
  });

  it('should create', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  it('reads slug + name and lists products scoped to the collection slug', async () => {
    const { component, adapter } = setup();
    await component.ngOnInit();

    expect(component.initial.slug).toBe('eid-edit');
    expect(component.initial.name).toBe('Eid Edit');

    const listing = adapter.callsFor('GET /mobile/collection-listing');
    expect(listing.length).toBe(1);
    expect(listing[0].opts.queryParams).toEqual({
      collection: 'eid-edit',
      sort: 'newest',
      limit: 10,
      offset: 0,
      max_price: 20000,
    });
    // Never filters by category on this page.
    expect(listing[0].opts.queryParams.category_id).toBeUndefined();
    expect(component.collection_listing.length).toBe(1);
    expect(component.ui_controls.is_empty).toBeFalse();
    expect(component.ui_controls.is_loading).toBeFalse();
  });

  it('scopes the facets to the collection slug', async () => {
    const { component, adapter } = setup();
    await component.ngOnInit();
    const facets = adapter.callsFor('GET /products/facets');
    expect(facets.length).toBe(1);
    expect(facets[0].opts.queryParams).toEqual({ sort: 'newest', collection: 'eid-edit' });
  });

  it('price chip keeps the collection scope', async () => {
    const { component, adapter } = setup();
    await component.ngOnInit();
    component.filterByPrice(500);
    const listing = adapter.callsFor('GET /mobile/collection-listing');
    const last = listing[listing.length - 1].opts.queryParams;
    expect(last.collection).toBe('eid-edit');
    expect(last.max_price).toBe(500);
    expect(last.offset).toBe(0);
  });

  it('infinite scroll pages with the collection scope and appends', async () => {
    const { component, adapter } = setup();
    await component.ngOnInit();
    component.getMoreItems();
    const listing = adapter.callsFor('GET /mobile/collection-listing');
    const last = listing[listing.length - 1].opts.queryParams;
    expect(last.collection).toBe('eid-edit');
    expect(last.offset).toBe(10);
    expect(component.collection_listing.length).toBe(2);
  });

  it('filter sheet apply sends sort/sizes/colors/price with the collection', async () => {
    const { component, adapter } = setup();
    await component.ngOnInit();
    component.onFilterApply({ sort: 'price_asc', sizes: ['S', 'M'], colors: ['black'], minPrice: 50, maxPrice: 900 } as any);
    const listing = adapter.callsFor('GET /mobile/collection-listing');
    const last = listing[listing.length - 1].opts.queryParams;
    expect(last).toEqual({
      collection: 'eid-edit',
      sort: 'price_asc',
      limit: 10,
      offset: 0,
      max_price: 900,
      min_price: 50,
      sizes: 'S,M',
      colors: 'black',
    });
    expect(component.hasActiveFilters).toBeTrue();
  });

  it('shows the empty state for an empty collection', async () => {
    const { component, adapter } = setup();
    adapter.listingResponse = { response_code: 200, status: 'success', data: [] };
    await component.ngOnInit();
    expect(component.collection_listing.length).toBe(0);
    expect(component.ui_controls.is_empty).toBeTrue();
  });

  it('does not list the whole catalog when the slug is missing', async () => {
    const { component, adapter } = setup({ slug: null, name: null });
    await component.ngOnInit();
    expect(adapter.callsFor('GET /mobile/collection-listing').length).toBe(0);
    expect(adapter.callsFor('GET /products/facets').length).toBe(0);
    expect(component.ui_controls.is_empty).toBeTrue();
  });

  it('redirects a signed-out viewer to login', async () => {
    window.localStorage.removeItem(USER_KEY);
    const { component, router } = setup();
    await component.ngOnInit();
    expect(router.navigate).toHaveBeenCalledWith(['/', 'login']);
  });
});
