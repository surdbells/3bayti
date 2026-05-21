import {
  Component,
  ChangeDetectionStrategy,
  input,
  output,
  computed,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import type { Facets, CatalogFilters, CatalogSort } from '../categories/catalog.service';
import { CATALOG_SORTS } from '../categories/catalog.service';

const SORT_LABELS: Record<CatalogSort, string> = {
  newest:     'Newest',
  oldest:     'Oldest',
  price_asc:  'Price: low to high',
  price_desc: 'Price: high to low',
  relevance:  'Relevance',
  best_seller: 'Best sellers',
};

/**
 * FacetFilters — the sidebar filter panel for the category listing.
 *
 * Fully controlled: the parent owns the filter state and passes it
 * in via `filters`; this component emits `filterChange` events when
 * the user makes a selection, keeping URL-query-string sync in the
 * parent. Receives `facets` (live counts from the API) to annotate
 * each option with its document count.
 *
 * Features:
 *   - Size checkboxes (XS / S / M / L / XL / XXL / shoe sizes)
 *   - Colour checkboxes (black / white / ivory etc.)
 *   - Price-band radio buttons (0-50 / 50-100 / 100-250 / 250-500 / 500+)
 *   - Sort dropdown
 *   - "Clear all filters" — emits a reset event
 *   - All inputs annotated with live counts from the facets API
 *   - Aria labels on every interactive element
 */
@Component({
  selector: 'app-facet-filters',
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <aside class="facets" aria-label="Filter products">

      <!-- ── Sort ── -->
      <section class="facets__group" aria-labelledby="sort-label">
        <h3 id="sort-label" class="facets__group-title">Sort by</h3>
        <select
          class="facets__select"
          [value]="filters().sort ?? 'newest'"
          (change)="onSortChange($event)"
          aria-label="Sort products by">
          @for (s of sorts; track s) {
            <option [value]="s">{{ sortLabel(s) }}</option>
          }
        </select>
      </section>

      <!-- ── Sizes ── -->
      @if (sizeValues().length > 0) {
        <section class="facets__group" aria-labelledby="size-label">
          <h3 id="size-label" class="facets__group-title">Size</h3>
          <ul class="facets__list" role="list">
            @for (v of sizeValues(); track v.value) {
              <li class="facets__item">
                <label class="facets__label">
                  <input
                    type="checkbox"
                    class="facets__checkbox"
                    [checked]="isChecked('sizes', v.value)"
                    (change)="toggleMulti('sizes', v.value)"
                    [attr.aria-label]="'Filter by size ' + v.value + ' (' + v.count + ' products)'"/>
                  <span class="facets__value">{{ v.value }}</span>
                  <span class="facets__count" aria-hidden="true">({{ v.count }})</span>
                </label>
              </li>
            }
          </ul>
        </section>
      }

      <!-- ── Colours ── -->
      @if (colorValues().length > 0) {
        <section class="facets__group" aria-labelledby="color-label">
          <h3 id="color-label" class="facets__group-title">Colour</h3>
          <ul class="facets__list" role="list">
            @for (v of colorValues(); track v.value) {
              <li class="facets__item">
                <label class="facets__label">
                  <input
                    type="checkbox"
                    class="facets__checkbox"
                    [checked]="isChecked('colors', v.value)"
                    (change)="toggleMulti('colors', v.value)"
                    [attr.aria-label]="'Filter by colour ' + v.value + ' (' + v.count + ' products)'"/>
                  <span class="facets__swatch"
                    [style.background]="swatchColor(v.value)"
                    aria-hidden="true"></span>
                  <span class="facets__value">{{ v.value | titlecase }}</span>
                  <span class="facets__count" aria-hidden="true">({{ v.count }})</span>
                </label>
              </li>
            }
          </ul>
        </section>
      }

      <!-- ── Price ── -->
      @if (priceValues().length > 0) {
        <section class="facets__group" aria-labelledby="price-label">
          <h3 id="price-label" class="facets__group-title">Price (AED)</h3>
          <ul class="facets__list" role="list">
            <li class="facets__item">
              <label class="facets__label">
                <input
                  type="radio"
                  class="facets__radio"
                  name="price-band"
                  [checked]="!filters().minPrice && !filters().maxPrice"
                  (change)="setPriceBand(null, null)"
                  aria-label="Any price"/>
                <span class="facets__value">Any price</span>
              </label>
            </li>
            @for (v of priceValues(); track v.value) {
              <li class="facets__item">
                <label class="facets__label">
                  <input
                    type="radio"
                    class="facets__radio"
                    name="price-band"
                    [checked]="isPriceBandActive(v.min, v.max)"
                    (change)="setPriceBand(v.min ?? null, v.max ?? null)"
                    [attr.aria-label]="priceBandLabel(v.min, v.max) + ' (' + v.count + ' products)'"/>
                  <span class="facets__value">{{ priceBandLabel(v.min, v.max) }}</span>
                  <span class="facets__count" aria-hidden="true">({{ v.count }})</span>
                </label>
              </li>
            }
          </ul>
        </section>
      }

      <!-- ── Clear all ── -->
      @if (hasActiveFilters()) {
        <button
          class="facets__clear"
          type="button"
          (click)="clearAll()"
          aria-label="Clear all filters">
          Clear all filters
        </button>
      }
    </aside>
  `,
  styleUrl: './facet-filters.component.scss',
})
export class FacetFiltersComponent {
  readonly filters  = input.required<CatalogFilters>();
  readonly facets   = input<Facets | null>(null);
  readonly filterChange = output<CatalogFilters>();

  readonly sorts = CATALOG_SORTS;

  readonly sizeValues  = computed(() => this.facets()?.size.values  ?? []);
  readonly colorValues = computed(() => this.facets()?.color.values ?? []);
  readonly priceValues = computed(() => this.facets()?.price.values ?? []);

  readonly hasActiveFilters = computed(() => {
    const f = this.filters();
    return (f.sizes?.length ?? 0) > 0
      || (f.colors?.length ?? 0) > 0
      || f.minPrice != null
      || f.maxPrice != null
      || (f.sort && f.sort !== 'newest');
  });

  sortLabel(s: CatalogSort): string {
    return SORT_LABELS[s];
  }

  isChecked(field: 'sizes' | 'colors', value: string): boolean {
    return (this.filters()[field] ?? []).includes(value);
  }

  isPriceBandActive(min?: number, max?: number): boolean {
    const f = this.filters();
    return f.minPrice === (min ?? null) && f.maxPrice === (max ?? null);
  }

  priceBandLabel(min?: number, max?: number): string {
    if (min == null && max == null) return 'Any price';
    if (max == null) return `AED ${min}+`;
    if (min == null || min === 0) return `Under AED ${max}`;
    return `AED ${min} – ${max}`;
  }

  swatchColor(color: string): string {
    // Basic named-colour CSS mapping; falls back to the name itself
    const map: Record<string, string> = {
      black: '#1a1a1a', white: '#ffffff', ivory: '#fffff0',
      cream: '#fffdd0', beige: '#f5f5dc', brown: '#8b4513',
      grey: '#808080', gray: '#808080', navy: '#001f5b',
      blue: '#0000cd', red: '#cc0000', green: '#006400',
      pink: '#ff69b4', purple: '#800080', gold: '#b8860b',
      silver: '#c0c0c0', orange: '#ff8c00', yellow: '#ffd700',
    };
    return map[color.toLowerCase()] ?? color;
  }

  onSortChange(ev: Event): void {
    const sort = (ev.target as HTMLSelectElement).value as CatalogSort;
    this.filterChange.emit({ ...this.filters(), sort });
  }

  toggleMulti(field: 'sizes' | 'colors', value: string): void {
    const current = [...(this.filters()[field] ?? [])];
    const idx = current.indexOf(value);
    if (idx === -1) current.push(value);
    else current.splice(idx, 1);
    this.filterChange.emit({ ...this.filters(), [field]: current });
  }

  setPriceBand(min: number | null, max: number | null): void {
    this.filterChange.emit({ ...this.filters(), minPrice: min, maxPrice: max });
  }

  clearAll(): void {
    this.filterChange.emit({
      category: this.filters().category,
      sort: 'newest',
    });
  }
}
