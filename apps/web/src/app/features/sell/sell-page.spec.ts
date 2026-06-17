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

  it('points seller CTAs at the external seller-app register URL', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    const hero = root.querySelector('[data-testid="sell-hero-register"]') as HTMLAnchorElement;
    const cta = root.querySelector('[data-testid="sell-cta-register"]') as HTMLAnchorElement;
    expect(hero.getAttribute('href')).toBe('https://seller.test/register');
    expect(cta.getAttribute('href')).toBe('https://seller.test/register');
    // External link hygiene.
    expect(hero.getAttribute('target')).toBe('_blank');
    expect(hero.getAttribute('rel')).toContain('noopener');
  });
});
