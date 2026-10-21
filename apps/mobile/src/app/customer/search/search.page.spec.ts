import { provideZoneChangeDetection } from '@angular/core';
import { ComponentFixture, TestBed, fakeAsync, tick } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { NavController, Platform } from '@ionic/angular/standalone';
import { Observable, of, Subject, throwError } from 'rxjs';

import { SearchPage } from './search.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import { WishlistService } from '../../core/services/wishlist.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { provideAxIcons } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';
import { CartCountService } from '../../core/services/cart-count.service';
import { PendingOrdersService } from '../../core/services/pending-orders.service';

const ok = (data: unknown) => ({ response_code: 200, status: 'success', message: '', data });
const result = (id: number, store = 'Dar Abaya') => ({
  product_id: id, product_name: `P${id}`, image_1: 'u', price: '100', store_name: store, vendor_slug: 'dar-abaya',
});

/** Each GET /mobile/search call takes the next queued reply. */
class AdapterStub {
  queue: Observable<unknown>[] = [];
  calls: any[] = [];
  get_v3(routeKey: string, opts: any) {
    this.calls.push({ routeKey, opts });
    return this.queue.shift() ?? of(ok([]));
  }
}

function setup() {
  const adapter = new AdapterStub();
  const toast = { success: jasmine.createSpy('success'), error: jasmine.createSpy('error') };
  TestBed.configureTestingModule({
    imports: [SearchPage],
    providers: [
      provideZoneChangeDetection(),
      provideRouter([]),
      provideHttpClient(),
      provideAxIcons(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      { provide: I18nService, useValue: { lang: 'en', t: (k: string) => k } },
      { provide: NavController, useValue: { back: () => undefined } },
      { provide: Platform, useValue: {} },
      { provide: ConnectionService, useValue: { setReachabilityCheck: () => undefined, online$: of(true) } },
      { provide: NetworkService, useValue: {} },
      { provide: WishlistService, useValue: {} },
      { provide: CartCountService, useValue: { refresh: () => undefined, count: () => 0 } },
      { provide: PendingOrdersService, useValue: { count: () => 0 } },
    ],
  });
  const fixture: ComponentFixture<SearchPage> = TestBed.createComponent(SearchPage);
  const component = fixture.componentInstance;
  // Skip the Preferences session read; the search itself is a public read.
  spyOn(component, 'getObject').and.returnValue(Promise.resolve());
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;
  return { fixture, component, adapter, toast, el };
}

describe('SearchPage', () => {
  it('shows the prompt (no skeleton) before anything is typed', () => {
    const { el } = setup();
    expect(el.querySelector('.initial-prompt')).not.toBeNull();
    expect(el.querySelectorAll('ax-skeleton-card').length).toBe(0);
  });

  it('shows the card skeleton during the debounce instead of a "no products" flash', fakeAsync(() => {
    const { fixture, component, adapter, el } = setup();
    const reply = new Subject<unknown>();
    adapter.queue.push(reply);

    component.onInputChange('abaya');
    fixture.detectChanges();
    // Still inside the 300ms debounce: no request yet, but skeleton is up.
    expect(adapter.calls.length).toBe(0);
    expect(el.querySelector('.empty-state')).toBeNull();
    const grid = el.querySelector('ion-grid.products-grid[role="status"]');
    expect(grid).not.toBeNull();
    expect(grid!.getAttribute('aria-busy')).toBe('true');
    expect(el.querySelectorAll('ax-skeleton-card').length).toBe(6);

    tick(300);
    expect(adapter.calls.length).toBe(1);
    expect(adapter.calls[0].opts.queryParams).toEqual({ q: 'abaya' });

    reply.next(ok([result(1), result(2)]));
    fixture.detectChanges();
    expect(el.querySelectorAll('ax-skeleton-card').length).toBe(0);
    expect(el.querySelectorAll('article.m6d-product-card').length).toBe(2);
  }));

  it('drops a late reply for an older query', fakeAsync(() => {
    const { fixture, component, adapter, el } = setup();
    const first = new Subject<unknown>();
    const second = new Subject<unknown>();
    adapter.queue.push(first, second);

    component.onInputChange('aba');
    tick(300);
    component.onInputChange('abaya');
    tick(300);
    expect(adapter.calls.length).toBe(2);

    second.next(ok([result(7)]));
    first.next(ok([result(1), result(2), result(3)]));
    fixture.detectChanges();

    expect(component.products.map((p) => p.product_id)).toEqual([7]);
    expect(el.querySelectorAll('article.m6d-product-card').length).toBe(1);
  }));

  it('ends the skeleton and toasts on a transport-level failure', fakeAsync(() => {
    const { fixture, component, adapter, toast, el } = setup();
    adapter.queue.push(throwError(() => new Error('offline')));

    component.onInputChange('abaya');
    tick(300);
    fixture.detectChanges();

    expect(component.ui_controls.is_loading).toBeFalse();
    expect(el.querySelectorAll('ax-skeleton-card').length).toBe(0);
    expect(el.querySelector('.empty-state')).not.toBeNull();
    expect(toast.error).toHaveBeenCalledOnceWith('network_error_retry', jasmine.anything());
  }));

  it('clearing the field drops the pending reply and shows the prompt again', fakeAsync(() => {
    const { fixture, component, adapter, el } = setup();
    const reply = new Subject<unknown>();
    adapter.queue.push(reply);

    component.onInputChange('abaya');
    tick(300);
    component.onInputChange('');
    reply.next(ok([result(1)]));
    fixture.detectChanges();

    expect(component.products.length).toBe(0);
    expect(el.querySelector('.initial-prompt')).not.toBeNull();
  }));

  it('renders the store badge as a tappable button with the shared global styling', fakeAsync(() => {
    const { fixture, component, adapter, el } = setup();
    adapter.queue.push(of(ok([result(1, 'Noor Couture')])));

    component.onInputChange('abaya');
    tick(300);
    fixture.detectChanges();

    const badge = el.querySelector('button.m6d-vendor-badge.vendor-badge--tappable') as HTMLElement;
    expect(badge).not.toBeNull();
    expect(badge.textContent!.trim()).toBe('@Noor Couture');
    // The page no longer ships its own override (it forced `color: inherit`,
    // i.e. dark body text on the dark scrim): no inline/page colour wins.
    expect(badge.style.color).toBe('');
  }));
});
