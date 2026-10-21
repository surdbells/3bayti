import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';
import { ProductPage } from './product.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { transformProductDetailResponse } from '../../core/http/transforms/catalog-response.transforms';

/** Benign adapter: every read succeeds empty, no real network. */
class AdapterStub {
  get_v3() { return of({ response_code: 200, status: 'success', data: [] }); }
  post_v3() { return of({ response_code: 200, status: 'success', data: {} }); }
}

class ToastStub {
  errors: string[] = [];
  error(m: string) { this.errors.push(m); }
  success() {}
}

/* No fixture.detectChanges(): rendering runs ngAfterViewInit → initSwiper(),
   which re-polls forever for the (unregistered in tests) swiper element. These
   specs exercise the component logic directly. */
function setup() {
  const toast = new ToastStub();
  TestBed.configureTestingModule({
    imports: [ProductPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: MobileNetworkAdapter, useValue: new AdapterStub() },
      { provide: AxNotificationService, useValue: toast },
      {
        provide: ActivatedRoute,
        useValue: { snapshot: { queryParamMap: { get: (k: string) => (k === 'id' ? '42' : null) } } },
      },
    ],
  });
  const fixture = TestBed.createComponent(ProductPage);
  const router = TestBed.inject(Router);
  spyOn(router, 'navigate');
  return { component: fixture.componentInstance, router, toast };
}

describe('ProductPage', () => {
  it('should create', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  describe('open_store (Sold by → storefront)', () => {
    it('opens the vendor storefront by SLUG, never the legacy store id', () => {
      const { component, router } = setup();
      component.isGuest = false;
      component.single.vendor_slug = 'almas-fashion';
      component.single.store_name = 'Almas Fashion';
      component.single.store = 123; // legacy id must NOT be used

      component.open_store();

      expect(router.navigate).toHaveBeenCalledOnceWith(
        ['/', 'vendors'],
        { queryParams: { slug: 'almas-fashion', name: 'Almas Fashion' } },
      );
    });

    it('does nothing when the product has no vendor slug', () => {
      const { component, router, toast } = setup();
      component.isGuest = false;
      component.single.vendor_slug = '';
      component.single.store_name = 'Almas Fashion';
      component.single.store = 123;

      component.open_store();

      expect(router.navigate).not.toHaveBeenCalled();
      expect(toast.errors.length).toBe(0);
    });

    it('prompts a guest to sign up instead of opening the (sign-in only) storefront', () => {
      const { component, router, toast } = setup();
      component.isGuest = true;
      component.single.vendor_slug = 'almas-fashion';
      component.single.store_name = 'Almas Fashion';

      component.open_store();

      expect(router.navigate).not.toHaveBeenCalled();
      expect(toast.errors.length).toBe(1);
    });
  });

  describe('gift-card nudge ("Not sure about their size?")', () => {
    it('stays hidden until a product has loaded', () => {
      const { component } = setup();
      expect(component.showGiftCardNudge).toBeFalse();
    });

    it('shows for a loaded v3 product (the detail transform emits `product`, never `id`)', () => {
      const { component } = setup();
      const data = transformProductDetailResponse({ id: 7, name: 'Abaya', price: { amount: '100.00' } }) as any;
      expect(data.id).toBeUndefined();

      component.single = data;

      expect(component.showGiftCardNudge).toBeTrue();
    });

    it('beacons ai_gift_card_recommended (pdp/mobile) and opens the gift-card flow', () => {
      const { component, router } = setup();
      const adapter = TestBed.inject(MobileNetworkAdapter) as unknown as AdapterStub;
      const post = spyOn(adapter, 'post_v3').and.callThrough();

      component.openGiftCardNudge();

      expect(post).toHaveBeenCalledTimes(1);
      const [route, body] = post.calls.mostRecent().args as unknown as [string, Record<string, unknown>];
      expect(route).toBe('POST /ai/events');
      expect(body).toEqual(jasmine.objectContaining({
        event: 'ai_gift_card_recommended',
        context: 'pdp',
        surface: 'mobile',
      }));
      expect(router.navigate).toHaveBeenCalledOnceWith(['/', 'gift-cards']);
    });
  });
});
