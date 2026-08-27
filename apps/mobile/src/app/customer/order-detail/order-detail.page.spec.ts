import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { ActivatedRoute } from '@angular/router';
import { of, throwError } from 'rxjs';
import { OrderDetailPage } from './order-detail.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';

/* A representative v3 GET /orders/:id success envelope. */
function okOrder(overrides: Record<string, unknown> = {}) {
  return {
    response_code: 200,
    status: 'success',
    data: {
      order: {
        id: 42,
        order_reference: 'ORD-42',
        status: 'pending_payment',
        date: '2026-05-01T10:00:00+00:00',
        subtotal: 200,
        delivery_fee: 20,
        discount: 30,
        total: 190,
        currency: 'AED',
        paid_at: null,
        items: [
          {
            id: 1,
            product_id: 100,
            vendor_id: 5,
            product_name: 'Abaya',
            product_image: 'https://img/abaya.jpg',
            quantity: 2,
            unit_price: 100,
            subtotal: 200,
            size: 'M',
            color: 'Black',
            is_custom: false,
            item_status: 'pending',
          },
        ],
        applied_promo: { code: 'SAVE30', type: 'fixed', value: 30, discount_amount: 30 },
        returns: [],
        billing_address: {
          first_name: 'Jane', last_name: 'Doe', phone: '+971500000000', email: 'j@d.co',
          street: '1 St', city: 'Dubai', state_province: null, country_code: 'AE', postal_code: null,
        },
        shipping_address: {
          first_name: 'Jane', last_name: 'Doe', phone: '+971500000000', email: 'j@d.co',
          street: '1 St', city: 'Dubai', state_province: null, country_code: 'AE', postal_code: null,
        },
        ...overrides,
      },
    },
  };
}

class AdapterStub {
  getResponse: any = okOrder();
  /* loadOrder() follows the order fetch with GET /orders/:id/timeline; serve
     an empty event feed so the component falls back to the derived stepper. */
  timelineResponse: any = { response_code: 200, status: 'success', data: { events: [] } };
  getError = false;
  cancelResponse: any = { response_code: 200, status: 'success', data: { cancellation: { was_already_cancelled: false } } };
  /* Records only the ORDER detail fetch, not the timeline / pending-orders
     list calls, so assertions read the request the tests care about. */
  lastGet: { routeKey: string; opts: any } | null = null;
  lastPost: { routeKey: string; body: any; opts: any } | null = null;

  get_v3(routeKey: string, opts: any) {
    if (routeKey === 'GET /orders/:id/timeline') {
      return this.getError ? throwError(() => new Error('net')) : of(this.timelineResponse);
    }
    if (routeKey === 'GET /orders/:id') {
      this.lastGet = { routeKey, opts };
      return this.getError ? throwError(() => new Error('net')) : of(this.getResponse);
    }
    // Any other GET (e.g. PendingOrdersService's 'GET /orders' list), benign.
    return of({ response_code: 200, status: 'success', data: [] });
  }
  post_v3(routeKey: string, body: any, opts: any) {
    this.lastPost = { routeKey, body, opts };
    return of(this.cancelResponse);
  }
}

class ToastStub {
  errors: string[] = [];
  successes: string[] = [];
  error(m: string) { this.errors.push(m); }
  success(m: string) { this.successes.push(m); }
}

function setup(routeId: string | null = '42') {
  const adapter = new AdapterStub();
  const toast = new ToastStub();

  TestBed.configureTestingModule({
    imports: [OrderDetailPage],
    providers: [
      provideRouter([]),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      {
        provide: ActivatedRoute,
        useValue: { snapshot: { paramMap: { get: (_: string) => routeId } } },
      },
    ],
  });

  const fixture = TestBed.createComponent(OrderDetailPage);
  const router = TestBed.inject(Router);
  spyOn(router, 'navigate');
  return { fixture, component: fixture.componentInstance, adapter, toast, router };
}

describe('OrderDetailPage', () => {
  /* Capacitor Preferences (web) is a Proxy whose `get` can't be spied
     (no own/proto descriptor). In Karma there IS a real window +
     localStorage, so seed the user there under the plugin's
     'CapacitorStorage.' prefix and let the real plugin read it. */
  const USER_KEY = 'CapacitorStorage.user';

  beforeEach(() => {
    window.localStorage.setItem(
      USER_KEY,
      JSON.stringify({ id: 7, token: 'tok', is_customer: true }),
    );
  });

  afterEach(() => {
    window.localStorage.removeItem(USER_KEY);
  });

  it('creates', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  it('loads the order via GET /orders/:id with the path param + auth token', async () => {
    const { component, adapter } = setup('42');
    await component.ngOnInit();
    expect(adapter.lastGet?.routeKey).toBe('GET /orders/:id');
    expect(adapter.lastGet?.opts.pathParams).toEqual({ id: '42' });
    expect(adapter.lastGet?.opts.authToken).toBe('tok');
    expect(component.order?.order_reference).toBe('ORD-42');
    expect(component.order?.items.length).toBe(1);
  });

  it('maps money fields and detects a discount', async () => {
    const { component } = setup();
    await component.ngOnInit();
    expect(component.order?.total).toBe(190);
    expect(component.hasDiscount()).toBeTrue();
    expect(component.order?.applied_promo?.code).toBe('SAVE30');
  });

  it('shows cancel only on pending_payment', async () => {
    const { component } = setup();
    await component.ngOnInit();
    expect(component.canCancel()).toBeTrue();
    component.order!.status = 'delivered';
    expect(component.canCancel()).toBeFalse();
  });

  it('redirects to my-orders on an invalid id', async () => {
    const { component, router } = setup('0');
    await component.ngOnInit();
    expect(router.navigate).toHaveBeenCalledWith(['/', 'my-orders']);
    expect(component.order).toBeNull();
  });

  it('flags not_found on a 404', async () => {
    const { component, adapter } = setup();
    adapter.getResponse = { response_code: 404, status: 'error' };
    await component.ngOnInit();
    expect(component.ui_controls.not_found).toBeTrue();
    expect(component.order).toBeNull();
  });

  it('cancels via POST /orders/:id/cancel and updates status', async () => {
    const { component, adapter, toast } = setup();
    await component.ngOnInit();
    // After a successful cancel the page reloads the order to reflect the
    // authoritative status, serve the cancelled order on that reload.
    adapter.getResponse = okOrder({ status: 'cancelled' });
    component['executeCancel']();
    expect(adapter.lastPost?.routeKey).toBe('POST /orders/:id/cancel');
    expect(adapter.lastPost?.opts.pathParams).toEqual({ id: '42' });
    expect(component.order?.status).toBe('cancelled');
    expect(toast.successes.length).toBe(1);
  });

  it('reports a network error toast on load failure', async () => {
    const { component, adapter, toast } = setup();
    adapter.getError = true;
    await component.ngOnInit();
    expect(toast.errors.length).toBe(1);
    expect(component.ui_controls.is_loading).toBeFalse();
  });
});
