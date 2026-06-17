import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { StoreDirectoryPageComponent } from './store-directory-page';
import { StoreService } from '../catalog/store.service';
import { provideI18n } from '../../core/i18n';
import type { DirectoryStore } from '../catalog/store.model';
import type { FeaturedVendor } from '../catalog/store-card';

function makeStore(o: Partial<DirectoryStore> = {}): DirectoryStore {
  return {
    id: 1, slug: 'acme', name: 'Acme Couture', description: null,
    logo_url: null, cover_image_url: 'https://img/cover.png',
    is_verified: false, rating: null, rating_count: 0, products: [], ...o,
  };
}

function makeFeatured(o: Partial<FeaturedVendor> = {}): FeaturedVendor {
  return {
    slug: 'acme', name: 'Acme Couture', description: null,
    rating: 4.5, rating_count: 12, products: [], ...o,
  };
}

class StubStoreService {
  private _dir = signal<DirectoryStore[]>([]);
  private _loading = signal(false);
  private _hasMore = signal(false);
  directory = this._dir.asReadonly();
  isLoadingList = this._loading.asReadonly();
  hasMore = this._hasMore.asReadonly();

  resetCalls = 0;
  loadMoreCalls = 0;
  getFeaturedCalls = 0;
  featuredResult: FeaturedVendor[] = [];
  directoryResult: DirectoryStore[] = [];
  loadMoreThrows = false;
  featuredThrows = false;

  reset(): void { this.resetCalls++; this._dir.set([]); }
  async loadMore(): Promise<DirectoryStore[]> {
    this.loadMoreCalls++;
    if (this.loadMoreThrows) throw new Error('list failed');
    this._dir.set(this.directoryResult);
    return this.directoryResult;
  }
  async getFeatured(): Promise<FeaturedVendor[]> {
    this.getFeaturedCalls++;
    if (this.featuredThrows) throw new Error('featured failed');
    return this.featuredResult;
  }
  setHasMore(b: boolean): void { this._hasMore.set(b); }
  setLoading(b: boolean): void { this._loading.set(b); }
}

function setup(opts: {
  featured?: FeaturedVendor[];
  directory?: DirectoryStore[];
  hasMore?: boolean;
  loadMoreThrows?: boolean;
  featuredThrows?: boolean;
} = {}): {
  fixture: ComponentFixture<StoreDirectoryPageComponent>;
  service: StubStoreService;
} {
  const service = new StubStoreService();
  service.featuredResult = opts.featured ?? [];
  service.directoryResult = opts.directory ?? [];
  if (opts.loadMoreThrows === true) service.loadMoreThrows = true;
  if (opts.featuredThrows === true) service.featuredThrows = true;
  if (opts.hasMore === true) service.setHasMore(true);

  TestBed.configureTestingModule({
    imports: [StoreDirectoryPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: StoreService, useValue: service },
    ],
  });
  const fixture = TestBed.createComponent(StoreDirectoryPageComponent);
  fixture.detectChanges();
  return { fixture, service };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('StoreDirectoryPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('init', () => {
    it('resets and loads the directory + fetches featured on init', async () => {
      const { service } = setup({ directory: [makeStore()] });
      await flush();
      expect(service.resetCalls).toBe(1);
      expect(service.loadMoreCalls).toBe(1);
      expect(service.getFeaturedCalls).toBe(1);
    });
  });

  describe('directory grid', () => {
    it('renders one card per store', async () => {
      const { fixture } = setup({
        directory: [makeStore({ id: 1, slug: 'a' }), makeStore({ id: 2, slug: 'b' })],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelectorAll('[data-testid="store-grid-item"]')).toHaveLength(2);
    });

    it('renders a store card linking to each store', async () => {
      const { fixture } = setup({ directory: [makeStore({ slug: 'acme-couture' })] });
      await flush();
      fixture.detectChanges();
      const link = fixture.nativeElement.querySelector('.store-card__name-link') as HTMLAnchorElement;
      expect(link).not.toBeNull();
      expect(link.getAttribute('href')).toContain('acme-couture');
    });

    it('shows the empty state when no stores', async () => {
      const { fixture } = setup({ directory: [] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-empty"]')).not.toBeNull();
    });

    it('shows the loading state while the first load is in flight', async () => {
      const { fixture, service } = setup({ directory: [] });
      service.setLoading(true);
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-loading"]')).not.toBeNull();
    });
  });

  describe('spotlight', () => {
    it('renders the spotlight when featured stores exist', async () => {
      const { fixture } = setup({
        featured: [makeFeatured({ slug: 'a' })],
        directory: [makeStore()],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-spotlight"]')).not.toBeNull();
    });

    it('hides the spotlight when no featured stores', async () => {
      const { fixture } = setup({ featured: [], directory: [makeStore()] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-spotlight"]')).toBeNull();
    });

    it('hides the spotlight (and does not crash) when featured fetch fails', async () => {
      const { fixture } = setup({ featuredThrows: true, directory: [makeStore()] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-spotlight"]')).toBeNull();
      /* Directory still renders. */
      expect(fixture.nativeElement.querySelector('[data-testid="store-grid"]')).not.toBeNull();
    });
  });

  describe('load more', () => {
    it('hides the button when hasMore is false', async () => {
      const { fixture } = setup({ directory: [makeStore()], hasMore: false });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="store-load-more"]')).toBeNull();
    });

    it('shows the button when hasMore is true and calls loadMore on click', async () => {
      const { fixture, service } = setup({ directory: [makeStore()], hasMore: true });
      await flush();
      fixture.detectChanges();
      const before = service.loadMoreCalls;
      const btn = fixture.nativeElement.querySelector('[data-testid="store-load-more"]') as HTMLButtonElement;
      expect(btn).not.toBeNull();
      btn.click();
      await flush();
      expect(service.loadMoreCalls).toBe(before + 1);
    });
  });
});
