import { provideZoneChangeDetection } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { NavController } from '@ionic/angular/standalone';
import { Observable, of, throwError } from 'rxjs';

import { FollowingPage } from './following.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

const store = (id: number, name: string) => ({
  id, slug: `s-${id}`, name, description: null, logo_url: null, is_verified: false,
});

function setup(deleteReply: Observable<unknown>) {
  const adapter = {
    delete_v3: jasmine.createSpy('delete_v3').and.returnValue(deleteReply),
    get_v3: () => of({ response_code: 200, status: 'success', data: [] }),
  };
  const toast = {
    success: jasmine.createSpy('success'),
    error: jasmine.createSpy('error'),
  };
  TestBed.configureTestingModule({
    imports: [FollowingPage],
    providers: [
      provideZoneChangeDetection(),
      provideRouter([]),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      {
        provide: I18nService,
        useValue: {
          t: (key: string, params?: Record<string, unknown>) =>
            params && params['store'] != null ? `${key}|${params['store']}` : key,
        },
      },
      { provide: NavController, useValue: { back: () => undefined } },
    ],
  });
  const component = TestBed.createComponent(FollowingPage).componentInstance;
  component.stores = [store(1, 'Dar Abaya'), store(2, 'Noor')];
  return { component, adapter, toast };
}

describe('FollowingPage unfollow', () => {
  it('removes the row and names the store in a success toast', async () => {
    const { component, adapter, toast } = setup(of({ response_code: 200, status: 'success', message: '', data: null }));

    await component.unfollow(component.stores[0]);

    expect(adapter.delete_v3.calls.mostRecent().args[1].pathParams).toEqual({ vendorId: '1' });
    expect(component.stores.map((s) => s.id)).toEqual([2]);
    expect(toast.success).toHaveBeenCalledOnceWith('store_unfollow_success|Dar Abaya', jasmine.anything());
    expect(toast.error).not.toHaveBeenCalled();
    expect(component.isUnfollowing(1)).toBeFalse();
  });

  it('keeps the row when the server refuses (HTTP errors resolve, they do not throw)', async () => {
    const { component, toast } = setup(of({ response_code: 401, status: 'error', message: 'Authentication required.', data: null }));

    await component.unfollow(component.stores[0]);

    expect(component.stores.map((s) => s.id)).toEqual([1, 2]);
    expect(toast.success).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledOnceWith('following_unfollow_failed', jasmine.anything());
  });

  it('shows the localized error on a network failure', async () => {
    const { component, toast } = setup(throwError(() => new Error('offline')));

    await component.unfollow(component.stores[1]);

    expect(component.stores.length).toBe(2);
    expect(toast.error).toHaveBeenCalledOnceWith('following_unfollow_failed', jasmine.anything());
    expect(component.isUnfollowing(2)).toBeFalse();
  });
});
