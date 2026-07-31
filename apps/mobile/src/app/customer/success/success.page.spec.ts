import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ActivatedRoute, convertToParamMap } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { of, throwError } from 'rxjs';

import { SuccessPage } from './success.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';

/** Minimal order payload in the shape GET /orders/:id returns. */
function orderPayload(over: Record<string, unknown> = {}) {
  return {
    id: 42,
    order_reference: 'V3-TEST-1',
    date: '30 Jul 2026',
    subtotal: 450,
    delivery_fee: 20,
    discount: 0,
    gift_card_amount: 0,
    total: 470,
    currency: 'AED',
    items: [
      {
        product_name: 'Elegant Black Abaya',
        product_image: 'https://cdn/x.jpg',
        quantity: 1,
        unit_price: 450,
        subtotal: 450,
        size: 'XXL',
        color: 'Black',
      },
    ],
    shipping_address: {
      first_name: 'Sodiq',
      last_name: 'Bello',
      phone: '+97165655666',
      street: 'Garden City, Ajman',
      city: 'Abu Dhabi',
      state_province: '67',
      country_code: 'AE',
    },
    ...over,
  };
}

class AdapterStub {
  order: unknown = orderPayload();
  failOrder = false;
  get_v3(routeKey: string) {
    if (routeKey === 'GET /orders/:id') {
      if (this.failOrder) return throwError(() => new Error('boom'));
      return of({ response_code: 200, status: 'success', data: { order: this.order } });
    }
    // GET /checkout/status/:order_reference
    return of({ response_code: 200, status: 'success', data: { order_id: 42 } });
  }
}

function configure(queryParams: Record<string, string>, adapter: AdapterStub) {
  TestBed.configureTestingModule({
    imports: [SuccessPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      {
        provide: ActivatedRoute,
        useValue: { snapshot: { queryParamMap: convertToParamMap(queryParams) } },
      },
    ],
  });
}

describe('SuccessPage', () => {
  let fixture: ComponentFixture<SuccessPage>;
  let component: SuccessPage;
  let adapter: AdapterStub;

  beforeEach(() => {
    adapter = new AdapterStub();
  });

  it('should create', async () => {
    configure({ orderReference: 'V3-TEST-1', orderId: '42' }, adapter);
    fixture = TestBed.createComponent(SuccessPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
    expect(component).toBeTruthy();
  });

  it('reads the order reference from the query string', async () => {
    configure({ orderReference: 'V3-TEST-1', orderId: '42' }, adapter);
    fixture = TestBed.createComponent(SuccessPage);
    component = fixture.componentInstance;
    await component.ngOnInit();
    expect(component.orderReference).toBe('V3-TEST-1');
  });

  it('maps the fetched order into the receipt (items, totals, address)', async () => {
    configure({ orderReference: 'V3-TEST-1', orderId: '42' }, adapter);
    fixture = TestBed.createComponent(SuccessPage);
    component = fixture.componentInstance;
    await component.ngOnInit();

    expect(component.isLoading).toBeFalse();
    expect(component.order).not.toBeNull();
    expect(component.order!.total).toBe(470);
    expect(component.order!.items.length).toBe(1);
    expect(component.order!.recipient).toBe('Sodiq Bello');
    // Address lines are compacted (no empty entries).
    expect(component.order!.address_lines).toContain('Garden City, Ajman');
    expect(component.order!.address_lines.every(l => l.trim() !== '')).toBeTrue();
  });

  it('builds the variant line from size + colour', async () => {
    configure({ orderReference: 'V3-TEST-1', orderId: '42' }, adapter);
    fixture = TestBed.createComponent(SuccessPage);
    component = fixture.componentInstance;
    await component.ngOnInit();
    expect(component.variantLine(component.order!.items[0])).toBe('XXL · Black');
  });

  it('still confirms success when the order fetch fails', async () => {
    // Payment already succeeded — a failed detail fetch must not error the screen.
    adapter.failOrder = true;
    configure({ orderReference: 'V3-TEST-1', orderId: '42' }, adapter);
    fixture = TestBed.createComponent(SuccessPage);
    component = fixture.componentInstance;
    await component.ngOnInit();

    expect(component.isLoading).toBeFalse();
    expect(component.order).toBeNull();
    expect(component.orderReference).toBe('V3-TEST-1');
  });
});
