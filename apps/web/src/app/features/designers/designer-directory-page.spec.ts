import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { DesignerDirectoryPageComponent } from './designer-directory-page';
import { DesignerService } from '../catalog/designer.service';
import { provideI18n } from '../../core/i18n';
import type { Designer } from '../catalog/designer.model';
import type { FeaturedVendor } from '../catalog/designer-card';

function makeDesigner(o: Partial<Designer> = {}): Designer {
  return {
    id: 1, slug: 'acme', name: 'Acme Couture', description: null,
    logo_url: null, cover_image_url: 'https://img/cover.png',
    is_verified: false, ...o,
  };
}

function makeFeatured(o: Partial<FeaturedVendor> = {}): FeaturedVendor {
  return {
    slug: 'acme', name: 'Acme Couture', description: null,
    rating: 4.5, rating_count: 12, products: [], ...o,
  };
}

class StubDesignerService {
  private _dir = signal<Designer[]>([]);
  private _loading = signal(false);
  private _hasMore = signal(false);
  directory = this._dir.asReadonly();
  isLoadingList = this._loading.asReadonly();
  hasMore = this._hasMore.asReadonly();

  resetCalls = 0;
  loadMoreCalls = 0;
  getFeaturedCalls = 0;
  featuredResult: FeaturedVendor[] = [];
  directoryResult: Designer[] = [];
  loadMoreThrows = false;
  featuredThrows = false;

  reset(): void { this.resetCalls++; this._dir.set([]); }
  async loadMore(): Promise<Designer[]> {
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
  directory?: Designer[];
  hasMore?: boolean;
  loadMoreThrows?: boolean;
  featuredThrows?: boolean;
} = {}): {
  fixture: ComponentFixture<DesignerDirectoryPageComponent>;
  service: StubDesignerService;
} {
  const service = new StubDesignerService();
  service.featuredResult = opts.featured ?? [];
  service.directoryResult = opts.directory ?? [];
  if (opts.loadMoreThrows === true) service.loadMoreThrows = true;
  if (opts.featuredThrows === true) service.featuredThrows = true;
  if (opts.hasMore === true) service.setHasMore(true);

  TestBed.configureTestingModule({
    imports: [DesignerDirectoryPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: DesignerService, useValue: service },
    ],
  });
  const fixture = TestBed.createComponent(DesignerDirectoryPageComponent);
  fixture.detectChanges();
  return { fixture, service };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

describe('DesignerDirectoryPageComponent', () => {
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
      const { service } = setup({ directory: [makeDesigner()] });
      await flush();
      expect(service.resetCalls).toBe(1);
      expect(service.loadMoreCalls).toBe(1);
      expect(service.getFeaturedCalls).toBe(1);
    });
  });

  describe('directory grid', () => {
    it('renders one tile per designer', async () => {
      const { fixture } = setup({
        directory: [makeDesigner({ id: 1, slug: 'a' }), makeDesigner({ id: 2, slug: 'b' })],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelectorAll('[data-testid="designer-grid-item"]')).toHaveLength(2);
    });

    it('tile links to /designer/:slug', async () => {
      const { fixture } = setup({ directory: [makeDesigner({ slug: 'acme-couture' })] });
      await flush();
      fixture.detectChanges();
      const tile = fixture.nativeElement.querySelector('.designer-tile') as HTMLAnchorElement;
      expect(tile.getAttribute('href')).toBe('/designer/acme-couture');
    });

    it('shows the verified badge only for verified designers', async () => {
      const { fixture } = setup({
        directory: [makeDesigner({ id: 1, slug: 'v', is_verified: true }),
                    makeDesigner({ id: 2, slug: 'n', is_verified: false })],
      });
      await flush();
      fixture.detectChanges();
      const badges = fixture.nativeElement.querySelectorAll('.designer-tile__verified');
      expect(badges).toHaveLength(1);
    });

    it('shows the empty state when no designers', async () => {
      const { fixture } = setup({ directory: [] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-empty"]')).not.toBeNull();
    });

    it('shows the loading state while the first load is in flight', async () => {
      const { fixture, service } = setup({ directory: [] });
      service.setLoading(true);
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-loading"]')).not.toBeNull();
    });
  });

  describe('spotlight', () => {
    it('renders the spotlight when featured designers exist', async () => {
      const { fixture } = setup({
        featured: [makeFeatured({ slug: 'a' })],
        directory: [makeDesigner()],
      });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-spotlight"]')).not.toBeNull();
    });

    it('hides the spotlight when no featured designers', async () => {
      const { fixture } = setup({ featured: [], directory: [makeDesigner()] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-spotlight"]')).toBeNull();
    });

    it('hides the spotlight (and does not crash) when featured fetch fails', async () => {
      const { fixture } = setup({ featuredThrows: true, directory: [makeDesigner()] });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-spotlight"]')).toBeNull();
      /* Directory still renders. */
      expect(fixture.nativeElement.querySelector('[data-testid="designer-grid"]')).not.toBeNull();
    });
  });

  describe('load more', () => {
    it('hides the button when hasMore is false', async () => {
      const { fixture } = setup({ directory: [makeDesigner()], hasMore: false });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="designer-load-more"]')).toBeNull();
    });

    it('shows the button when hasMore is true and calls loadMore on click', async () => {
      const { fixture, service } = setup({ directory: [makeDesigner()], hasMore: true });
      await flush();
      fixture.detectChanges();
      const before = service.loadMoreCalls;
      const btn = fixture.nativeElement.querySelector('[data-testid="designer-load-more"]') as HTMLButtonElement;
      expect(btn).not.toBeNull();
      btn.click();
      await flush();
      expect(service.loadMoreCalls).toBe(before + 1);
    });
  });
});
