import { describe, it, expect, afterEach } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { GiftCardService } from './gift-card.service';
import type { GiftCard } from './gift-card.model';

const V3 = 'https://api-v3.3bayti.ae';

function makeCard(o: Partial<GiftCard> = {}): GiftCard {
  return {
    id: 1,
    code: 'GIFT-ABCD-1234',
    theme: 'birthday',
    theme_meta: {
      label: 'Birthday',
      arabic_label: 'عيد ميلاد',
      primary_color: '#3A1A00',
      accent_color: '#E8C040',
      text_color: '#F5E060',
      border_color: '#E8C040',
      pattern: 'sunburst',
      supports_photo: false,
    },
    denomination: '500.00',
    balance: '500.00',
    currency: 'AED',
    status: 'active',
    is_spendable: true,
    recipient_name: null,
    recipient_message: null,
    recipient_photo_url: null,
    scheduled_delivery_at: null,
    activated_at: null,
    expires_at: null,
    created_at: '2026-06-01T00:00:00+00:00',
    is_buyer: true,
    ...o,
  };
}

function setup(): { service: GiftCardService; controller: HttpTestingController } {
  TestBed.configureTestingModule({
    providers: [provideHttpClient(), provideHttpClientTesting(), GiftCardService],
  });
  return {
    service: TestBed.inject(GiftCardService),
    controller: TestBed.inject(HttpTestingController),
  };
}

describe('GiftCardService', () => {
  afterEach(() => {
    TestBed.inject(HttpTestingController).verify();
    TestBed.resetTestingModule();
  });

  it('getThemes() lifts the theme key and preserves order', async () => {
    const { service, controller } = setup();
    const promise = service.getThemes();

    const req = controller.expectOne(`${V3}/v3/gift-cards/themes`);
    expect(req.request.method).toBe('GET');
    req.flush({
      data: {
        // deliberately out of canonical order in the payload
        luxury: {
          label: 'Luxury Gift', arabic_label: 'هدية فاخرة',
          primary_color: '#1A1200', accent_color: '#E8C040', text_color: '#F0D060',
          border_color: '#E8C040', pattern: 'medallion', supports_photo: true,
          presets: ['100.00'], min_denomination: '100.00', max_denomination: '10000.00',
        },
        birthday: {
          label: 'Birthday', arabic_label: 'عيد ميلاد',
          primary_color: '#3A1A00', accent_color: '#E8C040', text_color: '#F5E060',
          border_color: '#E8C040', pattern: 'sunburst', supports_photo: false,
          presets: ['100.00', '500.00'], min_denomination: '100.00', max_denomination: '10000.00',
        },
      },
    });

    const themes = await promise;
    // GIFT_CARD_THEME_ORDER puts birthday first, luxury last; absent themes skipped.
    expect(themes.map((t) => t.theme)).toEqual(['birthday', 'luxury']);
    expect(themes[0].pattern).toBe('sunburst');
    expect(themes[0].presets).toEqual(['100.00', '500.00']);
    expect(themes[1].supports_photo).toBe(true);
  });

  it('checkBalance() normalises the code (strip hyphens/spaces, upper-case) in the query', async () => {
    const { service, controller } = setup();
    const promise = service.checkBalance('gift-abcd 1234');

    const req = controller.expectOne(
      (r) => r.url === `${V3}/v3/gift-cards/balance` && r.params.get('code') === 'GIFTABCD1234',
    );
    expect(req.request.method).toBe('GET');
    req.flush({ data: makeCard({ balance: '250.00', status: 'partially_used' }) });

    const card = await promise;
    expect(card.balance).toBe('250.00');
  });

  it('listMine() returns the array (and [] when data missing)', async () => {
    const { service, controller } = setup();
    const promise = service.listMine();
    const req = controller.expectOne(`${V3}/v3/gift-cards/mine`);
    expect(req.request.method).toBe('GET');
    req.flush({ data: [makeCard(), makeCard({ id: 2 })] });
    expect((await promise).length).toBe(2);
  });

  it('purchase() POSTs the input body and returns the pending card', async () => {
    const { service, controller } = setup();
    const input = {
      denomination: '500.00',
      theme: 'eid' as const,
      recipient_name: 'Sara',
      recipient_message: 'Eid Mubarak',
    };
    const promise = service.purchase(input);
    const req = controller.expectOne(`${V3}/v3/gift-cards/purchase`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual(input);
    req.flush({ data: makeCard({ theme: 'eid', status: 'pending_payment' }) });
    expect((await promise).status).toBe('pending_payment');
  });

  it('redeem() POSTs the normalised code', async () => {
    const { service, controller } = setup();
    const promise = service.redeem('GIFT-abcd-1234');
    const req = controller.expectOne(`${V3}/v3/gift-cards/redeem`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ code: 'GIFTABCD1234' });
    req.flush({ data: makeCard() });
    await promise;
  });

  it('previewCartApply() POSTs to /v3/cart/gift-card with the normalised code', async () => {
    const { service, controller } = setup();
    const promise = service.previewCartApply('gift abcd 1234');
    const req = controller.expectOne(`${V3}/v3/cart/gift-card`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ code: 'GIFTABCD1234' });
    req.flush({
      data: {
        code: 'GIFTABCD1234', balance: '500.00', applicable: '300.00',
        cart_total: '300.00', remaining_due: '0.00', currency: 'AED',
      },
    });
    expect((await promise).applicable).toBe('300.00');
  });
});
