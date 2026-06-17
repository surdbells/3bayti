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

describe('SearchOverlayComponent', () => {
  let fixture: ComponentFixture<SearchOverlayComponent>;
  let component: SearchOverlayComponent;
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
    component = fixture.componentInstance;
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
    document.body.style.overflow = '';
  });

  function openOverlay(): void {
    fixture.componentRef.setInput('open', true);
    fixture.detectChanges();
  }

  async function typeQuery(value: string): Promise<void> {
    const input = fixture.nativeElement.querySelector('[data-testid="search-input"]') as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    vi.advanceTimersByTime(300);
    await flushMicro();
    fixture.detectChanges();
  }

  it('renders nothing when closed', () => {
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="search-overlay"]')).toBeNull();
  });

  it('renders a dialog with combobox + listbox roles when open', () => {
    openOverlay();
    expect(fixture.nativeElement.querySelector('[role="dialog"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('input[role="combobox"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[role="listbox"]')).not.toBeNull();
  });

  it('debounces typing then renders tabbed results with Products active by default', async () => {
    service.result = {
      products: [makeProduct('silk-dress', 'Silk Dress')] as never,
      stores: [makeStore('almas', 'Almas Fashion')] as never,
    };
    openOverlay();
    await typeQuery('silk');

    expect(service.calls).toContain('silk');
    // Both tabs render with counts.
    const productsTab = fixture.nativeElement.querySelector('[data-testid="search-tab-products"]');
    const storesTab = fixture.nativeElement.querySelector('[data-testid="search-tab-stores"]');
    expect(productsTab).not.toBeNull();
    expect(storesTab).not.toBeNull();
    expect(productsTab.getAttribute('aria-selected')).toBe('true');
    expect(storesTab.getAttribute('aria-selected')).toBe('false');

    // Products panel is active; product row visible, store row not in DOM yet.
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
    openOverlay();
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
    openOverlay();
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
    openOverlay();
    await typeQuery('zzz');
    expect(fixture.nativeElement.querySelector('[data-testid="search-empty"]')).not.toBeNull();
  });

  it('does not call the API for a blank query and shows the hint', async () => {
    openOverlay();
    await typeQuery('   ');
    expect(service.calls).toHaveLength(0);
    expect(fixture.nativeElement.querySelector('[data-testid="search-hint"]')).not.toBeNull();
  });

  it('emits closed on Escape', () => {
    openOverlay();
    let closed = false;
    component.closed.subscribe(() => (closed = true));
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(closed).toBe(true);
  });

  it('emits closed on backdrop click', () => {
    openOverlay();
    let closed = false;
    component.closed.subscribe(() => (closed = true));
    (fixture.nativeElement.querySelector('[data-testid="search-backdrop"]') as HTMLElement).click();
    expect(closed).toBe(true);
  });
});
