import { provideZoneChangeDetection } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter, Router } from '@angular/router';
import { of, throwError } from 'rxjs';
import { AccountPage, HIDDEN_FOR_YOU_RAILS, HomeCollection } from './account.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';

function collection(overrides: Partial<HomeCollection> = {}): HomeCollection {
  return {
    id: 1,
    slug: 'eid-edit',
    name: 'Eid Edit',
    description: null,
    image_url: 'https://cdn.example/eid.jpg',
    product_count: 4,
    display_order: 1,
    ...overrides,
  };
}

/** Benign adapter: GET /collections is configurable, every other read is empty. */
class AdapterStub {
  collectionsResponse: any = { response_code: 200, status: 'success', data: [] };
  collectionsError = false;
  forYouResponse: any = { response_code: 200, status: 'success', data: { rails: [] } };
  get_v3(routeKey: string) {
    if (routeKey === 'GET /collections') {
      return this.collectionsError ? throwError(() => new Error('net')) : of(this.collectionsResponse);
    }
    if (routeKey === 'GET /me/ai/for-you') {
      return of(this.forYouResponse);
    }
    return of({ response_code: 200, status: 'success', data: [], message: {} });
  }
  post_v3() { return of({ response_code: 200, status: 'success', data: {} }); }
}

describe('AccountPage', () => {
  let component: AccountPage;
  let fixture: ComponentFixture<AccountPage>;
  let adapter: AdapterStub;
  let router: Router;

  beforeEach(() => {
    adapter = new AdapterStub();
    TestBed.configureTestingModule({
      imports: [AccountPage],
      providers: [
        // The app bootstraps zone-based (main.ts provideZoneChangeDetection);
        // TestBed defaults to zoneless, where plain field writes need an
        // explicit markForCheck. Match the app so detectChanges() refreshes.
        provideZoneChangeDetection(),
        provideRouter([]),
        provideHttpClient(),
        { provide: MobileNetworkAdapter, useValue: adapter },
      ],
    });
    fixture = TestBed.createComponent(AccountPage);
    component = fixture.componentInstance;
    router = TestBed.inject(Router);
    spyOn(router, 'navigate');
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  describe('For You rails', () => {
    function railProduct(id: number) {
      return { id, name: 'P' + id, price: { amount: 100 }, vendor: { id: 1, name: 'Store', slug: 'store' } };
    }

    beforeEach(() => {
      component.single_user.token = 'token';
    });

    it('hides the "Popular right now" rail but keeps the other rails', () => {
      expect(HIDDEN_FOR_YOU_RAILS.has('popular')).toBeTrue();
      adapter.forYouResponse = {
        response_code: 200,
        status: 'success',
        data: {
          rails: [
            { key: 'your_style', products: [railProduct(1)] },
            { key: 'popular', products: [railProduct(2), railProduct(3)] },
            { key: 'new_for_you', products: [railProduct(4)] },
          ],
        },
      };
      component.get_for_you();
      expect(component.forYouRails.map((r) => r.key)).toEqual(['your_style', 'new_for_you']);
      expect(component.forYouLoading).toBeFalse();
    });

    it('renders no rail at all when only the popular cold-start rail came back', () => {
      adapter.forYouResponse = {
        response_code: 200,
        status: 'success',
        data: { rails: [{ key: 'popular', products: [railProduct(2)] }] },
      };
      component.get_for_you();
      fixture.detectChanges();
      expect(component.forYouRails).toEqual([]);
      expect(fixture.nativeElement.querySelector('.fy-placeholder')).toBeNull();
    });
  });

  describe('Ain concierge', () => {
    it('no longer renders the inline concierge cards at the top of the page', () => {
      const el: HTMLElement = fixture.nativeElement;
      expect(el.querySelector('.ain-concierge')).toBeNull();
      expect(el.querySelector('.ain-card')).toBeNull();
    });

    it('puts the Ain FAB first in the stack (bottom slot) and opens the sheet from it', () => {
      const el: HTMLElement = fixture.nativeElement;
      const fabs = el.querySelectorAll('.m6-fab-stack .m6-fab');
      expect(fabs[0].classList).toContain('m6-fab--ain');

      (fabs[0] as HTMLElement).click();
      expect(component.ainSheetOpen).toBeTrue();
    });

    it('shows only the Ain FAB for a customer (Messages stays in the header)', () => {
      const el: HTMLElement = fixture.nativeElement;
      expect(el.querySelectorAll('.m6-fab-stack .m6-fab').length).toBe(1);
      expect(el.querySelector('.m6-msg-btn')).not.toBeNull();
      expect(el.querySelector('ion-content')?.classList).not.toContain('home-bg--fab-2');
    });

    it('adds the Store dashboard FAB above Ain for an approved vendor', () => {
      component.single_user.is_vendor = true;
      component.single_user.is_store_approved = true;
      fixture.detectChanges();
      const el: HTMLElement = fixture.nativeElement;
      const fabs = el.querySelectorAll('.m6-fab-stack .m6-fab');
      expect(fabs.length).toBe(2);
      expect(fabs[0].classList).toContain('m6-fab--ain');
      expect(fabs[1].classList).toContain('m6-fab--dashboard');
      expect(el.querySelector('ion-content')?.classList).toContain('home-bg--fab-2');
    });

    it('opens store reviews by slug, never the legacy store id', () => {
      component.open_reviews('dar-abaya', 'Dar Abaya');
      expect(router.navigate).toHaveBeenCalledWith(['/', 'store_reviews'], { queryParams: { slug: 'dar-abaya', name: 'Dar Abaya' } });
      (router.navigate as jasmine.Spy).calls.reset();
      component.open_reviews(undefined, 'No slug');
      expect(router.navigate).not.toHaveBeenCalled();
    });
  });

  describe('collection chips', () => {
    it('keeps only collections with a slug and visible products', () => {
      adapter.collectionsResponse = {
        response_code: 200,
        status: 'success',
        data: [
          collection({ id: 1, slug: 'eid-edit', product_count: 4 }),
          collection({ id: 2, slug: 'empty', product_count: 0 }),
          collection({ id: 3, slug: '', product_count: 9 }),
          collection({ id: 4, slug: 'ramadan', name: 'Ramadan', product_count: 2 }),
        ],
      };
      component.get_collections();
      expect(component.collections.map((c) => c.slug)).toEqual(['eid-edit', 'ramadan']);
    });

    it('keeps the current list when the read fails', () => {
      component.collections = [collection()];
      adapter.collectionsError = true;
      component.get_collections();
      expect(component.collections.length).toBe(1);

      adapter.collectionsError = false;
      adapter.collectionsResponse = { response_code: 500, status: 'error', data: null };
      component.get_collections();
      expect(component.collections.length).toBe(1);
    });

    it('opens the collection PLP by slug', () => {
      component.open_collection('eid-edit', 'Eid Edit');
      expect(router.navigate).toHaveBeenCalledWith(
        ['/', 'collection'],
        { queryParams: { slug: 'eid-edit', name: 'Eid Edit' } },
      );
    });

    it('ignores a chip with no slug', () => {
      (router.navigate as jasmine.Spy).calls.reset();
      component.open_collection('', 'Nameless');
      expect(router.navigate).not.toHaveBeenCalled();
    });

    it('renders a chip row under the categories only when there are collections', () => {
      const el: HTMLElement = fixture.nativeElement;
      expect(el.querySelector('.categories--collections')).toBeNull();

      component.collections = [
        collection({ id: 1, slug: 'eid-edit', name: 'Eid Edit' }),
        collection({ id: 2, slug: 'no-image', name: 'No Image', image_url: null }),
      ];
      fixture.detectChanges();

      const chips = el.querySelectorAll('.categories--collections .m6-chip--collection');
      expect(chips.length).toBe(2);
      expect(chips[0].textContent).toContain('Eid Edit');
      expect(chips[0].querySelector('.m6-chip-thumb img')).not.toBeNull();
      // No image → icon fallback instead of a broken <img>.
      expect(chips[1].querySelector('.m6-chip-thumb img')).toBeNull();
      expect(chips[1].querySelector('.m6-chip-thumb ax-icon')).not.toBeNull();
    });
  });
});
