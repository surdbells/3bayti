import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { MyGiftCardsPageComponent } from './my-gift-cards-page';
import { GiftCardService } from './gift-card.service';
import { SeoService } from '../../core/seo/seo.service';
import { provideI18n } from '../../core/i18n';
import type { GiftCard, GiftCardTheme } from './gift-card.model';

function makeCard(id: number, theme: GiftCardTheme = 'birthday', o: Partial<GiftCard> = {}): GiftCard {
  return {
    id, code: `GIFT-000${id}-1234`, theme,
    theme_meta: {
      label: theme, arabic_label: theme, primary_color: '#222', accent_color: '#E8C040',
      text_color: '#F5E060', border_color: '#E8C040', pattern: 'sunburst', supports_photo: false,
    },
    denomination: '500.00', balance: '500.00', currency: 'AED', status: 'active',
    is_spendable: true, recipient_name: null, recipient_message: null, recipient_photo_url: null,
    scheduled_delivery_at: null, activated_at: null, expires_at: null,
    created_at: '2026-06-01T00:00:00+00:00', is_buyer: true, ...o,
  };
}

class StubGiftCardService {
  cards: GiftCard[] = [];
  throws = false;
  listMineCalls = 0;
  async listMine(): Promise<GiftCard[]> {
    this.listMineCalls++;
    if (this.throws) throw new Error('mine failed');
    return this.cards;
  }
}

function setup(opts: { cards?: GiftCard[]; throws?: boolean } = {}): {
  fixture: ComponentFixture<MyGiftCardsPageComponent>;
  gift: StubGiftCardService;
} {
  const gift = new StubGiftCardService();
  gift.cards = opts.cards ?? [];
  if (opts.throws === true) gift.throws = true;

  TestBed.configureTestingModule({
    imports: [MyGiftCardsPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: GiftCardService, useValue: gift },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
    ],
  });
  const fixture = TestBed.createComponent(MyGiftCardsPageComponent);
  fixture.detectChanges();
  return { fixture, gift };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('MyGiftCardsPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders a themed tile per owned card', async () => {
    const { fixture, gift } = setup({ cards: [makeCard(1), makeCard(2, 'luxury')] });
    await flush();
    fixture.detectChanges();
    expect(gift.listMineCalls).toBe(1);
    expect(fixture.nativeElement.querySelectorAll('.gca__tile')).toHaveLength(2);
    expect(fixture.nativeElement.querySelector('ui-gift-card')).not.toBeNull();
  });

  it('shows the empty state when the buyer has no cards', async () => {
    const { fixture } = setup({ cards: [] });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="my-gift-cards-empty"]')).not.toBeNull();
  });

  it('shows an error state when the list fails to load', async () => {
    const { fixture } = setup({ throws: true });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.gca__state--error')).not.toBeNull();
  });
});
