import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient, HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { GiftCardRedeemPageComponent } from './gift-card-redeem-page';
import { GiftCardService } from './gift-card.service';
import { SeoService } from '../../core/seo/seo.service';
import { provideI18n } from '../../core/i18n';
import type { GiftCard } from './gift-card.model';

/** /balance returns a minimal card; cast a partial to GiftCard for the stub. */
function balanceCard(o: Partial<GiftCard> = {}): GiftCard {
  return {
    code: 'GIFT-ABCD-1234', theme: 'birthday', status: 'active',
    balance: '350.00', currency: 'AED', is_spendable: true, expires_at: null,
    ...o,
  } as GiftCard;
}

class StubGiftCardService {
  balanceResult: GiftCard = balanceCard();
  balanceError: unknown = null;
  redeemError: unknown = null;
  redeemCalls = 0;
  checkArg: string | null = null;
  async checkBalance(code: string): Promise<GiftCard> {
    this.checkArg = code;
    if (this.balanceError) throw this.balanceError;
    return this.balanceResult;
  }
  async redeem(_code: string): Promise<GiftCard> {
    this.redeemCalls++;
    if (this.redeemError) throw this.redeemError;
    return balanceCard();
  }
}

function setup(): {
  fixture: ComponentFixture<GiftCardRedeemPageComponent>;
  gift: StubGiftCardService;
  router: Router;
} {
  const gift = new StubGiftCardService();
  TestBed.configureTestingModule({
    imports: [GiftCardRedeemPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: GiftCardService, useValue: gift },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
    ],
  });
  const fixture = TestBed.createComponent(GiftCardRedeemPageComponent);
  fixture.detectChanges();
  const router = TestBed.inject(Router);
  return { fixture, gift, router };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function cmp(fixture: ComponentFixture<GiftCardRedeemPageComponent>): any {
  return fixture.componentInstance as unknown as Record<string, any>;
}

describe('GiftCardRedeemPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('checks a code and renders the themed balance preview', async () => {
    const { fixture, gift } = setup();
    cmp(fixture).code.set('gift-abcd-1234');
    await cmp(fixture).check();
    await flush();
    fixture.detectChanges();
    expect(gift.checkArg).toBe('gift-abcd-1234');
    expect(cmp(fixture).checkState()).toBe('found');
    expect(fixture.nativeElement.querySelector('ui-gift-card')).not.toBeNull();
  });

  it('shows a not-found message for an unknown code (404)', async () => {
    const { fixture, gift } = setup();
    gift.balanceError = new HttpErrorResponse({ status: 404 });
    cmp(fixture).code.set('GIFT-NOPE-0000');
    await cmp(fixture).check();
    await flush();
    fixture.detectChanges();
    expect(cmp(fixture).checkState()).toBe('notfound');
  });

  it('redeems a checked card and navigates to /account/gift-cards', async () => {
    const { fixture, gift, router } = setup();
    const navSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    cmp(fixture).code.set('GIFT-ABCD-1234');
    await cmp(fixture).check();
    await flush();
    await cmp(fixture).redeem();
    expect(gift.redeemCalls).toBe(1);
    expect(navSpy).toHaveBeenCalledWith('/account/gift-cards');
  });

  it('sends an unauthenticated redeem to login with a returnUrl', async () => {
    const { fixture, gift, router } = setup();
    gift.redeemError = new HttpErrorResponse({ status: 401 });
    const navSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    cmp(fixture).code.set('GIFT-ABCD-1234');
    await cmp(fixture).check();
    await flush();
    await cmp(fixture).redeem();
    expect(navSpy).toHaveBeenCalledWith('/login?returnUrl=/gift-cards/redeem');
  });

  it('flags a non-redeemable card (400)', async () => {
    const { fixture, gift } = setup();
    gift.redeemError = new HttpErrorResponse({ status: 400 });
    cmp(fixture).code.set('GIFT-ABCD-1234');
    await cmp(fixture).check();
    await flush();
    await cmp(fixture).redeem();
    fixture.detectChanges();
    expect(cmp(fixture).redeemError()).toBe('notRedeemable');
  });
});
