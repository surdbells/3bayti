import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { SellPageComponent } from './sell-page';
import { SeoService } from '../../core/seo/seo.service';
import { VENDOR_APP_URL } from '../../core/auth/auth.tokens';
import { provideI18n } from '../../core/i18n';

function setup(): { fixture: ComponentFixture<SellPageComponent> } {
  TestBed.configureTestingModule({
    imports: [SellPageComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
      { provide: VENDOR_APP_URL, useValue: 'https://seller.test' },
    ],
  });
  const fixture = TestBed.createComponent(SellPageComponent);
  fixture.detectChanges();
  return { fixture };
}

describe('SellPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the hero, six feature cards and three steps', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('[data-testid="sell-page"]')).not.toBeNull();
    expect(root.querySelector('.sell-hero__title')).not.toBeNull();
    expect(root.querySelectorAll('.sell-feature')).toHaveLength(6);
    expect(root.querySelectorAll('.sell-step')).toHaveLength(3);
  });

  it('points the primary CTAs at the inline apply form', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    const hero = root.querySelector('[data-testid="sell-hero-apply"]') as HTMLAnchorElement;
    const cta = root.querySelector('[data-testid="sell-cta-apply"]') as HTMLAnchorElement;
    expect(hero.getAttribute('href')).toBe('#sell-apply');
    expect(cta.getAttribute('href')).toBe('#sell-apply');
    // The application form is rendered inline on the page.
    expect(root.querySelector('[data-testid="sell-apply-form"]')).not.toBeNull();
  });

  it('keeps the Sign in CTA pointing at the external portal for approved vendors', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    const signin = root.querySelector('[data-testid="sell-hero-signin"]') as HTMLAnchorElement;
    expect(signin.getAttribute('href')).toBe('https://seller.test');
    expect(signin.getAttribute('target')).toBe('_blank');
    expect(signin.getAttribute('rel')).toContain('noopener');
  });

  it('submits an application to the public vendor-applications endpoint and shows success', async () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    const controller = TestBed.inject(HttpTestingController);

    const set = (testid: string, value: string): void => {
      const el = root.querySelector(`[data-testid="${testid}"]`) as HTMLInputElement;
      el.value = value;
      el.dispatchEvent(new Event('input'));
    };
    set('sell-first-name', 'Aïsha');
    set('sell-last-name', 'Khan');
    set('sell-email', 'aisha@example.com');
    set('sell-business-name', 'Khan Couture');
    // Phone national digits (UAE default dial code in the phone-input).
    const phoneNational = root.querySelector('[data-testid="phone-input-national"]') as HTMLInputElement;
    phoneNational.value = '501234567';
    phoneNational.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    (root.querySelector('[data-testid="sell-apply-form"]') as HTMLFormElement)
      .dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    const req = controller.expectOne((r) => r.url.endsWith('/v3/vendor-applications'));
    expect(req.request.method).toBe('POST');
    expect(req.request.body.email).toBe('aisha@example.com');
    expect(req.request.body.phone).toBe('+971501234567');
    expect(req.request.body.country_code).toBe('AE');
    req.flush({ application: { id: 7, status: 'pending' } });
    await fixture.whenStable();
    fixture.detectChanges();

    expect(root.querySelector('[data-testid="sell-apply-success"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="sell-apply-form"]')).toBeNull();
  });
});
