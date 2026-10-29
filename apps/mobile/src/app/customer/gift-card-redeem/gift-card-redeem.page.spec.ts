import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';

import { GiftCardRedeemPage } from './gift-card-redeem.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { SESSION_PERSISTENCE, SessionStore, type SessionPersistence } from '../../core/services/session-store.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';

class MemoryPersistence implements SessionPersistence {
  constructor(public value: string | null, private unreadable = false) {}
  read(): Promise<string | null> {
    return this.unreadable ? Promise.reject(new Error('storage busy')) : Promise.resolve(this.value);
  }
  write(value: string): Promise<void> { this.value = value; return Promise.resolve(); }
  remove(): Promise<void> { this.value = null; return Promise.resolve(); }
}

const UNAUTHORIZED = { response_code: 401, status: 'error', message: 'Gift card is locked.', error_code: 'X', data: null };

async function setup(session: Record<string, unknown> | null, unreadable = false) {
  const adapter = { post_v3: jasmine.createSpy('post_v3').and.returnValue(of(UNAUTHORIZED)) };
  const toast = jasmine.createSpyObj('AxNotificationService', ['error', 'success']);
  TestBed.configureTestingModule({
    imports: [GiftCardRedeemPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      {
        provide: SESSION_PERSISTENCE,
        useValue: new MemoryPersistence(session ? JSON.stringify(session) : null, unreadable),
      },
    ],
  });
  await TestBed.inject(SessionStore).ready();
  const router = TestBed.inject(Router);
  spyOn(router, 'navigate');
  const page = TestBed.createComponent(GiftCardRedeemPage).componentInstance;
  page.code = 'ABCD-EFGH-IJKL-MNOP';
  return { page, router, toast };
}

describe('GiftCardRedeemPage redeem 401', () => {
  it('sends a signed-out customer to login (returning here)', async () => {
    const { page, router, toast } = await setup(null);
    page.redeemCard();
    expect(router.navigate).toHaveBeenCalledWith(['/login'], { queryParams: { returnUrl: '/gift-cards/redeem' } });
    expect(toast.error).not.toHaveBeenCalled();
  });

  it('never redirects a customer whose session is still live: shows the message instead', async () => {
    const { page, router, toast } = await setup({ id: 1, token: 'live', refresh_token: 'r' });
    page.redeemCard();
    expect(router.navigate).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith('Gift card is locked.');
  });

  it('never redirects while the stored session cannot be read (unknown is not signed out)', async () => {
    const { page, router, toast } = await setup({ id: 1, token: 'live', refresh_token: 'r' }, true);
    page.redeemCard();
    expect(router.navigate).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith('Gift card is locked.');
  });
});
