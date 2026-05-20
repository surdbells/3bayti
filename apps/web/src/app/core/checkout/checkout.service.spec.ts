import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { CheckoutService } from './checkout.service';
import type { InitiateCheckoutInput, InitiateCheckoutResponse } from './checkout.types';

const V3_BASE = 'https://api-v3.3bayti.ae';
const SESSION_KEY = 'bayti_checkout_state_v1';

function setup(): { service: CheckoutService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting()],
  });
  return {
    service: TestBed.inject(CheckoutService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('CheckoutService', () => {
  beforeEach(() => {
    if (typeof sessionStorage !== 'undefined') sessionStorage.clear();
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
    if (typeof sessionStorage !== 'undefined') sessionStorage.clear();
  });

  describe('initial state', () => {
    it('starts with empty state when sessionStorage has no entry', () => {
      const { service } = setup();
      expect(service.state()).toEqual({
        shipping_address_id: null,
        billing_address_id: null,
        promo_code: null,
      });
      expect(service.hasShippingAddress()).toBe(false);
    });

    it('hydrates from sessionStorage on construction', () => {
      sessionStorage.setItem(
        SESSION_KEY,
        JSON.stringify({
          shipping_address_id: 5,
          billing_address_id: 7,
          promo_code: 'SAVE10',
        }),
      );
      const { service } = setup();
      expect(service.shippingAddressId()).toBe(5);
      expect(service.billingAddressId()).toBe(7);
      expect(service.promoCode()).toBe('SAVE10');
      expect(service.hasShippingAddress()).toBe(true);
    });

    it('handles corrupted sessionStorage as empty state', () => {
      sessionStorage.setItem(SESSION_KEY, '{not-valid');
      const { service } = setup();
      expect(service.shippingAddressId()).toBeNull();
    });

    it('coerces non-numeric ids to null', () => {
      sessionStorage.setItem(
        SESSION_KEY,
        JSON.stringify({ shipping_address_id: 'abc' }),
      );
      const { service } = setup();
      expect(service.shippingAddressId()).toBeNull();
    });
  });

  describe('setShippingAddress', () => {
    it('sets the shipping id and defaults billing to the same', () => {
      const { service } = setup();
      service.setShippingAddress(42);
      expect(service.shippingAddressId()).toBe(42);
      expect(service.billingAddressId()).toBe(42);
    });

    it('preserves an explicitly set billing address when shipping is later changed', () => {
      const { service } = setup();
      service.setShippingAddress(1); /* billing defaults to 1 */
      service.setBillingAddress(99);
      service.setShippingAddress(5); /* shipping changes; billing stays 99 */
      expect(service.shippingAddressId()).toBe(5);
      expect(service.billingAddressId()).toBe(99);
    });

    it('persists to sessionStorage', () => {
      const { service } = setup();
      service.setShippingAddress(7);
      const raw = sessionStorage.getItem(SESSION_KEY);
      expect(raw).not.toBeNull();
      expect(JSON.parse(raw!).shipping_address_id).toBe(7);
    });
  });

  describe('setBillingAddress', () => {
    it('overrides the billing address', () => {
      const { service } = setup();
      service.setShippingAddress(1);
      service.setBillingAddress(2);
      expect(service.billingAddressId()).toBe(2);
    });

    it('accepts null to clear', () => {
      const { service } = setup();
      service.setShippingAddress(1);
      service.setBillingAddress(null);
      expect(service.billingAddressId()).toBeNull();
    });
  });

  describe('setPromoCode', () => {
    it('sets and clears the promo code', () => {
      const { service } = setup();
      service.setPromoCode('TEST');
      expect(service.promoCode()).toBe('TEST');
      service.setPromoCode(null);
      expect(service.promoCode()).toBeNull();
    });
  });

  describe('clear', () => {
    it('resets state and removes sessionStorage entry', () => {
      const { service } = setup();
      service.setShippingAddress(5);
      service.setPromoCode('X');
      service.clear();

      expect(service.shippingAddressId()).toBeNull();
      expect(service.promoCode()).toBeNull();
      expect(sessionStorage.getItem(SESSION_KEY)).toBeNull();
    });
  });

  describe('initiate', () => {
    it('POSTs to /v3/checkout/initiate and returns the response', async () => {
      const { service, controller } = setup();
      const input: InitiateCheckoutInput = {
        channel: 'web',
        delivery_fee: '25.00',
        discount: '0.00',
        promo_code: null,
        billing_address_id: 1,
        shipping_address_id: 1,
      };
      const expected: InitiateCheckoutResponse = {
        checkout_url: 'https://checkout.example.com/abc',
        order_reference: 'BAYTI-2026-001',
        provider_order_ref: 'noon-xyz',
        order_id: 100,
      };

      const promise = service.initiate(input);
      const req = controller.expectOne(`${V3_BASE}/v3/checkout/initiate`);
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual(input);
      req.flush(expected);

      const result = await promise;
      expect(result).toEqual(expected);
    });

    it('toggles isInitiating during the request', async () => {
      const { service, controller } = setup();
      const input: InitiateCheckoutInput = {
        channel: 'web',
        delivery_fee: '0.00',
        discount: '0.00',
      };
      expect(service.isInitiating()).toBe(false);
      const promise = service.initiate(input);
      expect(service.isInitiating()).toBe(true);
      controller.expectOne(`${V3_BASE}/v3/checkout/initiate`).flush({
        checkout_url: 'x',
        order_reference: 'r',
        provider_order_ref: 'p',
        order_id: 1,
      });
      await promise;
      expect(service.isInitiating()).toBe(false);
    });

    it('toggles isInitiating back to false on error', async () => {
      const { service, controller } = setup();
      const promise = service.initiate({ channel: 'web', delivery_fee: '0', discount: '0' });
      controller.expectOne(`${V3_BASE}/v3/checkout/initiate`)
        .flush('err', { status: 500, statusText: 'Error' });
      await expect(promise).rejects.toThrow();
      expect(service.isInitiating()).toBe(false);
    });
  });
});
