import {
  Component,
  ChangeDetectionStrategy,
  Input,
  Output,
  EventEmitter,
  inject,
  signal,
  computed,
  viewChild,
  ElementRef,
  HostListener,
  OnDestroy,
} from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { SearchService } from './search.service';
import { formatMoney } from '../catalog/product.model';
import type { Product } from '../catalog/product.model';
import type { DirectoryStore } from '../catalog/store.model';

/** Debounce before a keystroke triggers a search (ms). */
const SEARCH_DEBOUNCE_MS = 250;

/**
 * Global search overlay (Stores H2.C), a header-triggered typeahead over
 * products + stores.
 *
 * Controlled component: the header owns the open/close state via [open] +
 * (closed). Behaviour:
 *   - autofocus the input on open; restore focus to the trigger on close
 *   - debounce keystrokes (250ms) then call SearchService.search(q)
 *   - ignore stale responses (a request sequence guards out-of-order
 *     resolutions so the freshest query always wins)
 *   - grouped results: Stores (logo + rating) and Products (image + price),
 *     each row a direct link that closes the overlay on click
 *   - idle / loading / empty states; Esc + backdrop close
 *   - a11y: role=dialog + aria-modal, combobox input, listbox results;
 *     RTL via logical CSS in the stylesheet
 */
@Component({
  selector: 'app-search-overlay',
  standalone: true,
  imports: [RouterLink, TranslateModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (isOpen()) {
      <div class="search-overlay" data-testid="search-overlay">
        <div
          class="search-overlay__backdrop"
          (click)="requestClose()"
          data-testid="search-backdrop"
          aria-hidden="true"
        ></div>

        <div
          class="search-overlay__panel"
          role="dialog"
          aria-modal="true"
          [attr.aria-label]="'search.dialogLabel' | translate"
        >
          <div class="search-overlay__bar">
            <span class="search-overlay__bar-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                   stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                <circle cx="11" cy="11" r="7"></circle>
                <line x1="21" y1="21" x2="16.5" y2="16.5"></line>
              </svg>
            </span>
            <input
              #searchInput
              type="search"
              class="search-overlay__input"
              role="combobox"
              aria-autocomplete="list"
              aria-controls="search-overlay-results"
              [attr.aria-expanded]="hasResults()"
              [attr.placeholder]="'search.placeholder' | translate"
              [value]="query()"
              (input)="onInput($event)"
              autocomplete="off"
              spellcheck="false"
              data-testid="search-input"
            />
            <button
              type="button"
              class="search-overlay__close"
              (click)="requestClose()"
              [attr.aria-label]="'common.close' | translate"
              data-testid="search-close"
            >
              <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
                   stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                <line x1="6" y1="6" x2="18" y2="18"></line>
                <line x1="18" y1="6" x2="6" y2="18"></line>
              </svg>
            </button>
          </div>

          <div
            id="search-overlay-results"
            class="search-overlay__results"
            role="listbox"
            [attr.aria-label]="'search.resultsLabel' | translate"
          >
            @if (loading()) {
              <p class="search-overlay__status" data-testid="search-loading">
                {{ 'search.loading' | translate }}
              </p>
            } @else if (searched() && !hasResults()) {
              <p class="search-overlay__status" data-testid="search-empty">
                {{ 'search.noResults' | translate: { query: query() } }}
              </p>
            } @else if (hasResults()) {
              <div
                class="search-overlay__tabs"
                role="tablist"
                [attr.aria-label]="'search.resultsLabel' | translate"
                (keydown)="onTabKeydown($event)"
              >
                <button
                  type="button"
                  role="tab"
                  id="search-tab-products"
                  class="search-overlay__tab"
                  [class.search-overlay__tab--active]="activeTab() === 'products'"
                  [attr.aria-selected]="activeTab() === 'products'"
                  [attr.tabindex]="activeTab() === 'products' ? 0 : -1"
                  aria-controls="search-panel-products"
                  (click)="selectTab('products')"
                  data-testid="search-tab-products"
                >
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
                       stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 7l9-4 9 4-9 4-9-4z"></path><path d="M3 7v10l9 4 9-4V7"></path>
                  </svg>
                  {{ 'search.products' | translate }}
                  <span class="search-overlay__tab-count" aria-hidden="true">{{ products().length }}</span>
                </button>
                <button
                  type="button"
                  role="tab"
                  id="search-tab-stores"
                  class="search-overlay__tab"
                  [class.search-overlay__tab--active]="activeTab() === 'stores'"
                  [attr.aria-selected]="activeTab() === 'stores'"
                  [attr.tabindex]="activeTab() === 'stores' ? 0 : -1"
                  aria-controls="search-panel-stores"
                  (click)="selectTab('stores')"
                  data-testid="search-tab-stores"
                >
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
                       stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 9l1.5-5h15L21 9"></path><path d="M4 9h16v10H4z"></path><path d="M9 19v-6h6v6"></path>
                  </svg>
                  {{ 'search.stores' | translate }}
                  <span class="search-overlay__tab-count" aria-hidden="true">{{ stores().length }}</span>
                </button>
              </div>

              @if (activeTab() === 'products') {
                <div
                  role="tabpanel"
                  id="search-panel-products"
                  aria-labelledby="search-tab-products"
                  class="search-overlay__group"
                  data-testid="search-products"
                >
                  @if (products().length > 0) {
                    @for (product of products(); track product.slug) {
                      <a
                        class="search-overlay__row"
                        role="option"
                        [routerLink]="['/product', product.slug]"
                        (click)="requestClose()"
                        data-testid="search-product-row"
                      >
                        <span class="search-overlay__thumb">
                          @if (product.primary_image?.url) {
                            <img [src]="product.primary_image!.url" [attr.alt]="product.name" loading="lazy" />
                          }
                        </span>
                        <span class="search-overlay__row-text">
                          <span class="search-overlay__row-name">{{ product.name }}</span>
                          <span class="search-overlay__row-meta">{{ priceLabel(product) }}</span>
                        </span>
                      </a>
                    }
                  } @else {
                    <p class="search-overlay__status" data-testid="search-tab-empty">
                      {{ 'search.noResults' | translate: { query: query() } }}
                    </p>
                  }
                </div>
              } @else {
                <div
                  role="tabpanel"
                  id="search-panel-stores"
                  aria-labelledby="search-tab-stores"
                  class="search-overlay__group"
                  data-testid="search-stores"
                >
                  @if (stores().length > 0) {
                    @for (store of stores(); track store.slug) {
                      <a
                        class="search-overlay__row"
                        role="option"
                        [routerLink]="['/stores', store.slug]"
                        (click)="requestClose()"
                        data-testid="search-store-row"
                      >
                        <span class="search-overlay__thumb">
                          @if (store.logo_url) {
                            <img [src]="store.logo_url" alt="" loading="lazy" />
                          }
                        </span>
                        <span class="search-overlay__row-text">
                          <span class="search-overlay__row-name">{{ store.name }}</span>
                          @if (store.rating_count > 0) {
                            <span class="search-overlay__row-meta">
                              &#9733; {{ store.rating }} ({{ store.rating_count }})
                            </span>
                          }
                        </span>
                      </a>
                    }
                  } @else {
                    <p class="search-overlay__status" data-testid="search-tab-empty">
                      {{ 'search.noResults' | translate: { query: query() } }}
                    </p>
                  }
                </div>
              }
            } @else {
              <p class="search-overlay__status search-overlay__status--hint" data-testid="search-hint">
                {{ 'search.hint' | translate }}
              </p>
            }
          </div>
        </div>
      </div>
    }
  `,
  styleUrl: './search-overlay.scss',
})
export class SearchOverlayComponent implements OnDestroy {
  private readonly searchService = inject(SearchService);
  private readonly doc = inject(DOCUMENT);

  /** Controlled open state (driven by the header trigger). */
  @Input()
  set open(value: boolean) {
    const next = !!value;
    if (next === this._open()) return;
    this._open.set(next);
    next ? this.onOpened() : this.onClosed();
  }
  get open(): boolean {
    return this._open();
  }
  private readonly _open = signal(false);

  /** Emitted on Esc, backdrop click, close button, or a result click. */
  @Output() readonly closed = new EventEmitter<void>();

  private readonly searchInput = viewChild<ElementRef<HTMLInputElement>>('searchInput');

  protected readonly isOpen = this._open.asReadonly();
  protected readonly query = signal('');
  protected readonly loading = signal(false);
  protected readonly searched = signal(false);
  protected readonly products = signal<Product[]>([]);
  protected readonly stores = signal<DirectoryStore[]>([]);

  /** Active results tab. Products lead by default (web-uplift #6). */
  protected readonly activeTab = signal<'products' | 'stores'>('products');

  protected readonly hasResults = computed(
    () => this.products().length > 0 || this.stores().length > 0,
  );

  private debounceHandle: ReturnType<typeof setTimeout> | null = null;
  private requestSeq = 0;
  private previouslyFocused: HTMLElement | null = null;

  protected onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.query.set(value);
    this.clearDebounce();

    if (value.trim() === '') {
      this.requestSeq++; // drop any in-flight response
      this.loading.set(false);
      this.searched.set(false);
      this.products.set([]);
      this.stores.set([]);
      this.activeTab.set('products');
      return;
    }

    this.loading.set(true);
    this.debounceHandle = setTimeout(() => void this.runSearch(value), SEARCH_DEBOUNCE_MS);
  }

  protected priceLabel(product: Product): string {
    return formatMoney(product.sale_price ?? product.price);
  }

  protected selectTab(tab: 'products' | 'stores'): void {
    this.activeTab.set(tab);
  }

  /** Left/Right arrows move between the two result tabs (WAI-ARIA tabs). */
  protected onTabKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
      event.preventDefault();
      this.activeTab.set(this.activeTab() === 'products' ? 'stores' : 'products');
    }
  }

  protected requestClose(): void {
    this.closed.emit();
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this._open()) this.closed.emit();
  }

  ngOnDestroy(): void {
    this.clearDebounce();
    this.doc.body.style.overflow = '';
  }

  private async runSearch(value: string): Promise<void> {
    const seq = ++this.requestSeq;
    try {
      const res = await this.searchService.search(value);
      if (seq !== this.requestSeq) return; // a newer query superseded this one
      this.products.set(res.products);
      this.stores.set(res.stores);
      // Products lead, but if there are only stores, surface them.
      this.activeTab.set(res.products.length === 0 && res.stores.length > 0 ? 'stores' : 'products');
    } catch {
      if (seq !== this.requestSeq) return;
      this.products.set([]);
      this.stores.set([]);
    } finally {
      if (seq === this.requestSeq) {
        this.loading.set(false);
        this.searched.set(true);
      }
    }
  }

  private onOpened(): void {
    this.previouslyFocused = this.doc.activeElement as HTMLElement | null;
    this.doc.body.style.overflow = 'hidden';
    setTimeout(() => this.searchInput()?.nativeElement?.focus(), 0);
  }

  private onClosed(): void {
    this.doc.body.style.overflow = '';
    this.resetState();
    const prev = this.previouslyFocused;
    this.previouslyFocused = null;
    setTimeout(() => prev?.focus?.(), 0);
  }

  private resetState(): void {
    this.clearDebounce();
    this.requestSeq++;
    this.query.set('');
    this.loading.set(false);
    this.searched.set(false);
    this.products.set([]);
    this.stores.set([]);
    this.activeTab.set('products');
  }

  private clearDebounce(): void {
    if (this.debounceHandle !== null) {
      clearTimeout(this.debounceHandle);
      this.debounceHandle = null;
    }
  }
}
