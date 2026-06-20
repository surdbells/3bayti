import {
  Component,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  EventEmitter,
  Input,
  Output,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';

import { AxBottomSheetComponent } from '../bottom-sheet';
import { AxIconComponent } from '../icon';
import { TranslatePipe } from '../../../translate.pipe';
import {
  type FacetValue,
  type ProductFacets,
  type ProductFilterState,
  type ProductSort,
  LISTING_SORTS,
} from './product-filter.types';

/** i18n key for each sort value — web-matching labels. */
const SORT_LABEL_KEYS: Record<ProductSort, string> = {
  newest: 'filter_sort_newest',
  oldest: 'filter_sort_oldest',
  price_asc: 'filter_sort_price_asc',
  price_desc: 'filter_sort_price_desc',
  best_seller: 'filter_sort_best_seller',
};

/** Named-colour → CSS swatch mapping; falls back to the raw value. */
const SWATCH: Record<string, string> = {
  black: '#1a1a1a', white: '#ffffff', ivory: '#fffff0', cream: '#fffdd0',
  beige: '#f5f5dc', brown: '#8b4513', grey: '#808080', gray: '#808080',
  navy: '#001f5b', blue: '#0000cd', red: '#cc0000', green: '#006400',
  pink: '#ff69b4', purple: '#800080', gold: '#b8860b', silver: '#c0c0c0',
  orange: '#ff8c00', yellow: '#ffd700',
};

/**
 * AxProductFilterSheet — web-parity SORT / SIZE / COLOR / PRICE refinement
 * for the mobile listing pages (best-sellers, new-arrivals, category).
 *
 * Renders inside the shared ax-bottom-sheet. The host page owns the
 * committed filter state and the facet data; the sheet holds a working
 * DRAFT copy that only commits on Apply (matching apps/web's sheet
 * semantics). Clear all resets the draft to the page's identity sort with
 * no facet selections.
 *
 *   <ax-product-filter-sheet
 *     [isOpen]="isFilterOpen"
 *     [facets]="facets"
 *     [state]="filterState"
 *     [defaultSort]="'best_seller'"
 *     (apply)="onFilterApply($event)"
 *     (didDismiss)="isFilterOpen = false" />
 *
 * SORT is single-select; SIZE / COLOR are multi-select chips with live
 * facet counts; PRICE is min + a set of facet price bands (max ceiling).
 */
@Component({
  selector: 'ax-product-filter-sheet',
  standalone: true,
  imports: [CommonModule, TranslatePipe, AxBottomSheetComponent, AxIconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-product-filter-sheet.component.html',
  styleUrls: ['./ax-product-filter-sheet.component.scss'],
})
export class AxProductFilterSheetComponent {
  private readonly cdr = inject(ChangeDetectorRef);

  readonly sortKeys = SORT_LABEL_KEYS;

  /** Sort options the page allows (all listing sorts by default). */
  @Input() sorts: readonly ProductSort[] = LISTING_SORTS;

  /** The page's identity sort — what Clear all resets to. */
  @Input() defaultSort: ProductSort = 'newest';

  /** Facet data from GET /products/facets (unwrapped `data`). */
  @Input() facets: ProductFacets | null = null;

  /** Open/close binding, driven by the host page. */
  @Input()
  set isOpen(v: boolean) {
    const opening = v && !this._isOpen;
    this._isOpen = v;
    // Snapshot the committed state into the editable draft each time the
    // sheet opens, so an un-applied edit from a previous open is discarded.
    if (opening) {
      this.draft = this.cloneState(this._state);
    }
  }
  get isOpen(): boolean {
    return this._isOpen;
  }
  private _isOpen = false;

  /** The committed filter state owned by the host page. */
  @Input()
  set state(v: ProductFilterState) {
    this._state = v;
    if (!this._isOpen) {
      this.draft = this.cloneState(v);
    }
  }
  get state(): ProductFilterState {
    return this._state;
  }
  private _state: ProductFilterState = {
    sort: 'newest',
    sizes: [],
    colors: [],
    minPrice: null,
    maxPrice: null,
  };

  /** Emits the chosen filter state when the user taps Apply. */
  @Output() apply = new EventEmitter<ProductFilterState>();

  /** Emits when the sheet is dismissed (any reason) so the host clears isOpen. */
  @Output() didDismiss = new EventEmitter<void>();

  /** Editable working copy; only committed via Apply. */
  draft: ProductFilterState = this.cloneState(this._state);

  // ── Facet accessors ────────────────────────────────────────────────
  sizeValues(): FacetValue[] {
    return this.facets?.size?.values ?? [];
  }
  colorValues(): FacetValue[] {
    return this.facets?.color?.values ?? [];
  }
  priceValues(): FacetValue[] {
    return this.facets?.price?.values ?? [];
  }

  hasAnySection(): boolean {
    return (
      this.sizeValues().length > 0 ||
      this.colorValues().length > 0 ||
      this.priceValues().length > 0
    );
  }

  // ── Labels / display helpers ───────────────────────────────────────
  swatch(color: string): string {
    return SWATCH[color.toLowerCase()] ?? color;
  }

  /** Coerce a band bound (string|number|null) to a number or null. */
  private toNum(v: string | number | null | undefined): number | null {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isNaN(n) ? null : n;
  }

  bandMin(v: FacetValue): number | null {
    return this.toNum(v.min);
  }
  bandMax(v: FacetValue): number | null {
    return this.toNum(v.max);
  }

  // ── Draft selection state ──────────────────────────────────────────
  isSortActive(s: ProductSort): boolean {
    return this.draft.sort === s;
  }

  isChipActive(field: 'sizes' | 'colors', value: string): boolean {
    return this.draft[field].includes(value);
  }

  isBandActive(v: FacetValue): boolean {
    return this.draft.minPrice === this.bandMin(v) && this.draft.maxPrice === this.bandMax(v);
  }

  isPriceAnyActive(): boolean {
    return this.draft.minPrice === null && this.draft.maxPrice === null;
  }

  /** Count of active facet selections (size + colour + price). Sort excluded. */
  activeCount(): number {
    const priceActive = this.draft.minPrice !== null || this.draft.maxPrice !== null;
    return this.draft.sizes.length + this.draft.colors.length + (priceActive ? 1 : 0);
  }

  // ── Mutations on the draft ─────────────────────────────────────────
  setSort(s: ProductSort): void {
    this.draft = { ...this.draft, sort: s };
    this.cdr.markForCheck();
  }

  toggleChip(field: 'sizes' | 'colors', value: string): void {
    const current = [...this.draft[field]];
    const idx = current.indexOf(value);
    if (idx === -1) current.push(value);
    else current.splice(idx, 1);
    this.draft = { ...this.draft, [field]: current };
    this.cdr.markForCheck();
  }

  setBand(v: FacetValue): void {
    this.draft = { ...this.draft, minPrice: this.bandMin(v), maxPrice: this.bandMax(v) };
    this.cdr.markForCheck();
  }

  setPriceAny(): void {
    this.draft = { ...this.draft, minPrice: null, maxPrice: null };
    this.cdr.markForCheck();
  }

  /** Min-price text input (the band max-ceiling is still chosen via bands). */
  onMinPriceInput(raw: string): void {
    const trimmed = (raw ?? '').trim();
    const n = trimmed === '' ? null : Number(trimmed);
    this.draft = { ...this.draft, minPrice: n === null || Number.isNaN(n) ? null : n };
    this.cdr.markForCheck();
  }

  // ── Footer actions ─────────────────────────────────────────────────
  onApply(): void {
    this.apply.emit(this.cloneState(this.draft));
    this.didDismiss.emit();
  }

  onClearAll(): void {
    this.draft = {
      sort: this.defaultSort,
      sizes: [],
      colors: [],
      minPrice: null,
      maxPrice: null,
    };
    this.cdr.markForCheck();
  }

  onDismiss(): void {
    this.didDismiss.emit();
  }

  // ── Helpers ────────────────────────────────────────────────────────
  private cloneState(s: ProductFilterState): ProductFilterState {
    return {
      sort: s.sort,
      sizes: [...s.sizes],
      colors: [...s.colors],
      minPrice: s.minPrice,
      maxPrice: s.maxPrice,
    };
  }
}
