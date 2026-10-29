import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, provideRouter, Router } from '@angular/router';
import { NEVER, of } from 'rxjs';
import { ProductPage } from './product.page';
import { MobileNetworkAdapter, NETWORK_UNAVAILABLE } from '../../core/http/mobile-network-adapter';
import { SESSION_PERSISTENCE, SessionStore, type SessionPersistence } from '../../core/services/session-store.service';
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

describe('ProductPage auth gates (live session)', () => {
  class MemoryPersistence implements SessionPersistence {
    constructor(public value: string | null, private unreadable = false) {}
    read(): Promise<string | null> {
      return this.unreadable ? Promise.reject(new Error('storage busy')) : Promise.resolve(this.value);
    }
    write(value: string): Promise<void> { this.value = value; return Promise.resolve(); }
    remove(): Promise<void> { this.value = null; return Promise.resolve(); }
  }

  /** post_v3 answers with whatever the spec queues (default: never). */
  class ScriptedAdapter {
    next: unknown = null;
    posts = 0;
    get_v3() { return of({ response_code: 200, status: 'success', data: [] }); }
    post_v3(route: string) {
      if (route !== 'POST /cart/items') return of({ response_code: 200, status: 'success', data: {} });
      this.posts++;
      return this.next === null ? NEVER : of(this.next);
    }
  }

  async function setupWithSession(session: Record<string, unknown> | null, unreadable = false) {
    const toast = new ToastStub();
    const adapter = new ScriptedAdapter();
    TestBed.configureTestingModule({
      imports: [ProductPage],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        { provide: MobileNetworkAdapter, useValue: adapter },
        { provide: AxNotificationService, useValue: toast },
        {
          provide: SESSION_PERSISTENCE,
          useValue: new MemoryPersistence(session ? JSON.stringify(session) : null, unreadable),
        },
        {
          provide: ActivatedRoute,
          useValue: { snapshot: { queryParamMap: { get: (k: string) => (k === 'id' ? '42' : null) } } },
        },
      ],
    });
    const store = TestBed.inject(SessionStore);
    await store.ready();
    const fixture = TestBed.createComponent(ProductPage);
    const router = TestBed.inject(Router);
    spyOn(router, 'navigate');
    const component = fixture.componentInstance;
    component.add_cart.product_id = 42;
    return { component, router, toast, adapter, store };
  }

  const SESSION = { id: 9, token: 'live-token', refresh_token: 'r' };

  it('lets a signed-in customer through even when the page copy says guest', async () => {
    const { component, router, toast } = await setupWithSession(SESSION);
    component.isGuest = true;            // stale ngOnInit copy
    component.single_user.token = '';
    (component.single as any).slug = 'abaya-1';

    component.requestCustomization();

    expect(toast.errors).toEqual([]);
    expect(router.navigate).toHaveBeenCalled();
  });

  it('never treats a stored session it cannot READ as a guest (no sign-in prompt)', async () => {
    const { component, toast, adapter, store } = await setupWithSession(SESSION, true);
    expect(store.isLoaded()).toBeFalse();
    adapter.next = null; // the POST is what matters, not its answer

    component.addToCart();

    expect(toast.errors).toEqual([]);
    expect(adapter.posts).toBe(1);
  });

  it('prompts sign-in when there is no live session even if the page copy has a token', async () => {
    const { component, toast, adapter } = await setupWithSession(null);
    component.isGuest = false;
    component.single_user.token = 'old-copy';

    component.addToCart();

    expect(toast.errors).toEqual(['sign_in_to_add_to_cart']);
    expect(adapter.posts).toBe(0);
  });

  it('ignores a second tap while the first add is in flight (one POST, no duplicate toast)', async () => {
    const { component, adapter, toast } = await setupWithSession(SESSION);
    adapter.next = null; // first POST never answers

    component.addToCart();
    component.addToCart();

    expect(adapter.posts).toBe(1);
    expect(component.ui_controls.is_adding_to_cart).toBeTrue();
    expect(toast.errors).toEqual([]);
  });

  it('a transient NETWORK_UNAVAILABLE shows the connection message, never the sign-in prompt', async () => {
    const { component, adapter, toast } = await setupWithSession(SESSION);
    adapter.next = {
      response_code: 503,
      status: 'error',
      message: 'Connection problem. Please try again.',
      error_code: NETWORK_UNAVAILABLE,
      data: null,
    };

    component.addToCart();

    expect(toast.errors).toEqual(['Connection problem. Please try again.']);
    expect(component.ui_controls.is_adding_to_cart).toBeFalse();
    expect(component.ui_controls.is_empty).toBeFalse();
  });

  it('a 401 while the session is still live is NOT treated as signed out', async () => {
    const { component, adapter, toast } = await setupWithSession(SESSION);
    adapter.next = { response_code: 401, status: 'error', message: 'Nope.', error_code: 'SOMETHING', data: null };

    component.addToCart();

    expect(toast.errors).toEqual(['Nope.']);
  });

  it('a 401 after the server ended the session shows the sign-in prompt', async () => {
    const { component, adapter, toast, store } = await setupWithSession(SESSION);
    adapter.next = { response_code: 401, status: 'error', message: 'x', error_code: 'AUTH_INVALID_TOKEN', data: null };
    // The adapter ends the session before handing the 401 back.
    spyOn(adapter, 'post_v3').and.callFake((route: string) => {
      void store.endSession('rejected', 'r');
      return ScriptedAdapter.prototype.post_v3.call(adapter, route);
    });

    component.addToCart();
    await Promise.resolve();

    expect(toast.errors).toEqual(['sign_in_to_add_to_cart']);
  });
});
