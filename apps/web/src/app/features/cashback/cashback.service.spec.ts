import { describe, it, expect, afterEach } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { CashbackService } from './cashback.service';

const URL = 'https://api-v3.3bayti.ae/v3/cart/cashback';

function setup(): { service: CashbackService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), CashbackService],
  });
  return {
    service: TestBed.inject(CashbackService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('CashbackService', () => {
  afterEach(() => {
    TestBed.inject(HttpTestingController).verify();
    TestBed.resetTestingModule();
  });

  it('previewCart() sends channel=web and parses the estimate', async () => {
    const { service, controller } = setup();
    const promise = service.previewCart();

    const req = controller.expectOne((r) => r.url === URL);
    expect(req.request.method).toBe('GET');
    expect(req.request.params.get('channel')).toBe('web');
    expect(req.request.params.has('promo_code')).toBe(false);
    expect(req.request.params.has('gift_card_code')).toBe(false);
    expect(req.request.params.has('use_gift_wallet')).toBe(false);
    req.flush({
      data: {
        estimated_amount: '12.50',
        currency: 'AED',
        eligible: true,
        reason: null,
        hold_days: 14,
        uses_cashback_credit: false,
        wallet_has_cashback_credit: false,
      },
    });

    const res = await promise;
    expect(res!.eligible).toBe(true);
    expect(res!.estimated_amount).toBe('12.50');
    expect(res!.hold_days).toBe(14);
  });

  it('passes the promo and normalised gift card code (code wins over the wallet)', async () => {
    const { service, controller } = setup();
    const promise = service.previewCart({ promoCode: ' SAVE10 ', giftCardCode: 'abcd-1234 efgh', useGiftWallet: true });

    const req = controller.expectOne((r) => r.url === URL);
    expect(req.request.params.get('promo_code')).toBe('SAVE10');
    expect(req.request.params.get('gift_card_code')).toBe('ABCD1234EFGH');
    expect(req.request.params.has('use_gift_wallet')).toBe(false);
    req.flush({ data: { estimated_amount: '0.00', eligible: false, reason: 'paid_with_cashback', uses_cashback_credit: true } });

    const res = await promise;
    expect(res!.uses_cashback_credit).toBe(true);
    expect(res!.eligible).toBe(false);
  });

  it('passes use_gift_wallet=true when the wallet is applied', async () => {
    const { service, controller } = setup();
    const promise = service.previewCart({ useGiftWallet: true });
    const req = controller.expectOne((r) => r.url === URL);
    expect(req.request.params.get('use_gift_wallet')).toBe('true');
    req.flush({ data: { estimated_amount: '3.00', eligible: true } });
    expect((await promise)!.eligible).toBe(true);
  });

  it('resolves null on an HTTP error', async () => {
    const { service, controller } = setup();
    const promise = service.previewCart();
    controller.expectOne((r) => r.url === URL).flush(
      { error: { code: 'AUTH_INVALID_TOKEN' } },
      { status: 401, statusText: 'Unauthorized' },
    );
    expect(await promise).toBeNull();
  });

  it('resolves null for a malformed body', async () => {
    const { service, controller } = setup();
    const promise = service.previewCart();
    controller.expectOne((r) => r.url === URL).flush({});
    expect(await promise).toBeNull();
  });
});
