import {
  Component,
  ChangeDetectionStrategy,
  Input,
  Output,
  EventEmitter,
  signal,
  inject,
  HostListener,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import type { Facets, CatalogFilters, CatalogSort } from '../categories/catalog.service';
import { CATALOG_SORTS } from '../categories/catalog.service';

/** Translation keys for each sort value (shared vocabulary with the category sort menu). */
const SORT_LABELS: Record<CatalogSort, string> = {
  newest: 'categories.sort.newest',
  oldest: 'categories.sort.oldest',
  price_asc: 'categories.sort.priceAsc',
  price_desc: 'categories.sort.priceDesc',
  relevance: 'categories.sort.relevance',
  best_seller: 'categories.sort.bestSeller',
};

/** Named-colour → CSS swatch mapping; falls back to the raw value. */
const SWATCH: Record<string, string> = {
  black: '#1a1a1a', white: '#ffffff', ivory: '#fffff0', cream: '#fffdd0',
  beige: '#f5f5dc', brown: '#8b4513', grey: '#808080', gray: '#808080',
  navy: '#001f5b', blue: '#0000cd', red: '#cc0000', green: '#006400',
  pink: '#ff69b4', purple: '#800080', gold: '#b8860b', silver: '#c0c0c0',
  orange: '#ff8c00', yellow: '#ffd700',
};

/** Which facet group a desktop popover is showing (null = all closed). */
type OpenChip = 'sort' | 'size' | 'color' | 'price' | null;

/**
 * FilterBar — the unified, premium product-filter surface (web-uplift #5).
 *
 * Replaces the old vertical `FacetFilters` sidebar with a horizontal bar
 * of dropdown "chips" (Sort · Size · Colour · Price), an active-filter
 * count, and a clear-all. On mobile (<640px) the whole bar collapses to
 * a single "Filters" button that opens a bottom sheet with every group
 * stacked. Both layouts live in the DOM and are toggled purely by CSS
 * media queries, so the component is SSR-safe and needs no resize logic.
 *
 * Fully controlled (stateless re: filter values): the parent owns the
 * `filters` (normally URL-synced) and passes live `facets` for counts;
 * the bar emits `filterChange` on every selection. Drop-in compatible
 * with the FacetFilters contract, so listings + category share it.
 *
 * Group bodies are declared once as <ng-template> blocks and rendered in
 * both the desktop popovers and the mobile sheet via ngTemplateOutlet —
 * no markup duplication.
 *
 * a11y: each chip is a button with aria-haspopup + aria-expanded; the
 * mobile sheet is role=dialog/aria-modal; Esc closes any open surface;
 * RTL is handled with logical CSS properties in the stylesheet.
 */
@Component({
  selector: 'app-filter-bar',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <!-- ============================ DESKTOP / TABLET BAR ============================ -->
    <div class="filter-bar" data-testid="filter-bar" [attr.aria-label]="'filters.title' | translate">
      <span class="filter-bar__lead" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
             stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
          <line x1="4" y1="6" x2="20" y2="6"></line><circle cx="9" cy="6" r="2.2"></circle>
          <line x1="4" y1="12" x2="20" y2="12"></line><circle cx="15" cy="12" r="2.2"></circle>
          <line x1="4" y1="18" x2="20" y2="18"></line><circle cx="8" cy="18" r="2.2"></circle>
        </svg>
        <span class="filter-bar__lead-text">{{ 'filters.title' | translate }}</span>
      </span>

      <!-- Sort chip (always present) -->
      <div class="filter-bar__chip-wrap">
        <button type="button" class="filter-bar__chip" data-testid="chip-sort"
          [class.filter-bar__chip--active]="filters().sort && filters().sort !== 'newest'"
          [attr.aria-expanded]="openChip() === 'sort'" aria-haspopup="true"
          (click)="toggleChip('sort')">
          <span class="filter-bar__chip-label">{{ 'filters.sort' | translate }}</span>
          <span class="filter-bar__chip-value">{{ sortLabel(filters().sort ?? 'newest') | translate }}</span>
          <svg class="filter-bar__caret" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
            <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
        @if (openChip() === 'sort') {
          <div class="filter-bar__pop" role="group" [attr.aria-label]="'filters.sort' | translate" data-testid="pop-sort">
            <ng-container [ngTemplateOutlet]="sortTpl"></ng-container>
          </div>
        }
      </div>

      <!-- Size chip -->
      @if (sizeValues().length > 0) {
        <div class="filter-bar__chip-wrap">
          <button type="button" class="filter-bar__chip" data-testid="chip-size"
            [class.filter-bar__chip--active]="(filters().sizes?.length ?? 0) > 0"
            [attr.aria-expanded]="openChip() === 'size'" aria-haspopup="true"
            (click)="toggleChip('size')">
            <span class="filter-bar__chip-label">{{ 'filters.size' | translate }}</span>
            @if ((filters().sizes?.length ?? 0) > 0) {
              <span class="filter-bar__chip-badge">{{ filters().sizes!.length }}</span>
            }
            <svg class="filter-bar__caret" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
              <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          @if (openChip() === 'size') {
            <div class="filter-bar__pop" role="group" [attr.aria-label]="'filters.size' | translate" data-testid="pop-size">
              <ng-container [ngTemplateOutlet]="sizeTpl"></ng-container>
            </div>
          }
        </div>
      }

      <!-- Colour chip -->
      @if (colorValues().length > 0) {
        <div class="filter-bar__chip-wrap">
          <button type="button" class="filter-bar__chip" data-testid="chip-color"
            [class.filter-bar__chip--active]="(filters().colors?.length ?? 0) > 0"
            [attr.aria-expanded]="openChip() === 'color'" aria-haspopup="true"
            (click)="toggleChip('color')">
            <span class="filter-bar__chip-label">{{ 'filters.colour' | translate }}</span>
            @if ((filters().colors?.length ?? 0) > 0) {
              <span class="filter-bar__chip-badge">{{ filters().colors!.length }}</span>
            }
            <svg class="filter-bar__caret" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
              <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          @if (openChip() === 'color') {
            <div class="filter-bar__pop" role="group" [attr.aria-label]="'filters.colour' | translate" data-testid="pop-color">
              <ng-container [ngTemplateOutlet]="colorTpl"></ng-container>
            </div>
          }
        </div>
      }

      <!-- Price chip -->
      @if (priceValues().length > 0) {
        <div class="filter-bar__chip-wrap">
          <button type="button" class="filter-bar__chip" data-testid="chip-price"
            [class.filter-bar__chip--active]="hasPrice()"
            [attr.aria-expanded]="openChip() === 'price'" aria-haspopup="true"
            (click)="toggleChip('price')">
            <span class="filter-bar__chip-label">{{ 'filters.price' | translate }}</span>
            @if (hasPrice()) {
              <span class="filter-bar__chip-value">{{ activePriceLabel() }}</span>
            }
            <svg class="filter-bar__caret" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
              <path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          @if (openChip() === 'price') {
            <div class="filter-bar__pop" role="group" [attr.aria-label]="'filters.price' | translate" data-testid="pop-price">
              <ng-container [ngTemplateOutlet]="priceTpl"></ng-container>
            </div>
          }
        </div>
      }

      <!-- Clear all (desktop) -->
      @if (activeCount() > 0) {
        <button type="button" class="filter-bar__clear" (click)="clearAll()" data-testid="filter-clear">
          {{ 'filters.clear' | translate }}
          <span class="filter-bar__clear-count" aria-hidden="true">{{ activeCount() }}</span>
          <span class="filter-bar__sr">{{ 'filters.activeAria' | translate: { count: activeCount() } }}</span>
        </button>
      }

      <!-- Click-catcher: closes an open popover on outside click -->
      @if (openChip() !== null) {
        <div class="filter-bar__catcher" (click)="closeChips()" aria-hidden="true" data-testid="filter-catcher"></div>
      }
    </div>

    <!-- ============================ MOBILE TRIGGER ============================ -->
    <div class="filter-bar__mobile">
      <button type="button" class="filter-bar__mobile-btn" (click)="openSheet()" data-testid="filter-mobile-btn"
        [attr.aria-haspopup]="'dialog'" [attr.aria-expanded]="sheetOpen()">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none"
             stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
          <line x1="4" y1="6" x2="20" y2="6"></line><circle cx="9" cy="6" r="2.2"></circle>
          <line x1="4" y1="12" x2="20" y2="12"></line><circle cx="15" cy="12" r="2.2"></circle>
          <line x1="4" y1="18" x2="20" y2="18"></line><circle cx="8" cy="18" r="2.2"></circle>
        </svg>
        {{ 'filters.all' | translate }}
        @if (activeCount() > 0) {
          <span class="filter-bar__chip-badge">{{ activeCount() }}</span>
        }
      </button>
    </div>

    <!-- ============================ MOBILE BOTTOM SHEET ============================ -->
    @if (sheetOpen()) {
      <div class="filter-bar__sheet" data-testid="filter-sheet">
        <div class="filter-bar__sheet-backdrop" (click)="closeSheet()" aria-hidden="true"></div>
        <div class="filter-bar__sheet-panel" role="dialog" aria-modal="true"
             [attr.aria-label]="'filters.title' | translate">
          <header class="filter-bar__sheet-head">
            <h2 class="filter-bar__sheet-title">{{ 'filters.title' | translate }}</h2>
            <button type="button" class="filter-bar__sheet-close" (click)="closeSheet()"
              [attr.aria-label]="'common.close' | translate" data-testid="filter-sheet-close">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none"
                   stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                <line x1="6" y1="6" x2="18" y2="18"></line><line x1="18" y1="6" x2="6" y2="18"></line>
              </svg>
            </button>
          </header>

          <div class="filter-bar__sheet-body">
            <section class="filter-bar__sheet-group">
              <h3 class="filter-bar__sheet-group-title">{{ 'filters.sort' | translate }}</h3>
              <ng-container [ngTemplateOutlet]="sortTpl"></ng-container>
            </section>
            @if (sizeValues().length > 0) {
              <section class="filter-bar__sheet-group">
                <h3 class="filter-bar__sheet-group-title">{{ 'filters.size' | translate }}</h3>
                <ng-container [ngTemplateOutlet]="sizeTpl"></ng-container>
              </section>
            }
            @if (colorValues().length > 0) {
              <section class="filter-bar__sheet-group">
                <h3 class="filter-bar__sheet-group-title">{{ 'filters.colour' | translate }}</h3>
                <ng-container [ngTemplateOutlet]="colorTpl"></ng-container>
              </section>
            }
            @if (priceValues().length > 0) {
              <section class="filter-bar__sheet-group">
                <h3 class="filter-bar__sheet-group-title">{{ 'filters.price' | translate }}</h3>
                <ng-container [ngTemplateOutlet]="priceTpl"></ng-container>
              </section>
            }
          </div>

          <footer class="filter-bar__sheet-foot">
            <button type="button" class="filter-bar__sheet-clear" (click)="clearAll()"
              [disabled]="activeCount() === 0" data-testid="filter-sheet-clear">
              {{ 'filters.clear' | translate }}
            </button>
            <button type="button" class="filter-bar__sheet-apply" (click)="closeSheet()" data-testid="filter-sheet-apply">
              {{ 'filters.apply' | translate }}
            </button>
          </footer>
        </div>
      </div>
    }

    <!-- ============================ SHARED GROUP BODIES ============================ -->
    <ng-template #sortTpl>
      <ul class="filter-bar__opts" role="list">
        @for (s of sorts; track s) {
          <li>
            <label class="filter-bar__opt">
              <input type="radio" name="filter-sort" class="filter-bar__radio"
                [checked]="(filters().sort ?? 'newest') === s"
                (change)="setSort(s)"
                [attr.aria-label]="sortLabel(s) | translate"/>
              <span class="filter-bar__opt-text">{{ sortLabel(s) | translate }}</span>
            </label>
          </li>
        }
      </ul>
    </ng-template>

    <ng-template #sizeTpl>
      <ul class="filter-bar__opts" role="list">
        @for (v of sizeValues(); track v.value) {
          <li>
            <label class="filter-bar__opt">
              <input type="checkbox" class="filter-bar__check"
                [checked]="isChecked('sizes', v.value)"
                (change)="toggleMulti('sizes', v.value)"
                [attr.aria-label]="'categories.filterSizeAria' | translate: { value: v.value, count: v.count }"/>
              <span class="filter-bar__opt-text">{{ v.value }}</span>
              <span class="filter-bar__opt-count" aria-hidden="true">{{ v.count }}</span>
            </label>
          </li>
        }
      </ul>
    </ng-template>

    <ng-template #colorTpl>
      <ul class="filter-bar__opts" role="list">
        @for (v of colorValues(); track v.value) {
          <li>
            <label class="filter-bar__opt">
              <input type="checkbox" class="filter-bar__check"
                [checked]="isChecked('colors', v.value)"
                (change)="toggleMulti('colors', v.value)"
                [attr.aria-label]="'categories.filterColorAria' | translate: { value: v.value, count: v.count }"/>
              <span class="filter-bar__swatch" [style.background]="swatch(v.value)" aria-hidden="true"></span>
              <span class="filter-bar__opt-text">{{ v.value | titlecase }}</span>
              <span class="filter-bar__opt-count" aria-hidden="true">{{ v.count }}</span>
            </label>
          </li>
        }
      </ul>
    </ng-template>

    <ng-template #priceTpl>
      <ul class="filter-bar__opts" role="list">
        <li>
          <label class="filter-bar__opt">
            <input type="radio" name="filter-price" class="filter-bar__radio"
              [checked]="!hasPrice()" (change)="setPriceBand(null, null)"
              [attr.aria-label]="'categories.price.any' | translate"/>
            <span class="filter-bar__opt-text">{{ 'categories.price.any' | translate }}</span>
          </label>
        </li>
        @for (v of priceValues(); track v.value) {
          <li>
            <label class="filter-bar__opt">
              <input type="radio" name="filter-price" class="filter-bar__radio"
                [checked]="isPriceBandActive(v.min, v.max)"
                (change)="setPriceBand(v.min ?? null, v.max ?? null)"
                [attr.aria-label]="'categories.priceBandAria' | translate: { label: priceBandLabel(v.min, v.max), count: v.count }"/>
              <span class="filter-bar__opt-text">{{ priceBandLabel(v.min, v.max) }}</span>
              <span class="filter-bar__opt-count" aria-hidden="true">{{ v.count }}</span>
            </label>
          </li>
        }
      </ul>
    </ng-template>
  `,
  styleUrl: './filter-bar.scss',
})
export class FilterBarComponent {
  private readonly i18n = inject(TranslateService);

  /**
   * Inputs use the `@Input()` decorator (not signal `input()`) to stay
   * compatible with this repo's vitest unit-test toolchain, which does not
   * register signal inputs (see gift-card-visual.ts for the same note).
   * Aliased backing fields are exposed through `filters()` / `facets()`
   * accessors so the rest of the component reads them call-style.
   */
  @Input({ required: true, alias: 'filters' }) filtersValue!: CatalogFilters;
  @Input({ alias: 'facets' }) facetsValue: Facets | null = null;
  @Output() filterChange = new EventEmitter<CatalogFilters>();

  filters(): CatalogFilters {
    return this.filtersValue;
  }
  facets(): Facets | null {
    return this.facetsValue;
  }

  readonly sorts = CATALOG_SORTS;

  /** Which desktop popover is open (one at a time). */
  private readonly _openChip = signal<OpenChip>(null);
  readonly openChip = this._openChip.asReadonly();

  /** Mobile bottom-sheet open state. */
  private readonly _sheetOpen = signal(false);
  readonly sheetOpen = this._sheetOpen.asReadonly();

  sizeValues() {
    return this.facets()?.size.values ?? [];
  }
  colorValues() {
    return this.facets()?.color.values ?? [];
  }
  priceValues() {
    // Facet price-band bounds arrive from the API as decimal STRINGS
    // ("50.00"), but FacetValue types them as number. Coerce to numbers so
    // the band radios match the active filter (isPriceBandActive compares
    // with ===) and setPriceBand emits numeric min/max — catalog.service's
    // toQuery only forwards a bound when `typeof === 'number'`, so without
    // this the price filter silently does nothing.
    return (this.facets()?.price.values ?? []).map((v) => ({
      ...v,
      min: v.min == null ? undefined : Number(v.min),
      max: v.max == null ? undefined : Number(v.max),
    }));
  }

  hasPrice(): boolean {
    const f = this.filters();
    return f.minPrice != null || f.maxPrice != null;
  }

  /** Number of *facet* selections active (size + colour + price). Sort is
   *  excluded — it always has a value, so it isn't a "filter" to clear. */
  activeCount(): number {
    const f = this.filters();
    return (f.sizes?.length ?? 0) + (f.colors?.length ?? 0) + (this.hasPrice() ? 1 : 0);
  }

  activePriceLabel(): string {
    const f = this.filters();
    if (!this.hasPrice()) return '';
    return this.priceBandLabel(f.minPrice ?? undefined, f.maxPrice ?? undefined);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this._sheetOpen()) this.closeSheet();
    else if (this._openChip() !== null) this.closeChips();
  }

  // ── Desktop popover control ──────────────────────────────────────────
  toggleChip(chip: Exclude<OpenChip, null>): void {
    this._openChip.set(this._openChip() === chip ? null : chip);
  }
  closeChips(): void {
    this._openChip.set(null);
  }

  // ── Mobile sheet control ─────────────────────────────────────────────
  openSheet(): void {
    this._sheetOpen.set(true);
  }
  closeSheet(): void {
    this._sheetOpen.set(false);
  }

  // ── Labels ───────────────────────────────────────────────────────────
  sortLabel(s: CatalogSort): string {
    return SORT_LABELS[s];
  }

  swatch(color: string): string {
    return SWATCH[color.toLowerCase()] ?? color;
  }

  priceBandLabel(min?: number, max?: number): string {
    if (min == null && max == null) return this.i18n.instant('categories.price.any');
    if (max == null) return this.i18n.instant('categories.price.min', { min });
    if (min == null || min === 0) return this.i18n.instant('categories.price.under', { max });
    return this.i18n.instant('categories.price.range', { min, max });
  }

  // ── Selection state ──────────────────────────────────────────────────
  isChecked(field: 'sizes' | 'colors', value: string): boolean {
    return (this.filters()[field] ?? []).includes(value);
  }

  isPriceBandActive(min?: number, max?: number): boolean {
    const f = this.filters();
    return f.minPrice === (min ?? null) && f.maxPrice === (max ?? null);
  }

  // ── Mutations (emit a new filter set; parent owns the truth) ─────────
  setSort(sort: CatalogSort): void {
    this.filterChange.emit({ ...this.filters(), sort });
    this.closeChips();
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
    this.filterChange.emit({ category: this.filters().category, sort: 'newest' });
    this.closeChips();
  }
}
