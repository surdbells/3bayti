import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { CartPage } from './cart.page';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';

describe('CartPage', () => {
  let component: CartPage;
  let fixture: ComponentFixture<CartPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(CartPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});

describe('CartPage line controls', () => {
  let component: CartPage;
  let patch$: Subject<any>;
  let cart$: Subject<any>;
  let adapter: { patch_v3: jasmine.Spy; get_v3: jasmine.Spy; delete_v3: jasmine.Spy };
  let toast: { success: jasmine.Spy; error: jasmine.Spy };

  beforeEach(() => {
    patch$ = new Subject();
    cart$ = new Subject();
    adapter = {
      patch_v3: jasmine.createSpy('patch_v3').and.callFake(() => patch$),
      get_v3: jasmine.createSpy('get_v3').and.callFake(() => cart$),
      delete_v3: jasmine.createSpy('delete_v3').and.callFake(() => patch$),
    };
    toast = { success: jasmine.createSpy('success'), error: jasmine.createSpy('error') };
    TestBed.configureTestingModule({
      imports: [CartPage],
      providers: [
        { provide: MobileNetworkAdapter, useValue: adapter },
        { provide: AxNotificationService, useValue: toast },
      ],
    });
    component = TestBed.createComponent(CartPage).componentInstance;
    component.isGuest = false;
    component.single_user.token = 'token';
    // A loaded cart: controls start unlocked.
    component.ui_controls.is_loading = false;
  });

  it('locks the line controls from the tap until the reloaded cart lands, so a fast second tap is not lost', () => {
    expect(component.lineControlsLocked).toBeFalse();

    component.IncreaseItem(5, 1);
    expect(adapter.patch_v3).toHaveBeenCalledWith('PATCH /cart/items/:id', { quantity: 2 }, jasmine.any(Object));
    expect(component.lineControlsLocked).toBeTrue();

    // A second tap on the still-stale "1" would resend quantity 2: ignored.
    component.IncreaseItem(5, 1);
    expect(adapter.patch_v3).toHaveBeenCalledTimes(1);

    // PATCH lands -> reload starts: still locked (quantities are stale).
    patch$.next({ response_code: 200, status: 'success', data: {} });
    expect(adapter.get_v3).toHaveBeenCalledWith('GET /cart', jasmine.any(Object));
    expect(component.ui_controls.is_updating).toBeFalse();
    expect(component.lineControlsLocked).toBeTrue();

    // Fresh cart lands -> unlocked.
    cart$.next({ response_code: 200, status: 'success', data: { items: [{ id: 1, item: 5, quantity: 2 }], bill: { count: 2 } } });
    expect(component.lineControlsLocked).toBeFalse();
  });

  it('unlocks and shows the API message when the write is refused', () => {
    component.DecreaseItem(5, 3);
    expect(component.lineControlsLocked).toBeTrue();

    patch$.next({ response_code: 422, status: 'error', message: 'Only 2 left in stock' });
    expect(component.lineControlsLocked).toBeFalse();
    expect(adapter.get_v3).not.toHaveBeenCalled();
    expect(toast.error).toHaveBeenCalledWith('Only 2 left in stock', jasmine.any(Object));
  });

  it('unlocks on a transport error instead of staying locked forever', () => {
    component.removeItem(5);
    expect(component.lineControlsLocked).toBeTrue();

    patch$.error({ status: 0 });
    expect(component.lineControlsLocked).toBeFalse();
    expect(toast.error).toHaveBeenCalled();
  });
});
