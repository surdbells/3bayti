import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient, HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { GiftCardLandingPageComponent } from './gift-card-landing-page';
import { GiftCardService } from './gift-card.service';
import { CheckoutService } from '../../core/checkout/checkout.service';
import { SeoService } from '../../core/seo/seo.service';
import { provideI18n } from '../../core/i18n';
import type { GiftCardTheme, GiftCardThemeOption, GiftCard } from './gift-card.model';

function themeOpt(theme: GiftCardTheme, o: Partial<GiftCardThemeOption> = {}): GiftCardThemeOption {
  return {
    theme,
    label: theme,
    arabic_label: theme,
    primary_color: '#222',
    accent_color: '#E8C040',
    text_color: '#F5E060',
    border_color: '#E8C040',
    pattern: 'sunburst',
    supports_photo: theme === 'luxury',
    presets: ['100.00', '500.00', '1000.00'],
    min_denomination: '100.00',
    max_denomination: '10000.00',
    ...o,
  };
}

function makeCard(o: Partial<GiftCard> = {}): GiftCard {
  return {
    id: 42, code: 'GIFT-ABCD-1234', theme: 'birthday',
    theme_meta: themeOpt('birthday'),
    denomination: '500.00', balance: '500.00', currency: 'AED',
    status: 'pending_payment', is_spendable: false,
    recipient_name: null, recipient_message: null, recipient_photo_url: null,
    scheduled_delivery_at: null, activated_at: null, expires_at: null,
    created_at: '2026-06-01T00:00:00+00:00', is_buyer: true, ...o,
  };
}

class StubGiftCardService {
  themes: GiftCardThemeOption[] = [];
  themesThrows = false;
  purchaseResult: GiftCard = makeCard();
  purchaseError: unknown = null;
  getThemesCalls = 0;
  purchaseArg: unknown = null;
  async getThemes(): Promise<GiftCardThemeOption[]> {
    this.getThemesCalls++;
    if (this.themesThrows) throw new Error('themes failed');
    return this.themes;
  }
  async purchase(input: unknown): Promise<GiftCard> {
    this.purchaseArg = input;
    if (this.purchaseError) throw this.purchaseError;
    return this.purchaseResult;
  }
  uploadArg: File | null = null;
  uploadResult = 'https://api-v3.3bayti.ae/uploads/gift-cards/7/01J.png';
  uploadError: unknown = null;
  async uploadPhoto(file: File): Promise<string> {
    this.uploadArg = file;
    if (this.uploadError) throw this.uploadError;
    return this.uploadResult;
  }
}

class StubCheckoutService {
  initiateArg: unknown = null;
  initiateResult = { checkout_url: 'https://noon.test/pay/abc', order_reference: 'GC-REF-1' };
  initiateError: unknown = null;
  async initiate(input: unknown): Promise<{ checkout_url: string; order_reference: string }> {
    this.initiateArg = input;
    if (this.initiateError) throw this.initiateError;
    return this.initiateResult;
  }
}

function setup(opts: { themes?: GiftCardThemeOption[]; themesThrows?: boolean } = {}): {
  fixture: ComponentFixture<GiftCardLandingPageComponent>;
  gift: StubGiftCardService;
  checkout: StubCheckoutService;
  router: Router;
  redirectSpy: ReturnType<typeof vi.fn>;
} {
  const gift = new StubGiftCardService();
  gift.themes = opts.themes ?? [themeOpt('birthday'), themeOpt('luxury')];
  if (opts.themesThrows === true) gift.themesThrows = true;
  const checkout = new StubCheckoutService();

  /* Stub the protected redirectTo so the spec never touches window.location. */
  const redirectSpy = vi.fn();
  vi.spyOn(
    GiftCardLandingPageComponent.prototype as unknown as { redirectTo: (u: string) => void },
    'redirectTo',
  ).mockImplementation(redirectSpy);

  TestBed.configureTestingModule({
    imports: [GiftCardLandingPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: GiftCardService, useValue: gift },
      { provide: CheckoutService, useValue: checkout },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
    ],
  });
  const fixture = TestBed.createComponent(GiftCardLandingPageComponent);
  fixture.detectChanges();
  const router = TestBed.inject(Router);
  return { fixture, gift, checkout, router, redirectSpy };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

/* Access protected members in tests without widening the component API. */
function cmp(fixture: ComponentFixture<GiftCardLandingPageComponent>): any {
  return fixture.componentInstance as unknown as Record<string, any>;
}

describe('GiftCardLandingPageComponent', () => {
  afterEach(() => {
    try {
      sessionStorage.clear();
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => {
        if (!req.cancelled) req.flush({});
      });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the themed designs once themes load', async () => {
    const { fixture, gift } = setup({ themes: [themeOpt('birthday'), themeOpt('luxury')] });
    await flush();
    fixture.detectChanges();
    expect(gift.getThemesCalls).toBe(1);
    expect(fixture.nativeElement.querySelectorAll('.gc__theme')).toHaveLength(2);
    expect(fixture.nativeElement.querySelector('ui-gift-card')).not.toBeNull();
  });

  it('shows an error state when themes fail to load', async () => {
    const { fixture } = setup({ themesThrows: true });
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.gc__state--error')).not.toBeNull();
  });

  it('disables submit for an out-of-range amount and enables it for a valid one', async () => {
    const { fixture } = setup();
    await flush();
    cmp(fixture).denomination.set('50'); // below the 100 minimum
    fixture.detectChanges();
    let btn = fixture.nativeElement.querySelector('.gc__submit') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);

    cmp(fixture).denomination.set('500');
    fixture.detectChanges();
    btn = fixture.nativeElement.querySelector('.gc__submit') as HTMLButtonElement;
    expect(btn.disabled).toBe(false);
  });

  it('selecting a preset sets the amount and leaves custom mode', async () => {
    const { fixture } = setup();
    await flush();
    cmp(fixture).enableCustom();
    expect(cmp(fixture).customMode()).toBe(true);
    cmp(fixture).selectPreset('1000.00');
    expect(cmp(fixture).denomination()).toBe('1000');
    expect(cmp(fixture).customMode()).toBe(false);
  });

  it('purchases then initiates a synthetic checkout and redirects to Noon', async () => {
    const { fixture, gift, checkout, redirectSpy } = setup();
    await flush();
    cmp(fixture).recipientName.set('Sara');
    cmp(fixture).denomination.set('500');

    await cmp(fixture).submit();

    expect(gift.purchaseArg).toMatchObject({
      theme: 'birthday',
      denomination: '500.00',
      recipient_name: 'Sara',
      recipient_message: null,
      scheduled_delivery_at: null,
    });
    expect(checkout.initiateArg).toMatchObject({ gift_card_purchase_id: 42, channel: 'web' });
    expect(redirectSpy).toHaveBeenCalledWith('https://noon.test/pay/abc');
    expect(sessionStorage.getItem('bayti.giftCardCheckoutRef')).toBe('GC-REF-1');
  });

  it('sends the buyer to login when the purchase is unauthenticated (401)', async () => {
    const { fixture, gift, router, redirectSpy } = setup();
    await flush();
    gift.purchaseError = new HttpErrorResponse({ status: 401 });
    const navSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

    await cmp(fixture).submit();

    expect(navSpy).toHaveBeenCalledWith('/login?returnUrl=/gift-cards');
    expect(redirectSpy).not.toHaveBeenCalled();
  });

  it('shows the photo upload control only for the luxury theme', async () => {
    const { fixture } = setup({ themes: [themeOpt('birthday'), themeOpt('luxury')] });
    await flush();
    fixture.detectChanges();
    // Default theme is birthday (supports_photo: false) → no photo field.
    expect(fixture.nativeElement.querySelector('.gc__photo-field')).toBeNull();

    cmp(fixture).selectTheme('luxury');
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.gc__photo-field')).not.toBeNull();
  });

  it('uploads a chosen luxury photo, previews it, and includes the url in the purchase', async () => {
    const { fixture, gift } = setup();
    await flush();
    cmp(fixture).selectTheme('luxury');
    cmp(fixture).denomination.set('500');
    fixture.detectChanges();

    const file = new File(['x'], 'recipient.png', { type: 'image/png' });
    await cmp(fixture).onPhotoSelected({ target: { files: [file], value: '' } } as unknown as Event);
    fixture.detectChanges();

    expect(gift.uploadArg).toBe(file);
    expect(cmp(fixture).recipientPhotoUrl()).toBe(gift.uploadResult);
    expect(fixture.nativeElement.querySelector('.gc__photo-thumb')).not.toBeNull();

    await cmp(fixture).submit();
    expect(gift.purchaseArg).toMatchObject({
      theme: 'luxury',
      recipient_photo_url: gift.uploadResult,
    });
  });

  it('rejects an unsupported file type without uploading', async () => {
    const { fixture, gift } = setup();
    await flush();
    cmp(fixture).selectTheme('luxury');

    const file = new File(['x'], 'doc.pdf', { type: 'application/pdf' });
    await cmp(fixture).onPhotoSelected({ target: { files: [file], value: '' } } as unknown as Event);

    expect(gift.uploadArg).toBeNull();
    expect(cmp(fixture).photoError()).toBe('type');
    expect(cmp(fixture).recipientPhotoUrl()).toBeNull();
  });

  it('clears the photo when switching away from the luxury theme', async () => {
    const { fixture } = setup();
    await flush();
    cmp(fixture).selectTheme('luxury');

    const file = new File(['x'], 'recipient.png', { type: 'image/png' });
    await cmp(fixture).onPhotoSelected({ target: { files: [file], value: '' } } as unknown as Event);
    expect(cmp(fixture).recipientPhotoUrl()).not.toBeNull();

    cmp(fixture).selectTheme('birthday');
    expect(cmp(fixture).recipientPhotoUrl()).toBeNull();
  });
});
