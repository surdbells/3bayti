import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, ActivatedRoute } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { GiftCardDetailPageComponent } from './gift-card-detail-page';
import { GiftCardService } from './gift-card.service';
import { SeoService } from '../../core/seo/seo.service';
import { provideI18n } from '../../core/i18n';
import type { GiftCard, GiftCardTransaction } from './gift-card.model';

function makeCard(id: number, o: Partial<GiftCard> = {}): GiftCard {
  return {
    id, code: 'GIFT-ABCD-1234', theme: 'birthday',
    theme_meta: {
      label: 'Birthday', arabic_label: 'عيد ميلاد', primary_color: '#222', accent_color: '#E8C040',
      text_color: '#F5E060', border_color: '#E8C040', pattern: 'sunburst', supports_photo: false,
    },
    denomination: '500.00', balance: '200.00', currency: 'AED', status: 'partially_used',
    is_spendable: true, recipient_name: 'Sara', recipient_message: null, recipient_photo_url: null,
    scheduled_delivery_at: null, activated_at: '2026-06-01T00:00:00+00:00', expires_at: null,
    created_at: '2026-06-01T00:00:00+00:00', is_buyer: true,
    transactions: [
      { id: 1, type: 'purchase', amount: '500.00', balance_after: '500.00', order_reference: null, created_at: '2026-06-01T00:00:00+00:00' },
      { id: 2, type: 'redemption', amount: '300.00', balance_after: '200.00', order_reference: 'ORD-9', created_at: '2026-06-05T00:00:00+00:00' },
    ] as GiftCardTransaction[],
    ...o,
  };
}

class StubGiftCardService {
  cards: GiftCard[] = [];
  throws = false;
  async listMine(): Promise<GiftCard[]> {
    if (this.throws) throw new Error('mine failed');
    return this.cards;
  }
}

function setup(opts: { cards?: GiftCard[]; id?: string; throws?: boolean } = {}): {
  fixture: ComponentFixture<GiftCardDetailPageComponent>;
} {
  const gift = new StubGiftCardService();
  gift.cards = opts.cards ?? [];
  if (opts.throws === true) gift.throws = true;
  const id = opts.id ?? '1';

  TestBed.configureTestingModule({
    imports: [GiftCardDetailPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: GiftCardService, useValue: gift },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: (k: string) => (k === 'id' ? id : null) } } } },
    ],
  });
  const fixture = TestBed.createComponent(GiftCardDetailPageComponent);
  fixture.detectChanges();
  return { fixture };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function cmp(fixture: ComponentFixture<GiftCardDetailPageComponent>): any {
  return fixture.componentInstance as unknown as Record<string, any>;
}

describe('GiftCardDetailPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('selects the card by id and renders its details + transactions', async () => {
    const { fixture } = setup({ cards: [makeCard(1)], id: '1' });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('ui-gift-card')).not.toBeNull();
    expect(fixture.nativeElement.querySelectorAll('.gcd__txn')).toHaveLength(2);
    // 200 of 500 => 40%
    expect(cmp(fixture).balancePct()).toBe(40);
  });

  it('shows a not-found state when the id is not among the buyer cards', async () => {
    const { fixture } = setup({ cards: [makeCard(1)], id: '99' });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="gift-card-detail-notfound"]')).not.toBeNull();
  });

  it('shows an error state when the load fails', async () => {
    const { fixture } = setup({ throws: true, id: '1' });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.gcd__state--error')).not.toBeNull();
  });
});
