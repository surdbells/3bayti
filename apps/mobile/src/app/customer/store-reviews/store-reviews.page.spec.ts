import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { NavController, Platform } from '@ionic/angular/standalone';
import { ActionSheetController } from '@ionic/angular';
import { Observable, of, throwError } from 'rxjs';

import { StoreReviewsPage } from './store-reviews.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { transformReviewDisplayResponse } from '../../core/http/transforms/catalog-response.transforms';
import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { provideAxIcons } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';

const ok = (data: unknown) => ({ response_code: 200, status: 'success', message: '', data });

/** ReviewSerializer::publicShape row, as the adapter hands it over (transformed). */
const pub = (id: number, reviewer: string) =>
  (transformReviewDisplayResponse([{
    id, product_id: null, product_name: null, reviewer, star: '4', title: null,
    comment: `Lovely ${id}`, vendor_reply: null, is_verified: false, helpful_count: 0,
    created_at: '2026-10-21T12:34:56+00:00',
  }]) as unknown[])[0];

class AdapterStub {
  list: Observable<unknown> = of(ok([]));
  get_v3 = jasmine.createSpy('get_v3').and.callFake(() => this.list);
}

function setup(params: Record<string, string> = { slug: 'dar-abaya', name: 'Dar Abaya' }) {
  const adapter = new AdapterStub();
  TestBed.configureTestingModule({
    imports: [StoreReviewsPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideAxIcons(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: { success: () => undefined, error: () => undefined } },
      { provide: I18nService, useValue: { lang: 'en', t: (k: string) => k } },
      { provide: NavController, useValue: { back: () => undefined } },
      { provide: Platform, useValue: {} },
      { provide: ActionSheetController, useValue: {} },
      { provide: ConnectionService, useValue: { setReachabilityCheck: () => undefined, online$: of(true) } },
      { provide: NetworkService, useValue: {} },
      {
        provide: ActivatedRoute,
        useValue: { snapshot: { queryParamMap: { get: (k: string) => params[k] ?? null } } },
      },
    ],
  });
  const fixture = TestBed.createComponent(StoreReviewsPage);
  const component = fixture.componentInstance;
  component.ngOnInit();
  const el = fixture.nativeElement as HTMLElement;
  return { fixture, component, adapter, el };
}

describe('StoreReviewsPage', () => {
  it('shows the empty state, not a blank page, when the store has no reviews (200 + [])', () => {
    const { fixture, component, el } = setup();
    component.get_reviews();
    fixture.detectChanges();

    expect(el.querySelector('.empty-state')?.textContent).toContain('heading_no_reviews_yet');
    expect(el.querySelectorAll('.reviews-list .review-card').length).toBe(0);
  });

  it('renders the reviewer name from the v3 `reviewer` field', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.list = of(ok([pub(1, 'Sara'), pub(2, 'Mona')]));
    component.get_reviews();
    fixture.detectChanges();

    const names = Array.from(el.querySelectorAll('.reviewer-name')).map((n) => n.textContent?.trim());
    expect(names).toEqual(['Sara', 'Mona']);
    expect(el.textContent).toContain('Lovely 1');
  });

  it('requests the store by its slug, never a legacy id', () => {
    const { component, adapter } = setup({ slug: 'dar-abaya', id: '12', name: 'Dar Abaya' });
    component.get_reviews();
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /vendors/:vendorId/reviews', { pathParams: { vendorId: 'dar-abaya' } });
  });

  it('shows the empty state without a request when opened with no slug', () => {
    const { fixture, component, adapter, el } = setup({ id: '12', name: 'Dar Abaya' });
    component.get_reviews();
    fixture.detectChanges();

    expect(adapter.get_v3).not.toHaveBeenCalled();
    expect(component.ui_controls.is_loading).toBeFalse();
    expect(el.querySelector('.empty-state')).not.toBeNull();
  });

  it('shows a retry state on a transport error instead of a stuck loader', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.list = throwError(() => ({ status: 0 }));
    component.get_reviews();
    fixture.detectChanges();

    expect(component.ui_controls.is_loading).toBeFalse();
    expect(el.querySelector('.retry-btn')).not.toBeNull();
  });
});
