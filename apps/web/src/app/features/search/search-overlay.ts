import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  viewChild,
  ElementRef,
  HostListener,
  OnDestroy,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslateModule } from '@ngx-translate/core';
import { SearchService } from './search.service';
import { formatMoney } from '../catalog/product.model';
import type { Product } from '../catalog/product.model';
import type { DirectoryStore } from '../catalog/store.model';

/** Debounce before a keystroke triggers a search (ms). */
const SEARCH_DEBOUNCE_MS = 250;

/**
 * Persistent header search bar, a typeahead over products + stores that lives
 * permanently under the primary nav (not revealed by a trigger click).
 *
 * Behaviour:
 *   - the input is always visible; focusing it (or typing) opens a results
 *     panel that drops directly beneath the bar
 *   - debounce keystrokes (250ms) then call SearchService.search(q)
 *   - ignore stale responses (a request sequence guards out-of-order
 *     resolutions so the freshest query always wins)
 *   - grouped, tabbed results: Products (image + price) and Stores (logo +
 *     rating), each row a direct link that closes the panel on click
 *   - idle hint / loading / empty states
 *   - the panel closes on Escape, an outside click, or a result click; the
 *     bar itself stays put
 *   - a11y: combobox input + listbox results; RTL via logical CSS
 */
@Component({
  selector: 'app-search-overlay',
  standalone: true,
  imports: [RouterLink, TranslateModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="search-bar" [class.search-bar--active]="panelOpen()" data-testid="search-bar">
      <div class="search-bar__field">
        <span class="search-bar__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none"
               stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="21" y1="21" x2="16.5" y2="16.5"></line>
          </svg>
        </span>
        <input
          #searchInput
          type="search"
          class="search-bar__input"
          role="combobox"
          aria-autocomplete="list"
          aria-controls="search-results"
          [attr.aria-expanded]="panelOpen()"
          [attr.placeholder]="'search.placeholder' | translate"
          [value]="query()"
          (input)="onInput($event)"
          (focus)="onFocus()"
          autocomplete="off"
          spellcheck="false"
          data-testid="search-input"
        />
        @if (query()) {
          <button
            type="button"
            class="search-bar__clear"
            (click)="clear()"
            [attr.aria-label]="'common.close' | translate"
            data-testid="search-clear"
          >
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round">
              <line x1="6" y1="6" x2="18" y2="18"></line>
              <line x1="18" y1="6" x2="6" y2="18"></line>
            </svg>
          </button>
        }
      </div>

      @if (panelOpen()) {
        <div
          id="search-results"
          class="search-bar__panel"
          role="listbox"
          [attr.aria-label]="'search.resultsLabel' | translate"
          data-testid="search-overlay"
        >
          @if (loading()) {
            <p class="search-bar__status" data-testid="search-loading">
              {{ 'search.loading' | translate }}
            </p>
          } @else if (searched() && !hasResults()) {
            <p class="search-bar__status" data-testid="search-empty">
              {{ 'search.noResults' | translate: { query: query() } }}
            </p>
          } @else if (hasResults()) {
            <div
              class="search-bar__tabs"
              role="tablist"
              [attr.aria-label]="'search.resultsLabel' | translate"
              (keydown)="onTabKeydown($event)"
            >
              <button
                type="button"
                role="tab"
                id="search-tab-products"
                class="search-bar__tab"
                [class.search-bar__tab--active]="activeTab() === 'products'"
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
                <span class="search-bar__tab-count" aria-hidden="true">{{ products().length }}</span>
              </button>
              <button
                type="button"
                role="tab"
                id="search-tab-stores"
                class="search-bar__tab"
                [class.search-bar__tab--active]="activeTab() === 'stores'"
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
                <span class="search-bar__tab-count" aria-hidden="true">{{ stores().length }}</span>
              </button>
            </div>

            @if (activeTab() === 'products') {
              <div
                role="tabpanel"
                id="search-panel-products"
                aria-labelledby="search-tab-products"
                class="search-bar__group"
                data-testid="search-products"
              >
                @if (products().length > 0) {
                  <div class="search-bar__cards">
                    @for (product of products(); track product.slug) {
                      <a
                        class="search-card"
                        role="option"
                        [routerLink]="['/product', product.slug]"
                        (click)="closePanel()"
                        data-testid="search-product-row"
                      >
                        <span class="search-card__media">
                          @if (product.primary_image?.url) {
                            <img [src]="product.primary_image!.url" [attr.alt]="product.name" loading="lazy" />
                          } @else {
                            <svg class="search-card__ph" viewBox="0 0 24 24" width="30" height="30" fill="none"
                                 stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                              <rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="M4 18l5-4 4 3 3-2 4 3" />
                            </svg>
                          }
                        </span>
                        <span class="search-card__name">{{ product.name }}</span>
                        <span class="search-card__price">{{ priceLabel(product) }}</span>
                      </a>
                    }
                  </div>
                } @else {
                  <p class="search-bar__status" data-testid="search-tab-empty">
                    {{ 'search.noResults' | translate: { query: query() } }}
                  </p>
                }
              </div>
            } @else {
              <div
                role="tabpanel"
                id="search-panel-stores"
                aria-labelledby="search-tab-stores"
                class="search-bar__group"
                data-testid="search-stores"
              >
                @if (stores().length > 0) {
                  <div class="search-bar__cards search-bar__cards--stores">
                    @for (store of stores(); track store.slug) {
                      <a
                        class="search-store"
                        role="option"
                        [routerLink]="['/stores', store.slug]"
                        (click)="closePanel()"
                        data-testid="search-store-row"
                      >
                        <span class="search-store__logo">
                          @if (store.logo_url) {
                            <img [src]="store.logo_url" alt="" loading="lazy" />
                          } @else {
                            <span class="search-store__initial" aria-hidden="true">{{ store.name.charAt(0) }}</span>
                          }
                        </span>
                        <span class="search-store__text">
                          <span class="search-store__name">{{ store.name }}</span>
                          @if (store.rating_count > 0) {
                            <span class="search-store__rating">&#9733; {{ store.rating }} ({{ store.rating_count }})</span>
                          }
                        </span>
                      </a>
                    }
                  </div>
                } @else {
                  <p class="search-bar__status" data-testid="search-tab-empty">
                    {{ 'search.noResults' | translate: { query: query() } }}
                  </p>
                }
              </div>
            }
          } @else {
            <p class="search-bar__status search-bar__status--hint" data-testid="search-hint">
              {{ 'search.hint' | translate }}
            </p>
          }
        </div>
      }
    </div>
  `,
  styleUrl: './search-overlay.scss',
})
export class SearchOverlayComponent implements OnDestroy {
  private readonly searchService = inject(SearchService);
  private readonly host = inject(ElementRef<HTMLElement>);

  private readonly searchInput = viewChild<ElementRef<HTMLInputElement>>('searchInput');

  protected readonly query = signal('');
  protected readonly loading = signal(false);
  protected readonly searched = signal(false);
  protected readonly products = signal<Product[]>([]);
  protected readonly stores = signal<DirectoryStore[]>([]);

  /** Whether the results panel (dropdown) is open. The bar itself is always shown. */
  protected readonly panelOpen = signal(false);

  /** Active results tab. Products lead by default (web-uplift #6). */
  protected readonly activeTab = signal<'products' | 'stores'>('products');

  protected readonly hasResults = computed(
    () => this.products().length > 0 || this.stores().length > 0,
  );

  private debounceHandle: ReturnType<typeof setTimeout> | null = null;
  private requestSeq = 0;

  protected onFocus(): void {
    this.panelOpen.set(true);
  }

  protected onInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.query.set(value);
    this.panelOpen.set(true);
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

  /** Close the results panel (the bar stays). */
  protected closePanel(blurInput = false): void {
    if (!this.panelOpen()) return;
    this.panelOpen.set(false);
    if (blurInput) this.searchInput()?.nativeElement?.blur();
  }

  /** Clear the query but keep the bar focused + the panel open (shows the hint). */
  protected clear(): void {
    this.clearDebounce();
    this.requestSeq++;
    this.query.set('');
    this.loading.set(false);
    this.searched.set(false);
    this.products.set([]);
    this.stores.set([]);
    this.activeTab.set('products');
    this.panelOpen.set(true);
    setTimeout(() => this.searchInput()?.nativeElement?.focus(), 0);
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    this.closePanel(true);
  }

  /** Close the panel when the user clicks anywhere outside the search bar. */
  @HostListener('document:pointerdown', ['$event'])
  protected onDocumentPointerDown(event: Event): void {
    if (!this.panelOpen()) return;
    const target = event.target as Node | null;
    if (target && !this.host.nativeElement.contains(target)) {
      this.closePanel();
    }
  }

  ngOnDestroy(): void {
    this.clearDebounce();
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

  private clearDebounce(): void {
    if (this.debounceHandle !== null) {
      clearTimeout(this.debounceHandle);
      this.debounceHandle = null;
    }
  }
}
