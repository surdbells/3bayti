import { provideZoneChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, provideRouter, Router } from '@angular/router';
import { NavController, Platform } from '@ionic/angular/standalone';
import { ActionSheetController } from '@ionic/angular';
import { Observable, of, Subject, throwError } from 'rxjs';

import { VendorsPage } from './vendors.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import { HotlinkService } from '../../service/hotlink.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { provideAxIcons } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';
import { CartCountService } from '../../core/services/cart-count.service';
import { PendingOrdersService } from '../../core/services/pending-orders.service';

const ok = (data: unknown) => ({ response_code: 200, status: 'success', message: '', data });
const httpError = { response_code: 500, status: 'error', message: 'Request failed', data: null };

const product = (id: number) => ({ product_id: id, product_name: `P${id}`, image_1: 'u', price: '100' });

/** Serves a reply per route key (an Observable or a plain envelope). */
class AdapterStub {
  calls: { method: string; routeKey: string; opts: any }[] = [];
  replies: Record<string, unknown> = {
    'GET /mobile/read-vendor': ok({ id: 5, name: 'Dar Abaya', logo: '', cover: '', tagline: '', following: false }),
    'GET /mobile/store-latest': ok([product(1)]),
    'GET /mobile/store-labels': ok([{ id: 1, name: 'Eid', count: 2, slug: 'eid' }]),
    'GET /mobile/vendors-products': ok([product(1), product(2)]),
    'GET /mobile/products-by-labels': ok([product(2)]),
    'POST /following/:vendorId': ok({ id: 5 }),
    'DELETE /following/:vendorId': ok(null),
  };

  private reply(method: string, routeKey: string, opts: any): Observable<unknown> {
    this.calls.push({ method, routeKey, opts });
    const r = this.replies[routeKey];
    return r instanceof Observable ? r : of(r);
  }

  get_v3(routeKey: string, opts: any) { return this.reply('GET', routeKey, opts); }
  post_v3(routeKey: string, _body: unknown, opts: any) { return this.reply('POST', routeKey, opts); }
  delete_v3(routeKey: string, opts: any) { return this.reply('DELETE', routeKey, opts); }

  callsFor(routeKey: string) {
    return this.calls.filter((c) => c.routeKey === routeKey);
  }
}

class ToastStub {
  success = jasmine.createSpy('success');
  error = jasmine.createSpy('error');
}

/** Echoes the key, plus the interpolated store name when one is passed. */
class I18nStub {
  lang = 'en';
  t = (key: string, params?: Record<string, unknown>) =>
    params && params['store'] != null ? `${key}|${params['store']}` : key;
}

function setup(params: Record<string, string | null> = { slug: 'dar-abaya', name: 'Dar Abaya (link)' }) {
  const adapter = new AdapterStub();
  const toast = new ToastStub();
  TestBed.configureTestingModule({
    imports: [VendorsPage],
    providers: [
      // The app bootstraps zone-based (main.ts provideZoneChangeDetection);
      // TestBed defaults to zoneless, where plain field writes need an
      // explicit markForCheck. Match the app so detectChanges() refreshes.
      provideZoneChangeDetection(),
      provideRouter([]),
      provideHttpClient(),
      provideAxIcons(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      { provide: I18nService, useValue: new I18nStub() },
      { provide: NavController, useValue: { back: () => undefined } },
      { provide: Platform, useValue: {} },
      { provide: ActionSheetController, useValue: {} },
      { provide: ConnectionService, useValue: {} },
      { provide: NetworkService, useValue: {} },
      { provide: HotlinkService, useValue: { createShortUrl: async () => null } },
      { provide: CartCountService, useValue: { refresh: () => undefined, count: () => 0 } },
      { provide: PendingOrdersService, useValue: { count: () => 0 } },
      {
        provide: ActivatedRoute,
        useValue: { snapshot: { queryParamMap: { get: (k: string) => params[k] ?? null } } },
      },
    ],
  });
  const fixture = TestBed.createComponent(VendorsPage);
  spyOn(TestBed.inject(Router), 'navigate').and.resolveTo(true);
  return { fixture, component: fixture.componentInstance, adapter, toast };
}

describe('VendorsPage', () => {
  /* Capacitor Preferences (web) reads localStorage under the
     'CapacitorStorage.' prefix, seed the signed-in user there. */
  const USER_KEY = 'CapacitorStorage.user';

  beforeEach(() => {
    window.localStorage.setItem(USER_KEY, JSON.stringify({ id: 7, token: 'tok', is_customer: true }));
  });

  afterEach(() => {
    window.localStorage.removeItem(USER_KEY);
  });

  /** ngOnInit + the ionViewDidEnter load, awaited. */
  async function enter(component: VendorsPage) {
    component.ngOnInit();
    component.rqst_param.store_slug = 'dar-abaya';
    await component.getObject();
  }

  describe('loading states', () => {
    it('first paint is the skeleton (header, chips, Latest rail, grid), never the empty state', () => {
      const { fixture } = setup();
      fixture.detectChanges();
      const el = fixture.nativeElement as HTMLElement;

      expect(el.querySelector('.vendor-cover-wrapper > ax-skeleton-block')).not.toBeNull();
      expect(el.querySelector('.vendor-logo ax-skeleton-block')).not.toBeNull();
      expect(el.querySelector('.vendor-follow-skeleton')).not.toBeNull();
      expect(el.querySelector('.vendor-follow-pill')).toBeNull();
      expect(el.querySelectorAll('.filter-chip-row--skeleton ax-skeleton-block').length).toBe(5);
      expect(el.querySelectorAll('.section--latest ax-skeleton-card').length).toBe(3);
      // The rail placeholder is hidden AND unfocusable (its scroller has tabindex=0).
      const railSkeleton = el.querySelector('.section--latest')!;
      expect(railSkeleton.getAttribute('aria-hidden')).toBe('true');
      expect(railSkeleton.hasAttribute('inert')).toBeTrue();
      expect(el.querySelectorAll('.product-grid[aria-busy="true"] ax-skeleton-card').length).toBe(6);
      expect(el.querySelector('.grid-empty')).toBeNull();
      // Back + share stay usable while loading.
      expect(el.querySelector('.vendor-back-btn')).not.toBeNull();
      expect(el.querySelector('.vendor-share-btn')).not.toBeNull();
    });

    it('loads header, rail and labels in parallel, then swaps every skeleton for content', async () => {
      const { fixture, component, adapter } = setup();
      fixture.detectChanges();
      await enter(component);
      fixture.detectChanges();
      const el = fixture.nativeElement as HTMLElement;

      expect(adapter.callsFor('GET /mobile/read-vendor').length).toBe(1);
      expect(adapter.callsFor('GET /mobile/store-latest').length).toBe(1);
      expect(adapter.callsFor('GET /mobile/store-labels').length).toBe(1);
      expect(adapter.callsFor('GET /mobile/vendors-products').length).toBe(1);
      expect(component.ui_controls.vendor_loading).toBeFalse();
      expect(component.ui_controls.latest_loading).toBeFalse();
      expect(component.ui_controls.labels_loading).toBeFalse();
      expect(component.ui_controls.products_loading).toBeFalse();

      expect(el.querySelectorAll('ax-skeleton-card').length).toBe(0);
      expect(el.querySelector('.vendor-meta h2')?.textContent?.trim()).toBe('Dar Abaya');
      expect(el.querySelectorAll('.filter-chip').length).toBe(2); // All + Eid
      expect(el.querySelectorAll('.section--latest article.m6d-product-card').length).toBe(1);
      expect(el.querySelectorAll('.product-grid article.m6d-product-card').length).toBe(2);
      expect(el.querySelector('.vendor-reviews-chip')).not.toBeNull();
    });

    it('a failed Latest read no longer blocks the chips and the grid', async () => {
      const { component, adapter } = setup();
      adapter.replies['GET /mobile/store-latest'] = httpError;
      await enter(component);

      expect(component.latest.length).toBe(0);
      expect(component.ui_controls.latest_loading).toBeFalse();
      expect(component.categories.length).toBe(1);
      expect(component.products.length).toBe(2);
    });

    it('network errors end the loading states instead of spinning forever', async () => {
      const { component, adapter } = setup();
      for (const key of ['GET /mobile/read-vendor', 'GET /mobile/store-latest', 'GET /mobile/store-labels', 'GET /mobile/vendors-products']) {
        adapter.replies[key] = throwError(() => new Error('offline'));
      }
      await enter(component);

      expect(component.ui_controls.vendor_loading).toBeFalse();
      expect(component.ui_controls.latest_loading).toBeFalse();
      expect(component.ui_controls.labels_loading).toBeFalse();
      expect(component.ui_controls.products_loading).toBeFalse();
      // Header falls back to the name the link carried.
      expect(component.storeDisplayName).toBe('Dar Abaya (link)');
    });

    it('a chip tap reloads only the grid (chips + header keep their content)', async () => {
      const { component } = setup();
      await enter(component);
      component.selectLabel(1);
      expect(component.ui_controls.labels_loading).toBeFalse();
      expect(component.ui_controls.vendor_loading).toBeFalse();
      expect(component.products.map((p) => p.product_id)).toEqual([2]);
    });

    it('re-entering the same store refreshes in place; a different store resets to the skeleton', async () => {
      const params: Record<string, string | null> = { slug: 'dar-abaya', name: 'Dar Abaya (link)' };
      const { component, adapter } = setup(params);
      const getObject = spyOn(component, 'getObject').and.callThrough();
      component.ngOnInit();
      component.ionViewDidEnter();
      await getObject.calls.mostRecent().returnValue;
      component.selectLabel(1);

      // Same slug: nothing flips back to its skeleton and the chip survives.
      component.ionViewDidEnter();
      expect(getObject.calls.mostRecent().args).toEqual([true]);
      await getObject.calls.mostRecent().returnValue;
      expect(component.ui_controls.vendor_loading).toBeFalse();
      expect(component.ui_controls.labels_loading).toBeFalse();
      expect(component.ui_controls.products_loading).toBeFalse();
      expect(component.selectedLabelId).toBe(1);
      expect(component.products.map((p) => p.product_id)).toEqual([2]);

      // Different store: back to the skeleton, previous content cleared.
      params['slug'] = 'noor';
      adapter.replies['GET /mobile/read-vendor'] = new Subject<unknown>();
      component.ionViewDidEnter();
      expect(getObject.calls.mostRecent().args).toEqual([false]);
      expect(component.ui_controls.vendor_loading).toBeTrue();
      expect(component.ui_controls.products_loading).toBeTrue();
      expect(component.products.length).toBe(0);
      expect(component.selectedLabelId).toBeNull();
      expect(component.view_vendor.name).toBe('');
    });

    it('drops responses for a store the page has since left', async () => {
      const params: Record<string, string | null> = { slug: 'dar-abaya', name: null };
      const { component, adapter } = setup(params);
      const lateVendor = new Subject<unknown>();
      adapter.replies['GET /mobile/read-vendor'] = lateVendor;
      const getObject = spyOn(component, 'getObject').and.callThrough();
      component.ngOnInit();
      component.ionViewDidEnter();
      await getObject.calls.mostRecent().returnValue;

      params['slug'] = 'noor';
      adapter.replies['GET /mobile/read-vendor'] = new Subject<unknown>();
      component.ionViewDidEnter();
      lateVendor.next(ok({ id: 5, name: 'Dar Abaya', logo: '', cover: '', tagline: '', following: false }));

      expect(component.view_vendor.name).toBe('');
      expect(component.ui_controls.vendor_loading).toBeTrue();
    });

    it('drops a late grid response from an earlier chip tap', async () => {
      const { component, adapter } = setup();
      await enter(component);
      component.categories = [
        { id: 1, name: 'Eid', count: 1, slug: 'eid' },
        { id: 2, name: 'Daily', count: 1, slug: 'daily' },
      ];
      const first = new Subject<unknown>();
      adapter.replies['GET /mobile/products-by-labels'] = first;
      component.selectLabel(1);
      const second = new Subject<unknown>();
      adapter.replies['GET /mobile/products-by-labels'] = second;
      component.selectLabel(2);
      expect(component.ui_controls.products_loading).toBeTrue();

      second.next(ok([product(22)]));
      first.next(ok([product(11)]));

      expect(component.products.map((p) => p.product_id)).toEqual([22]);
      expect(component.ui_controls.products_loading).toBeFalse();
    });
  });

  describe('follow / unfollow toasts', () => {
    it('follow shows a named success toast (the v3 response carries no message)', async () => {
      const { component, adapter, toast } = setup();
      await enter(component);

      component.toggleFollow();

      expect(adapter.callsFor('POST /following/:vendorId')[0].opts.pathParams).toEqual({ vendorId: '5' });
      expect(component.view_vendor.following).toBeTrue();
      expect(toast.success).toHaveBeenCalledOnceWith('store_follow_success|Dar Abaya', jasmine.anything());
      expect(toast.error).not.toHaveBeenCalled();
      expect(component.ui_controls.follow_busy).toBeFalse();
    });

    it('unfollow shows a named success toast', async () => {
      const { component, toast } = setup();
      await enter(component);
      component.view_vendor.following = true;

      component.toggleFollow();

      expect(component.view_vendor.following).toBeFalse();
      expect(toast.success).toHaveBeenCalledOnceWith('store_unfollow_success|Dar Abaya', jasmine.anything());
    });

    it('an HTTP failure shows a localized error and keeps the state', async () => {
      const { component, adapter, toast } = setup();
      await enter(component);
      adapter.replies['POST /following/:vendorId'] = httpError;

      component.toggleFollow();

      expect(component.view_vendor.following).toBeFalse();
      expect(toast.success).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledOnceWith('store_follow_failed', jasmine.anything());
    });

    it('a network failure on unfollow shows an error and re-enables the pill', async () => {
      const { component, adapter, toast } = setup();
      await enter(component);
      component.view_vendor.following = true;
      adapter.replies['DELETE /following/:vendorId'] = throwError(() => new Error('offline'));

      component.toggleFollow();

      expect(component.view_vendor.following).toBeTrue();
      expect(toast.error).toHaveBeenCalledOnceWith('following_unfollow_failed', jasmine.anything());
      expect(component.ui_controls.follow_busy).toBeFalse();
    });

    it('ignores repeat taps while a write is in flight', async () => {
      const { component, adapter } = setup();
      await enter(component);
      adapter.replies['POST /following/:vendorId'] = new Subject<unknown>();

      component.toggleFollow();
      component.toggleFollow();

      expect(component.ui_controls.follow_busy).toBeTrue();
      expect(adapter.callsFor('POST /following/:vendorId').length).toBe(1);
      expect(adapter.callsFor('DELETE /following/:vendorId').length).toBe(0);
    });

    it('uses generic copy when neither the vendor read nor the link carries a name', async () => {
      const { component, toast } = setup({ slug: 'dar-abaya', name: null });
      await enter(component);
      component.view_vendor.name = '';

      component.toggleFollow();

      expect(toast.success).toHaveBeenCalledOnceWith('store_follow_success_generic', jasmine.anything());
    });
  });
});
