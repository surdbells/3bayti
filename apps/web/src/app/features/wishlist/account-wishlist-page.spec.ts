import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountWishlistPageComponent } from './account-wishlist-page';
import { WishlistService, WishlistMeta } from './wishlist.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Product } from '../catalog/product.model';

function makeProduct(id: number): Product {
  return {
    id, slug: `p-${id}`, name: `Product ${id}`,
    price: { amount: 100, currency: 'AED' }, sale_price: null,
    in_stock: true, is_new: false, is_bestseller: false,
    primary_image: null, vendor: null, rating: null, review_count: 0,
  } as unknown as Product;
}

class StubWishlistService {
  private _products = signal<Product[]>([]);
  private _meta = signal<WishlistMeta | null>(null);
  private _loading = signal(false);
  products = this._products.asReadonly();
  meta = this._meta.asReadonly();
  isLoading = this._loading.asReadonly();

  loadResult: Product[] = [];
  loadMeta: WishlistMeta | null = null;
  loadThrows = false;
  loadMoreCalls = 0;

  isSaved(): boolean { return false; }

  async load(): Promise<void> {
    if (this.loadThrows) throw new Error('load failed');
    this._products.set(this.loadResult);
    this._meta.set(this.loadMeta);
  }
  async loadMore(): Promise<void> {
    this.loadMoreCalls++;
  }
}

class StubToast {
  calls: Array<{ kind: string; msg: string }> = [];
  success(m: string): string { this.calls.push({ kind: 'success', msg: m }); return ''; }
  error(m: string): string { this.calls.push({ kind: 'error', msg: m }); return ''; }
  info(m: string): string { this.calls.push({ kind: 'info', msg: m }); return ''; }
  warning(m: string): string { this.calls.push({ kind: 'warning', msg: m }); return ''; }
}

function setup(opts: {
  products?: Product[];
  meta?: WishlistMeta | null;
  loadThrows?: boolean;
} = {}): {
  fixture: ComponentFixture<AccountWishlistPageComponent>;
  wishlist: StubWishlistService;
  toast: StubToast;
} {
  const wishlist = new StubWishlistService();
  wishlist.loadResult = opts.products ?? [];
  wishlist.loadMeta = opts.meta ?? null;
  if (opts.loadThrows === true) wishlist.loadThrows = true;
  const toast = new StubToast();

  TestBed.configureTestingModule({
    imports: [AccountWishlistPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: WishlistService, useValue: wishlist },
      { provide: ToastService, useValue: toast },
    ],
  });
  const fixture = TestBed.createComponent(AccountWishlistPageComponent);
  fixture.detectChanges();
  return { fixture, wishlist, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function q(fixture: ComponentFixture<AccountWishlistPageComponent>, testid: string): HTMLElement | null {
  return fixture.nativeElement.querySelector(`[data-testid="${testid}"]`);
}

describe('AccountWishlistPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the empty state when nothing is saved', async () => {
    const { fixture } = setup({ products: [], meta: { total: 0, limit: 24, offset: 0, has_more: false } });
    await flush();
    fixture.detectChanges();
    expect(q(fixture, 'wishlist-empty')).not.toBeNull();
  });

  it('renders a product grid when products exist', async () => {
    const { fixture } = setup({
      products: [makeProduct(1), makeProduct(2)],
      meta: { total: 2, limit: 24, offset: 0, has_more: false },
    });
    await flush();
    fixture.detectChanges();
    expect(q(fixture, 'wishlist-empty')).toBeNull();
    expect(fixture.nativeElement.querySelectorAll('ui-product-card').length).toBe(2);
  });

  it('shows load-more only when has_more is true', async () => {
    const { fixture } = setup({
      products: [makeProduct(1)],
      meta: { total: 4, limit: 1, offset: 0, has_more: true },
    });
    await flush();
    fixture.detectChanges();
    expect(q(fixture, 'wishlist-load-more')).not.toBeNull();
  });

  it('hides load-more when has_more is false', async () => {
    const { fixture } = setup({
      products: [makeProduct(1)],
      meta: { total: 1, limit: 24, offset: 0, has_more: false },
    });
    await flush();
    fixture.detectChanges();
    expect(q(fixture, 'wishlist-load-more')).toBeNull();
  });

  it('calls loadMore when the button is clicked', async () => {
    const { fixture, wishlist } = setup({
      products: [makeProduct(1)],
      meta: { total: 4, limit: 1, offset: 0, has_more: true },
    });
    await flush();
    fixture.detectChanges();
    (q(fixture, 'wishlist-load-more') as HTMLButtonElement).click();
    await flush();
    expect(wishlist.loadMoreCalls).toBe(1);
  });

  it('toasts on load failure', async () => {
    const { toast } = setup({ loadThrows: true });
    await flush();
    expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
  });
});
