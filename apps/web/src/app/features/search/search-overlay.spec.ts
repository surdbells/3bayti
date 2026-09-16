import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { SearchOverlayComponent } from './search-overlay';
import { SearchService, type SearchResults } from './search.service';
import { provideI18n } from '../../core/i18n';

class StubSearchService {
  result: SearchResults = { products: [], stores: [] };
  calls: string[] = [];
  async search(query: string): Promise<SearchResults> {
    this.calls.push(query);
    return this.result;
  }
}

function makeProduct(slug: string, name: string): unknown {
  return {
    id: 1, slug, name,
    price: { amount: 199, currency: 'AED' },
    primary_image: null, in_stock: true,
  };
}

function makeStore(slug: string, name: string): unknown {
  return {
    id: 1, slug, name, description: null,
    logo_url: null, cover_image_url: null, is_verified: false,
    rating: 4.5, rating_count: 12, products: [],
  };
}

async function flushMicro(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

/**
 * The search component is a PERSISTENT bar (always rendered under the nav); a
 * results panel drops beneath it on focus / while typing. There is no modal
 * open/close input any more, so these tests drive it via focus + input.
 */
describe('SearchOverlayComponent (persistent bar)', () => {
  let fixture: ComponentFixture<SearchOverlayComponent>;
  let service: StubSearchService;

  beforeEach(() => {
    vi.useFakeTimers();
    service = new StubSearchService();
    TestBed.configureTestingModule({
      imports: [SearchOverlayComponent],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideI18n(),
        { provide: SearchService, useValue: service },
      ],
    });
    fixture = TestBed.createComponent(SearchOverlayComponent);
    fixture.detectChanges();
  });

  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => {
        if (!req.cancelled) req.flush({});
      });
    } catch { /* ignore (i18n fetches) */ }
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
    TestBed.resetTestingModule();
  });

  function input(): HTMLInputElement {
    return fixture.nativeElement.querySelector('[data-testid="search-input"]') as HTMLInputElement;
  }

  function focusBar(): void {
    input().dispatchEvent(new Event('focus'));
    fixture.detectChanges();
  }

  async function typeQuery(value: string): Promise<void> {
    const el = input();
    el.value = value;
    el.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(300);
    await flushMicro();
    fixture.detectChanges();
  }

  it('renders the persistent bar (input visible without interaction; panel closed)', () => {
    expect(fixture.nativeElement.querySelector('[data-testid="search-bar"]')).not.toBeNull();
    expect(input()).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="search-overlay"]')).toBeNull();
  });

  it('opens the results panel on focus with combobox + listbox roles and the idle hint', () => {
    focusBar();
    expect(fixture.nativeElement.querySelector('[data-testid="search-overlay"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('input[role="combobox"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="search-hint"]')).not.toBeNull();
  });

  it('debounces typing then renders tabbed results with Products active by default', async () => {
    service.result = {
      products: [makeProduct('silk-dress', 'Silk Dress')] as never,
      stores: [makeStore('almas', 'Almas Fashion')] as never,
    };
    await typeQuery('silk');

    expect(service.calls).toContain('silk');
    const productsTab = fixture.nativeElement.querySelector('[data-testid="search-tab-products"]');
    const storesTab = fixture.nativeElement.querySelector('[data-testid="search-tab-stores"]');
    expect(productsTab).not.toBeNull();
    expect(storesTab).not.toBeNull();
    expect(productsTab.getAttribute('aria-selected')).toBe('true');
    expect(storesTab.getAttribute('aria-selected')).toBe('false');

    expect(fixture.nativeElement.querySelector('[data-testid="search-products"]')).not.toBeNull();
    expect(
      fixture.nativeElement.querySelector('[data-testid="search-product-row"]').textContent,
    ).toContain('Silk Dress');
    expect(fixture.nativeElement.querySelector('[data-testid="search-store-row"]')).toBeNull();
  });

  it('switches to the Stores tab on click and reveals store rows', async () => {
    service.result = {
      products: [makeProduct('silk-dress', 'Silk Dress')] as never,
      stores: [makeStore('almas', 'Almas Fashion')] as never,
    };
    await typeQuery('silk');

    (fixture.nativeElement.querySelector('[data-testid="search-tab-stores"]') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(
      fixture.nativeElement.querySelector('[data-testid="search-tab-stores"]').getAttribute('aria-selected'),
    ).toBe('true');
    expect(
      fixture.nativeElement.querySelector('[data-testid="search-store-row"]').textContent,
    ).toContain('Almas Fashion');
    expect(fixture.nativeElement.querySelector('[data-testid="search-product-row"]')).toBeNull();
  });

  it('auto-selects the Stores tab when there are no product matches', async () => {
    service.result = {
      products: [] as never,
      stores: [makeStore('almas', 'Almas Fashion')] as never,
    };
    await typeQuery('almas');

    expect(
      fixture.nativeElement.querySelector('[data-testid="search-tab-stores"]').getAttribute('aria-selected'),
    ).toBe('true');
    expect(
      fixture.nativeElement.querySelector('[data-testid="search-store-row"]').textContent,
    ).toContain('Almas Fashion');
  });

  it('shows the empty state when a search returns nothing', async () => {
    service.result = { products: [], stores: [] };
    await typeQuery('zzz');
    expect(fixture.nativeElement.querySelector('[data-testid="search-empty"]')).not.toBeNull();
  });

  it('does not call the API for a blank query and shows the hint', async () => {
    await typeQuery('   ');
    expect(service.calls).toHaveLength(0);
    expect(fixture.nativeElement.querySelector('[data-testid="search-hint"]')).not.toBeNull();
  });

  it('closes the results panel on Escape while the bar stays', async () => {
    service.result = { products: [makeProduct('silk-dress', 'Silk Dress')] as never, stores: [] };
    await typeQuery('silk');
    expect(fixture.nativeElement.querySelector('[data-testid="search-overlay"]')).not.toBeNull();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[data-testid="search-overlay"]')).toBeNull();
    expect(input()).not.toBeNull(); // bar persists
  });

  it('closes the results panel on an outside click', () => {
    focusBar();
    expect(fixture.nativeElement.querySelector('[data-testid="search-overlay"]')).not.toBeNull();

    document.dispatchEvent(new Event('pointerdown')); // target = document (outside the bar)
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('[data-testid="search-overlay"]')).toBeNull();
  });
});
