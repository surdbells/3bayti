import { describe, it, expect, beforeEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';

import { NotFoundPageComponent } from './not-found-page';
import { SeoService } from '../../core/seo/seo.service';
import { provideI18n } from '../../core/i18n';

/**
 * Coverage for the 404 page: it renders the branded not-found content
 * and marks itself noindex so crawlers don't index unknown URLs.
 */
describe('NotFoundPageComponent', () => {
  let seoSet: ReturnType<typeof vi.fn>;

  function setup(): ComponentFixture<NotFoundPageComponent> {
    seoSet = vi.fn();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideI18n(),
        { provide: SeoService, useValue: { set: seoSet, setStructuredData: vi.fn() } },
      ],
    });
    const fixture = TestBed.createComponent(NotFoundPageComponent);
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => {
    TestBed.resetTestingModule();
  });

  it('creates', () => {
    const fixture = setup();
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('renders the 404 numeral and heading', () => {
    const fixture = setup();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';
    expect(text).toContain('404');
    expect(text).toContain("We can't find that page");
  });

  it('sets noindex SEO so unknown URLs are not indexed', () => {
    setup();
    expect(seoSet).toHaveBeenCalledTimes(1);
    expect(seoSet).toHaveBeenCalledWith(
      expect.objectContaining({ robots: 'noindex,follow', title: 'Page not found' }),
    );
  });
});
