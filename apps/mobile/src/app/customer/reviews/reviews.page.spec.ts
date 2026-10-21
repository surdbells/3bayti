import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { NavController, Platform } from '@ionic/angular/standalone';
import { ActionSheetController } from '@ionic/angular';
import { Observable, of, throwError } from 'rxjs';

import { ReviewsPage } from './reviews.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { transformReviewDisplayResponse } from '../../core/http/transforms/catalog-response.transforms';
import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { provideAxIcons } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';

const ok = (data: unknown) => ({ response_code: 200, status: 'success', message: '', data });

/** ReviewSerializer::mineShape row, as the adapter hands it over (transformed). */
const mine = (id: number) =>
  (transformReviewDisplayResponse([{
    id, product_id: 3, product_name: `Abaya ${id}`, vendor_id: 2, star: '5', title: null,
    comment: `Comment ${id}`, status: 'approved', vendor_reply: null, helpful_count: 0,
    created_at: '2026-10-21 12:34:56',
  }]) as unknown[])[0];

class AdapterStub {
  list: Observable<unknown> = of(ok([]));
  del: Observable<unknown> = of(ok(null));
  get_v3 = jasmine.createSpy('get_v3').and.callFake(() => this.list);
  delete_v3 = jasmine.createSpy('delete_v3').and.callFake(() => this.del);
}

class ToastStub {
  success = jasmine.createSpy('success');
  error = jasmine.createSpy('error');
}

function setup() {
  const adapter = new AdapterStub();
  const toast = new ToastStub();
  TestBed.configureTestingModule({
    imports: [ReviewsPage],
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
      { provide: ConnectionService, useValue: { setReachabilityCheck: () => undefined, online$: of(true) } },
      { provide: NetworkService, useValue: {} },
    ],
  });
  const fixture = TestBed.createComponent(ReviewsPage);
  const component = fixture.componentInstance;
  component.single_user.token = 'tok';
  const el = fixture.nativeElement as HTMLElement;
  return { fixture, component, adapter, toast, el };
}

describe('ReviewsPage (My reviews)', () => {
  it('shows the empty state, not a blank page, when v3 answers 200 + []', () => {
    const { fixture, component, el } = setup();
    component.get_reviews();
    fixture.detectChanges();

    expect(component.ui_controls.is_empty).toBeTrue();
    expect(el.querySelector('.empty-state')?.textContent).toContain('heading_no_reviews');
    expect(el.querySelectorAll('ion-card.review-card').length).toBe(0);
  });

  it('renders a card per review (iOS-hostile MySQL date included)', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.list = of(ok([mine(1), mine(2)]));
    component.get_reviews();
    fixture.detectChanges();

    const cards = el.querySelectorAll('ion-card.review-card');
    expect(cards.length).toBe(2);
    expect(cards[0].textContent).toContain('Abaya 1');
    expect(cards[0].textContent).toContain('Comment 1');
    expect(el.querySelector('.empty-state')).toBeNull();
  });

  it('shows a retry state on a transport error instead of a stuck loader', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.list = throwError(() => ({ status: 0 }));
    component.get_reviews();
    fixture.detectChanges();

    expect(component.ui_controls.is_loading).toBeFalse();
    expect(component.ui_controls.is_error).toBeTrue();
    const retry = el.querySelector<HTMLButtonElement>('.retry-btn');
    expect(retry).not.toBeNull();

    adapter.list = of(ok([mine(1)]));
    retry!.click();
    fixture.detectChanges();
    expect(adapter.get_v3).toHaveBeenCalledTimes(2);
    expect(el.querySelectorAll('ion-card.review-card').length).toBe(1);
  });

  it('shows a retry state (not "no reviews") on an HTTP error envelope', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.list = of({ response_code: 500, status: 'error', message: 'Request failed', data: null });
    component.get_reviews();
    fixture.detectChanges();

    expect(component.ui_controls.is_error).toBeTrue();
    expect(el.querySelector('.retry-btn')).not.toBeNull();
    expect(el.textContent).not.toContain('heading_no_reviews');
  });

  it('shows skeletons while loading', () => {
    const { fixture, component, adapter, el } = setup();
    adapter.list = new Observable(() => undefined); // never settles
    component.get_reviews();
    fixture.detectChanges();

    expect(el.querySelectorAll('.review-card--skeleton').length).toBe(3);
  });

  it('removes a deleted review locally and toasts a real message (DELETE is a 204)', () => {
    const { fixture, component, adapter, toast } = setup();
    adapter.list = of(ok([mine(1), mine(2)]));
    component.get_reviews();
    component.delete_reviews(1);
    fixture.detectChanges();

    expect(adapter.delete_v3).toHaveBeenCalled();
    expect(toast.success).toHaveBeenCalledWith('review_deleted', jasmine.anything());
    expect(component.reviews.map((r) => r.id)).toEqual([2]);
  });
});
