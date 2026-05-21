import { describe, it, expect, afterEach } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { HttpClient } from '@angular/common/http';
import { CurrencyService } from './currency.service';
import { currencyInterceptor } from './currency.interceptor';

const V3 = 'https://api-v3.3bayti.ae';

function setup(): { http: HttpClient; controller: HttpTestingController; svc: CurrencyService } {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(withInterceptors([currencyInterceptor])),
      provideHttpClientTesting(),
      CurrencyService,
    ],
  });
  return {
    http: TestBed.inject(HttpClient),
    controller: TestBed.inject(HttpTestingController),
    svc: TestBed.inject(CurrencyService),
  };
}

describe('currencyInterceptor', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    localStorage.removeItem('bayti_currency');
  });

  it('does NOT append ?currency when AED (default)', () => {
    const { http, controller } = setup();
    http.get(`${V3}/v3/products`).subscribe();
    const req = controller.expectOne(`${V3}/v3/products`);
    expect(req.request.params.has('currency')).toBe(false);
    req.flush([]);
  });

  it('appends ?currency=USD to /v3/products', () => {
    const { http, controller, svc } = setup();
    svc.set('USD');
    http.get(`${V3}/v3/products`).subscribe();
    const req = controller.expectOne(r => r.url === `${V3}/v3/products`);
    expect(req.request.params.get('currency')).toBe('USD');
    req.flush([]);
  });

  it('appends ?currency to /v3/products/facets', () => {
    const { http, controller, svc } = setup();
    svc.set('EUR');
    http.get(`${V3}/v3/products/facets`).subscribe();
    const req = controller.expectOne(r => r.url === `${V3}/v3/products/facets`);
    expect(req.request.params.get('currency')).toBe('EUR');
    req.flush({});
  });

  it('appends ?currency to /v3/categories/:slug', () => {
    const { http, controller, svc } = setup();
    svc.set('GBP');
    http.get(`${V3}/v3/categories/abayas`).subscribe();
    const req = controller.expectOne(r => r.url === `${V3}/v3/categories/abayas`);
    expect(req.request.params.get('currency')).toBe('GBP');
    req.flush({});
  });

  it('does NOT append ?currency to non-catalog endpoints', () => {
    const { http, controller, svc } = setup();
    svc.set('SAR');
    http.get(`${V3}/v3/me/cart`).subscribe();
    const req = controller.expectOne(`${V3}/v3/me/cart`);
    expect(req.request.params.has('currency')).toBe(false);
    req.flush({});
  });

  it('does NOT append ?currency to POST requests on catalog paths', () => {
    const { http, controller, svc } = setup();
    svc.set('USD');
    http.post(`${V3}/v3/products`, {}).subscribe();
    const req = controller.expectOne(`${V3}/v3/products`);
    expect(req.request.params.has('currency')).toBe(false);
    req.flush({});
  });

  it('does NOT append ?currency to auth endpoints', () => {
    const { http, controller, svc } = setup();
    svc.set('USD');
    http.post(`${V3}/v3/auth/login`, {}).subscribe();
    const req = controller.expectOne(`${V3}/v3/auth/login`);
    expect(req.request.params.has('currency')).toBe(false);
    req.flush({});
  });
});
