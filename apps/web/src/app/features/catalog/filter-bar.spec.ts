import { describe, it, expect, afterEach, vi } from 'vitest';
import { Component } from '@angular/core';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { FilterBarComponent } from './filter-bar';
import { provideI18n } from '../../core/i18n';
import type { Facets, CatalogFilters } from '../categories/catalog.service';

function makeFacets(): Facets {
  return {
    size: { values: [{ value: 'M', count: 4 }, { value: 'L', count: 2 }], total_distinct: 2 },
    color: { values: [{ value: 'black', count: 6 }, { value: 'ivory', count: 1 }], total_distinct: 2 },
    price: {
      values: [
        { value: '0-100', count: 3, min: 0, max: 100 },
        { value: '100-250', count: 5, min: 100, max: 250 },
      ],
    },
    vendor: { values: [], total_distinct: 0 },
    category: { values: [], total_distinct: 0 },
    total_products: 8,
  };
}

/**
 * Host wrapper — the component uses signal inputs, which this repo's
 * vitest/unit-test toolchain can't drive via componentRef.setInput
 * (see gift-card-visual.ts). Binding through a host template is the
 * standard alternative and exercises the real input/output contract.
 */
@Component({
  standalone: true,
  imports: [FilterBarComponent],
  template: `<app-filter-bar [filters]="filters" [facets]="facets" (filterChange)="onChange($event)" />`,
})
class HostComponent {
  filters: CatalogFilters = { category: 'abayas', sort: 'newest' };
  facets: Facets | null = makeFacets();
  emitted: CatalogFilters[] = [];
  onChange(f: CatalogFilters): void {
    this.emitted.push(f);
  }
}

function setup(overrides: Partial<HostComponent> = {}): {
  fixture: ComponentFixture<HostComponent>;
  host: HostComponent;
  el: HTMLElement;
} {
  TestBed.configureTestingModule({
    imports: [HostComponent],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideI18n()],
  });
  const fixture = TestBed.createComponent(HostComponent);
  const host = fixture.componentInstance;
  Object.assign(host, overrides);
  fixture.detectChanges();
  return { fixture, host, el: fixture.nativeElement as HTMLElement };
}

const q = (el: HTMLElement, sel: string) => el.querySelector(sel) as HTMLElement | null;
const click = (node: HTMLElement | null) => node?.dispatchEvent(new MouseEvent('click', { bubbles: true }));

describe('FilterBarComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the bar with a sort chip plus a chip per facet that has values', () => {
    const { el } = setup();
    expect(q(el, '[data-testid="filter-bar"]')).not.toBeNull();
    expect(q(el, '[data-testid="chip-sort"]')).not.toBeNull();
    expect(q(el, '[data-testid="chip-size"]')).not.toBeNull();
    expect(q(el, '[data-testid="chip-color"]')).not.toBeNull();
    expect(q(el, '[data-testid="chip-price"]')).not.toBeNull();
  });

  it('omits a facet chip when that facet has no values', () => {
    const facets = makeFacets();
    facets.size.values = [];
    const { el } = setup({ facets });
    expect(q(el, '[data-testid="chip-size"]')).toBeNull();
    expect(q(el, '[data-testid="chip-color"]')).not.toBeNull();
  });

  it('opens a popover when its chip is clicked and toggles it closed again', () => {
    const { fixture, el } = setup();
    expect(q(el, '[data-testid="pop-size"]')).toBeNull();

    click(q(el, '[data-testid="chip-size"]'));
    fixture.detectChanges();
    expect(q(el, '[data-testid="pop-size"]')).not.toBeNull();
    expect(q(el, '[data-testid="chip-size"]')!.getAttribute('aria-expanded')).toBe('true');

    click(q(el, '[data-testid="chip-size"]'));
    fixture.detectChanges();
    expect(q(el, '[data-testid="pop-size"]')).toBeNull();
  });

  it('emits a toggled multi-value selection when a size option is checked', () => {
    const { fixture, el, host } = setup();
    click(q(el, '[data-testid="chip-size"]'));
    fixture.detectChanges();

    const firstCheckbox = q(el, '[data-testid="pop-size"] input[type="checkbox"]') as HTMLInputElement;
    firstCheckbox.dispatchEvent(new Event('change', { bubbles: true }));

    expect(host.emitted).toHaveLength(1);
    expect(host.emitted[0].sizes).toEqual(['M']);
  });

  it('emits the chosen sort and closes the popover', () => {
    const { fixture, el, host } = setup();
    click(q(el, '[data-testid="chip-sort"]'));
    fixture.detectChanges();

    const radios = el.querySelectorAll('[data-testid="pop-sort"] input[type="radio"]');
    // CATALOG_SORTS order: newest, oldest, price_asc, ... — pick price_asc (index 2)
    (radios[2] as HTMLInputElement).dispatchEvent(new Event('change', { bubbles: true }));

    expect(host.emitted[0].sort).toBe('price_asc');
    fixture.detectChanges();
    expect(q(el, '[data-testid="pop-sort"]')).toBeNull();
  });

  it('shows the active-count clear button only when facet filters are active, and resets on click', () => {
    const { el, host } = setup({
      filters: { category: 'abayas', sort: 'newest', sizes: ['M'], colors: ['black'] },
    });
    const clear = q(el, '[data-testid="filter-clear"]');
    expect(clear).not.toBeNull();
    expect(clear!.textContent).toContain('2');

    click(clear);
    expect(host.emitted[0]).toEqual({ category: 'abayas', sort: 'newest' });
  });

  it('hides the clear button when no facet filters are active', () => {
    const { el } = setup({ filters: { category: 'abayas', sort: 'best_seller' } });
    expect(q(el, '[data-testid="filter-clear"]')).toBeNull();
  });

  it('closes an open popover via the outside-click catcher', () => {
    const { fixture, el } = setup();
    click(q(el, '[data-testid="chip-price"]'));
    fixture.detectChanges();
    expect(q(el, '[data-testid="pop-price"]')).not.toBeNull();

    click(q(el, '[data-testid="filter-catcher"]'));
    fixture.detectChanges();
    expect(q(el, '[data-testid="pop-price"]')).toBeNull();
  });

  it('opens and closes the mobile bottom sheet', () => {
    const { fixture, el } = setup();
    expect(q(el, '[data-testid="filter-sheet"]')).toBeNull();

    click(q(el, '[data-testid="filter-mobile-btn"]'));
    fixture.detectChanges();
    expect(q(el, '[data-testid="filter-sheet"]')).not.toBeNull();

    click(q(el, '[data-testid="filter-sheet-close"]'));
    fixture.detectChanges();
    expect(q(el, '[data-testid="filter-sheet"]')).toBeNull();
  });

  it('handles facet price bounds that arrive as decimal strings (real API shape)', () => {
    // The API serialises price-band bounds as strings ("50.00"); the bar
    // must coerce them so the band matches the active filter and emits
    // NUMERIC min/max (otherwise catalog.service drops them).
    const facets = makeFacets();
    facets.price.values = [
      { value: '50-100', count: 3, min: '50.00' as unknown as number, max: '100.00' as unknown as number },
      { value: '100-250', count: 5, min: '100.00' as unknown as number, max: '250.00' as unknown as number },
    ];
    const { fixture, el, host } = setup({
      facets,
      filters: { category: 'abayas', sort: 'newest', minPrice: 50, maxPrice: 100 },
    });

    click(q(el, '[data-testid="chip-price"]'));
    fixture.detectChanges();

    const radios = el.querySelectorAll('[data-testid="pop-price"] input[type="radio"]');
    // radios[0] = "Any", [1] = 50-100 (active), [2] = 100-250
    expect((radios[1] as HTMLInputElement).checked).toBe(true);

    // Selecting the 100-250 band emits numeric bounds.
    (radios[2] as HTMLInputElement).dispatchEvent(new Event('change', { bubbles: true }));
    const last = host.emitted.at(-1)!;
    expect(last.minPrice).toBe(100);
    expect(last.maxPrice).toBe(250);
    expect(typeof last.minPrice).toBe('number');
  });

  it('closes an open surface on Escape', () => {
    const { fixture, el } = setup();
    click(q(el, '[data-testid="chip-size"]'));
    fixture.detectChanges();
    expect(q(el, '[data-testid="pop-size"]')).not.toBeNull();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    fixture.detectChanges();
    expect(q(el, '[data-testid="pop-size"]')).toBeNull();
  });
});
