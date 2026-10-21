import { provideZoneChangeDetection } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { NavController, Platform } from '@ionic/angular/standalone';
import { ActionSheetController } from '@ionic/angular';
import { Observable, of, Subject, throwError } from 'rxjs';

import { MyOrdersPage } from './my-orders.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { provideAxIcons } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';
import { CartCountService } from '../../core/services/cart-count.service';
import { PendingOrdersService } from '../../core/services/pending-orders.service';

const ok = (orders: unknown[]) => ({
  response_code: 200, status: 'success', message: '', data: { orders, pagination: {} },
});
const order = (id: number, status = 'paid') => ({
  id, order_reference: `3B-${id}`, date: '2026-10-01T10:00:00+04:00', status, total: '100.00',
  items: [{ product_name: `P${id}`, quantity: 1, product_image: '', vendor_slug: 's' }],
});

/** Each GET /orders call takes the next queued reply. */
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
    imports: [MyOrdersPage],
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
      { provide: ActionSheetController, useValue: {} },
      { provide: ConnectionService, useValue: {} },
      { provide: NetworkService, useValue: {} },
      { provide: CartCountService, useValue: { refresh: () => undefined, count: () => 0 } },
      { provide: PendingOrdersService, useValue: { count: () => 0 } },
    ],
  });
  const fixture: ComponentFixture<MyOrdersPage> = TestBed.createComponent(MyOrdersPage);
  const component = fixture.componentInstance;
  component.single_user.token = 'tok';
  fixture.detectChanges();
  const el = fixture.nativeElement as HTMLElement;
  return { fixture, component, adapter, toast, el };
}

describe('MyOrdersPage loading state', () => {
  it('first paint is the order-card skeleton, never the "no orders" empty state', () => {
    const { el } = setup();
    const busy = el.querySelector('[role="status"][aria-busy="true"]');
    expect(busy).not.toBeNull();
    expect(busy!.getAttribute('aria-label')).toBe('orders_loading');
    expect(busy!.querySelectorAll('ion-card.order-card').length).toBe(3);
    expect(el.querySelector('.empty-state')).toBeNull();
  });

  it('swaps the skeleton for the loaded orders', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.queue.push(of(ok([order(1), order(2)])));

    component.order_listing();
    fixture.detectChanges();

    expect(el.querySelector('[aria-busy="true"]')).toBeNull();
    expect(el.querySelectorAll('ion-card.order-card').length).toBe(2);
    expect(el.querySelector('.order-ref')!.textContent!.trim()).toBe('3B-1');
  });

  it('shows the empty state once an empty first page lands', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.queue.push(of(ok([])));

    component.order_listing();
    fixture.detectChanges();

    expect(el.querySelector('.empty-state')).not.toBeNull();
    expect(el.querySelector('[aria-busy="true"]')).toBeNull();
  });

  it('drops a late reply for an earlier chip selection', () => {
    const { fixture, component, adapter } = setup();
    const allReply = new Subject<unknown>();
    const deliveredReply = new Subject<unknown>();
    adapter.queue.push(allReply, deliveredReply);

    component.order_listing();               // 'all'
    component.selectStatus('delivered');      // newer request
    deliveredReply.next(ok([order(9, 'delivered')]));
    allReply.next(ok([order(1), order(2), order(3)]));
    fixture.detectChanges();

    expect(adapter.calls[1].opts.queryParams.status).toBe('delivered');
    expect(component.orders.map((o: any) => o.id)).toEqual([9]);
  });

  it('ends the skeleton and toasts on a transport-level failure', () => {
    const { fixture, component, adapter, toast, el } = setup();
    adapter.queue.push(throwError(() => new Error('offline')));

    component.order_listing();
    fixture.detectChanges();

    expect(component.ui_controls.is_loading).toBeFalse();
    expect(el.querySelector('[aria-busy="true"]')).toBeNull();
    expect(toast.error).toHaveBeenCalledOnceWith('network_error_retry', jasmine.anything());
  });
});
