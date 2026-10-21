import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { NavController, Platform } from '@ionic/angular/standalone';
import { ActionSheetController } from '@ionic/angular';
import { Observable, of, throwError } from 'rxjs';

import { VendorReviewsPage } from './vendor-reviews.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { transformReviewDisplayResponse } from '../../core/http/transforms/catalog-response.transforms';
import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { provideAxIcons } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';
import { CartCountService } from '../../core/services/cart-count.service';
import { PendingOrdersService } from '../../core/services/pending-orders.service';

const ok = (data: unknown, meta?: unknown) => ({ response_code: 200, status: 'success', message: '', data, ...(meta ? { meta } : {}) });

/** ReviewSerializer::publicShape rows, as the adapter hands them over (transformed). */
const rows = (...ids: number[]) =>
  transformReviewDisplayResponse(ids.map((id) => ({
    id, product_id: null, product_name: null, reviewer: `User ${id}`, star: String((id % 5) + 1),
    title: id === 1 ? 'Gorgeous' : null, comment: `Body ${id}`, vendor_reply: null,
    is_verified: id === 1, helpful_count: id,
    // Oldest id first in time, so "recent" must reverse a by-id list.
    created_at: `2026-10-${String(10 + id).padStart(2, '0')} 09:00:00`,
  }))) as unknown[];

class AdapterStub {
  replies: Record<string, Observable<unknown>> = {
    'GET /mobile/read-vendor': of(ok({ id: 5, name: 'Dar Abaya', logo: '', cover: '', tagline: '', following: false })),
    'GET /vendors/:vendorId/reviews': of(ok([], { total: 0, limit: 10, offset: 0 })),
    'POST /reviews/:id/helpful': of(ok({ id: 2, helpful_count: 9 })),
    'POST /vendors/:vendorId/reviews': of(ok({ id: 99 })),
  };
  get_v3 = jasmine.createSpy('get_v3').and.callFake((k: string) => this.replies[k]);
  post_v3 = jasmine.createSpy('post_v3').and.callFake((k: string) => this.replies[k]);
}

class ToastStub {
  success = jasmine.createSpy('success');
  error = jasmine.createSpy('error');
}

function setup() {
  const adapter = new AdapterStub();
  const toast = new ToastStub();
  const params: Record<string, string> = { vendor_id: '5', slug: 'dar-abaya', name: 'Dar Abaya' };
  TestBed.configureTestingModule({
    imports: [VendorReviewsPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideAxIcons(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      { provide: I18nService, useValue: { lang: 'en', t: (k: string) => k } },
      { provide: NavController, useValue: { back: () => undefined } },
      { provide: Platform, useValue: {} },
      { provide: ActionSheetController, useValue: {} },
      { provide: ConnectionService, useValue: {} },
      { provide: NetworkService, useValue: {} },
      { provide: CartCountService, useValue: { refresh: () => undefined, count: () => 0 } },
      { provide: PendingOrdersService, useValue: { count: () => 0 } },
      { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: { get: (k: string) => params[k] ?? null } } } },
    ],
  });
  const fixture = TestBed.createComponent(VendorReviewsPage);
  const component = fixture.componentInstance;
  component.ngOnInit();
  const el = fixture.nativeElement as HTMLElement;
  /** What getObject() does after reading the stored user. */
  const load = () => {
    component.get_reviews(true);
    component.get_vendor();
    fixture.detectChanges();
  };
  return { fixture, component, adapter, toast, el, load };
}

describe('VendorReviewsPage', () => {
  it('shows the empty state for a store with no reviews', () => {
    const { component, el, load } = setup();
    load();
    expect(component.ui_controls.is_loading).toBeFalse();
    expect(el.querySelector('.empty-state')?.textContent).toContain('heading_no_reviews');
  });

  it('renders the review body, helpful count and verified badge from v3 fields', () => {
    const { adapter, el, load } = setup();
    adapter.replies['GET /vendors/:vendorId/reviews'] = of(ok(rows(1, 2), { total: 2 }));
    load();

    const cards = el.querySelectorAll('ion-card.review-card');
    expect(cards.length).toBe(2);
    expect(cards[0].querySelector('.review-text')?.textContent).toContain('Body 1');
    expect(cards[0].querySelector('.review-title')?.textContent).toContain('Gorgeous');
    expect(cards[0].querySelector('.action-btn')?.textContent).toContain('(1)');
    expect(cards[0].querySelector('.verified-badge')).not.toBeNull();
    // No empty title row when the review has none.
    expect(cards[1].querySelector('.review-title')).toBeNull();
    // Relative date rendered even from a MySQL-style timestamp.
    expect(cards[1].querySelector('.date')?.textContent?.trim().length).toBeGreaterThan(0);
  });

  it('uses meta.total for the count and averages the loaded ratings', () => {
    const { component, adapter, el, load } = setup();
    adapter.replies['GET /vendors/:vendorId/reviews'] = of(ok(rows(1, 2), { total: 14 }));
    load();
    // ratings 2 and 3
    expect(component.summaryRating).toBe(2.5);
    expect(el.querySelector('.rating-sub')?.textContent).toContain('(14');
  });

  it('pages with explicit limit/offset and never re-appends the same reviews', () => {
    const { component, adapter, load } = setup();
    adapter.replies['GET /vendors/:vendorId/reviews'] = of(ok(rows(1, 2), { total: 3 }));
    load();
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendors/:vendorId/reviews', jasmine.objectContaining({
      queryParams: { limit: 10, offset: 0 },
    }));
    expect(component.hasMore).toBeTrue();

    // Server returns an overlapping page: only the new review is appended.
    adapter.replies['GET /vendors/:vendorId/reviews'] = of(ok(rows(2, 3), { total: 3 }));
    const done = jasmine.createSpy('done');
    component.getMoreItems(done);
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendors/:vendorId/reviews', jasmine.objectContaining({
      queryParams: { limit: 10, offset: 2 },
    }));
    expect(component.reviews.map((r) => r.id)).toEqual([1, 2, 3]);
    expect(component.hasMore).toBeFalse();
    expect(done).toHaveBeenCalled();

    // Nothing more to fetch: completes immediately without a request.
    const calls = adapter.get_v3.calls.count();
    component.getMoreItems(done);
    expect(adapter.get_v3.calls.count()).toBe(calls);
  });

  it('sorts by "Most recent" without throwing (used to read an unset createdAt)', () => {
    const { component, adapter, load } = setup();
    adapter.replies['GET /vendors/:vendorId/reviews'] = of(ok(rows(1, 2, 3), { total: 3 }));
    load();
    component.sortBy = 'ratingDesc';
    component.applySort();
    component.sortBy = 'recent';
    expect(() => component.applySort()).not.toThrow();
    expect(component.reviews.map((r) => r.id)).toEqual([3, 2, 1]);
  });

  it('shows a retry state on a transport error instead of a blank list', () => {
    const { component, adapter, el, load } = setup();
    adapter.replies['GET /vendors/:vendorId/reviews'] = throwError(() => ({ status: 0 }));
    load();
    expect(component.ui_controls.is_loading).toBeFalse();
    expect(component.ui_controls.is_error).toBeTrue();
    expect(el.querySelector('.retry-btn')).not.toBeNull();
    expect(el.querySelector('ion-infinite-scroll')).toBeNull();
  });

  it('updates the helpful count in place, without an empty toast', () => {
    const { component, adapter, toast, load } = setup();
    adapter.replies['GET /vendors/:vendorId/reviews'] = of(ok(rows(1, 2), { total: 2 }));
    load();
    component.toggleHelpful(2);
    const card = component.reviews.find((r) => r.id === 2)!;
    expect(card.helpfulCount).toBe(9);
    expect(card.helpful).toBeTrue();
    expect(toast.success).not.toHaveBeenCalled();

    // A second tap on the same card doesn't re-post.
    const posts = adapter.post_v3.calls.count();
    component.toggleHelpful(2);
    expect(adapter.post_v3.calls.count()).toBe(posts);
  });

  it('confirms a submitted store review with a real message', () => {
    const { component, toast, load } = setup();
    load();
    component.add_new_review.star = 4;
    component.add_new_review.title = 'Great';
    component.add_new_review.comment = 'Loved it';
    component.isReviewOpen = true;
    component.add_review();
    expect(toast.success).toHaveBeenCalledWith('review_submitted_for_approval', jasmine.anything());
    expect(component.isReviewOpen).toBeFalse();
  });

  it('validates with translated messages', () => {
    const { component, toast } = setup();
    component.add_review();
    expect(toast.error).toHaveBeenCalledWith('review_select_rating', jasmine.anything());
    component.add_new_review.star = 3;
    component.add_review();
    expect(toast.error).toHaveBeenCalledWith('review_title_required', jasmine.anything());
    component.add_new_review.title = 'T';
    component.add_review();
    expect(toast.error).toHaveBeenCalledWith('review_comment_required', jasmine.anything());
  });
});
