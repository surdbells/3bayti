import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { BehaviorSubject, of, throwError } from 'rxjs';

import { RegisterPage } from './register.page';
import { ConnectionService } from '../../service/connection.service';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';
import { CartMergeService } from '../../core/services/cart-merge.service';
import { PushManager } from '../../core/services/push-manager.service';
import { BlockerService } from '../../blocker.service';

// ---------------------------------------------------------------------------
// Stubs (useValue) — no real network / no real constructors.
// ---------------------------------------------------------------------------

class ConnectionStub {
  online$ = new BehaviorSubject<boolean>(true);
  setReachabilityCheck(_enabled: boolean, _url?: string) {}
}

class AdapterStub {
  // Replaced per-test with a jasmine spy.
  post_v3 = jasmine.createSpy('post_v3').and.returnValue(
    of({ response_code: 200, status: 'success', data: {} }),
  );
}

class ToastStub {
  error = jasmine.createSpy('error');
  success = jasmine.createSpy('success');
}

class I18nStub {
  // Echo the key back so assertions can match on the key string.
  t = (key: string) => key;
}

class CartMergeStub {
  mergeIfAny = jasmine.createSpy('mergeIfAny').and.returnValue(Promise.resolve());
}

class PushManagerStub {
  onSignedIn = jasmine.createSpy('onSignedIn').and.returnValue(Promise.resolve());
}

class BlockerStub {
  block = jasmine.createSpy('block');
}

function setup() {
  const net = new ConnectionStub();
  const adapter = new AdapterStub();
  const toast = new ToastStub();
  const cartMerge = new CartMergeStub();
  const pushManager = new PushManagerStub();
  const blocker = new BlockerStub();

  TestBed.configureTestingModule({
    imports: [RegisterPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: ConnectionService, useValue: net },
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      { provide: I18nService, useValue: new I18nStub() },
      { provide: CartMergeService, useValue: cartMerge },
      { provide: PushManager, useValue: pushManager },
      { provide: BlockerService, useValue: blocker },
    ],
  });

  const fixture = TestBed.createComponent(RegisterPage);
  const component = fixture.componentInstance;
  const router = TestBed.inject(Router);
  const navSpy = spyOn(router, 'navigate').and.resolveTo(true);

  return { component, adapter, toast, cartMerge, pushManager, blocker, net, navSpy };
}

/** v3 success envelope helper. */
function ok(data: any) {
  return of({ response_code: 200, status: 'success', data });
}
/** v3 error envelope helper (rides the SUCCESS channel). */
function err(error_code: string, message = 'boom', response_code = 409) {
  return of({ response_code, status: 'error', message, error_code });
}

describe('RegisterPage (v3 phone-first 4-step flow)', () => {
  it('should create', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  // ----- Step 1: initiate_phone -------------------------------------------

  describe('initiate_phone()', () => {
    it('guards when offline: error toast, no call', () => {
      const { component, adapter, toast } = setup();
      component.isOnline = false;
      component.register.phone = '501234567';
      component.initiate_phone();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_offline_check_connection', { position: 'top-center' });
    });

    it('guards when phone is empty: error toast, no call', () => {
      const { component, adapter, toast } = setup();
      component.register.phone = '';
      component.initiate_phone();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_phone_required', { position: 'top-center' });
    });

    it('calls initiate with fullPhone + ISO country_code and advances to step 2', () => {
      const { component, adapter, toast } = setup();
      component.register.phone = '0501234567'; // leading zero stripped
      adapter.post_v3.and.returnValue(ok({ verification_id: 'phone-vid-1' }));

      component.initiate_phone();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/register/initiate', {
        phone: '+971501234567',
        country_code: 'AE',
      });
      expect(component.phoneVerificationId).toBe('phone-vid-1');
      expect(component.ui_controls.step as number).toBe(2);
      expect(component.ui_controls.loading).toBeFalse();
      expect(toast.success).toHaveBeenCalledWith('text_otp_sent', { position: 'top-center' });
    });

    it('error envelope (CONFLICT_PHONE_TAKEN): toast, stays on step 1', () => {
      const { component, adapter, toast } = setup();
      component.register.phone = '501234567';
      adapter.post_v3.and.returnValue(err('CONFLICT_PHONE_TAKEN'));

      component.initiate_phone();

      expect(component.ui_controls.step as number).toBe(1);
      expect(component.phoneVerificationId).toBe('');
      expect(toast.error).toHaveBeenCalledWith('text_phone_already_registered', { position: 'top-center' });
    });

    it('missing verification_id in success data: request_failed toast, stays on step 1', () => {
      const { component, adapter, toast } = setup();
      component.register.phone = '501234567';
      adapter.post_v3.and.returnValue(ok({}));

      component.initiate_phone();

      expect(component.ui_controls.step as number).toBe(1);
      expect(toast.error).toHaveBeenCalledWith('text_request_failed', { position: 'top-center' });
    });

    it('rxjs error channel -> handleHttpError maps err.error.error.code', () => {
      const { component, adapter, toast } = setup();
      component.register.phone = '501234567';
      adapter.post_v3.and.returnValue(throwError(() => ({ error: { error: { code: 'OTP_RATE_LIMITED' } } })));

      component.initiate_phone();

      expect(component.ui_controls.loading).toBeFalse();
      expect(toast.error).toHaveBeenCalledWith('text_otp_rate_limited', { position: 'top-center' });
    });
  });

  // ----- Step 2: verify_phone ---------------------------------------------

  describe('verify_phone()', () => {
    it('guards empty otp: error toast, no call', () => {
      const { component, adapter, toast } = setup();
      component.otp.code = '';
      component.verify_phone();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_otp_required', { position: 'top-center' });
    });

    it('verifies and advances to step 3 with registration_token', () => {
      const { component, adapter } = setup();
      component.phoneVerificationId = 'phone-vid-1';
      component.otp.code = '123456';
      adapter.post_v3.and.returnValue(ok({ registration_token: 'reg-tok-1' }));

      component.verify_phone();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/register/verify-phone', {
        verification_id: 'phone-vid-1',
        code: '123456',
      });
      expect(component.registrationToken).toBe('reg-tok-1');
      expect(component.ui_controls.step as number).toBe(3);
    });

    it('error envelope (OTP_VERIFICATION_FAILED): toast, stays on step (no advance)', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.step = 2;
      component.otp.code = '000000';
      adapter.post_v3.and.returnValue(err('OTP_VERIFICATION_FAILED', 'bad', 401));

      component.verify_phone();

      expect(component.registrationToken).toBe('');
      expect(component.ui_controls.step as number).toBe(2);
      expect(toast.error).toHaveBeenCalledWith('text_otp_verification_failed', { position: 'top-center' });
    });
  });

  // ----- resend_phone_otp -------------------------------------------------

  describe('resend_phone_otp()', () => {
    it('guards offline', () => {
      const { component, adapter, toast } = setup();
      component.isOnline = false;
      component.resend_phone_otp();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_offline_check_connection', { position: 'top-center' });
    });

    it('re-calls initiate, updates vid, shows success toast, no step change', () => {
      const { component, adapter, toast } = setup();
      component.register.phone = '501234567';
      component.ui_controls.step = 2;
      adapter.post_v3.and.returnValue(ok({ verification_id: 'phone-vid-2' }));

      component.resend_phone_otp();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/register/initiate', {
        phone: '+971501234567',
        country_code: 'AE',
      });
      expect(component.phoneVerificationId).toBe('phone-vid-2');
      expect(component.ui_controls.step as number).toBe(2);
      expect(toast.success).toHaveBeenCalledWith('text_otp_sent', { position: 'top-center' });
    });

    it('error envelope: toast, no step change', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.step = 2;
      adapter.post_v3.and.returnValue(err('OTP_PROVIDER_ERROR', 'x', 502));
      component.resend_phone_otp();
      expect(component.ui_controls.step as number).toBe(2);
      expect(toast.error).toHaveBeenCalledWith('text_otp_provider_error', { position: 'top-center' });
    });
  });

  // ----- change_phone -----------------------------------------------------

  describe('change_phone()', () => {
    it('resets to step 1 and clears otp', () => {
      const { component } = setup();
      component.ui_controls.step = 2;
      component.otp.code = '123456';
      component.change_phone();
      expect(component.ui_controls.step as number).toBe(1);
      expect(component.otp.code).toBe('');
    });
  });

  // ----- Step 3: submit_details -------------------------------------------

  describe('submit_details()', () => {
    function fillDetails(component: RegisterPage) {
      component.registrationToken = 'reg-tok-1';
      component.register.first_name = 'Jane';
      component.register.last_name = 'Doe';
      component.register.email = 'jane@example.com';
      component.register.password = 'password1';
      component.register.confirm_password = 'password1';
      component.register.accepted_terms = true;
    }

    it('guards offline', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.isOnline = false;
      component.submit_details();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_offline_check_connection', { position: 'top-center' });
    });

    it('guards missing first_name', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.register.first_name = '';
      component.submit_details();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_first_name_required', { position: 'top-center' });
    });

    it('guards invalid email format', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.register.email = 'not-an-email';
      component.submit_details();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_invalid_email_format', { position: 'top-center' });
    });

    it('guards short password', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.register.password = 'short';
      component.register.confirm_password = 'short';
      component.submit_details();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_password_min_length', { position: 'top-center' });
    });

    it('guards password mismatch', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.register.confirm_password = 'different1';
      component.submit_details();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_passwords_do_not_match', { position: 'top-center' });
    });

    it('guards unaccepted terms', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.register.accepted_terms = false;
      component.submit_details();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_accept_terms_required', { position: 'top-center' });
    });

    it('submits and advances to step 4 with email verification_id', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      adapter.post_v3.and.returnValue(ok({ verification_id: 'email-vid-1' }));

      component.submit_details();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/register/submit', {
        registration_token: 'reg-tok-1',
        email: 'jane@example.com',
        password: 'password1',
        first_name: 'Jane',
        last_name: 'Doe',
      });
      expect(component.emailVerificationId).toBe('email-vid-1');
      expect(component.ui_controls.step as number).toBe(4);
      expect(toast.success).toHaveBeenCalledWith('text_otp_sent', { position: 'top-center' });
    });

    it('CONFLICT_EMAIL_TAKEN envelope: toast, stays on step 3', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.ui_controls.step = 3;
      adapter.post_v3.and.returnValue(err('CONFLICT_EMAIL_TAKEN'));

      component.submit_details();

      expect(component.ui_controls.step as number).toBe(3);
      expect(toast.error).toHaveBeenCalledWith('text_email_already_registered', { position: 'top-center' });
    });

    it('AUTH_INVALID_TOKEN envelope: restartExpired -> step 1, cleared tokens', () => {
      const { component, adapter, toast } = setup();
      fillDetails(component);
      component.phoneVerificationId = 'p';
      component.emailVerificationId = 'e';
      component.ui_controls.step = 3;
      adapter.post_v3.and.returnValue(err('AUTH_INVALID_TOKEN', 'expired', 401));

      component.submit_details();

      expect(component.ui_controls.step as number).toBe(1);
      expect(component.registrationToken).toBe('');
      expect(component.phoneVerificationId).toBe('');
      expect(component.emailVerificationId).toBe('');
      expect(toast.error).toHaveBeenCalledWith('text_session_expired_restart', { position: 'top-center' });
    });

    it('rxjs-error AUTH_INVALID_TOKEN -> restartExpired', () => {
      const { component, adapter } = setup();
      fillDetails(component);
      component.ui_controls.step = 3;
      adapter.post_v3.and.returnValue(throwError(() => ({ error: { error: { code: 'AUTH_INVALID_TOKEN' } } })));

      component.submit_details();

      expect(component.ui_controls.step as number).toBe(1);
      expect(component.registrationToken).toBe('');
    });
  });

  // ----- Step 4: confirm_email (auto-login happy path) --------------------

  describe('confirm_email()', () => {
    it('guards empty otp', () => {
      const { component, adapter, toast } = setup();
      component.otp.code = '';
      component.confirm_email();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_otp_required', { position: 'top-center' });
    });

    it('confirms, transforms user, merges cart, signs in push, navigates to /account', async () => {
      const { component, adapter, cartMerge, pushManager, blocker, navSpy } = setup();
      // NOTE: @capacitor/preferences' Preferences is a dynamic Proxy whose
      // method reads don't route through spyOn, and the transform is a
      // non-writable ES module export, so neither can be spied directly.
      // Instead we feed v3-shaped data and assert the collaborators that
      // consume the TRANSFORM OUTPUT: cartMerge is called with
      // {id, token} derived from the transformed user, which proves the
      // real transform ran on response.data.
      component.emailVerificationId = 'email-vid-1';
      component.otp.code = '654321';

      // v3-shaped data so the real transform returns a legacy object
      // ({ id: 42, token: 'access-tok', ... }).
      adapter.post_v3.and.returnValue(ok({
        access_token: 'access-tok',
        refresh_token: 'refresh-tok',
        user: { id: 42, first_name: 'Jane', last_name: 'Doe', email: 'jane@example.com', phone: '+971501234567', roles: [] },
      }));

      component.confirm_email();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/register/confirm-email', {
        verification_id: 'email-vid-1',
        code: '654321',
      });
      // cart merge called with {id, token} derived from the transformed user.
      expect(cartMerge.mergeIfAny).toHaveBeenCalledWith({ id: 42, token: 'access-tok' });
      expect(pushManager.onSignedIn).toHaveBeenCalled();
      expect(navSpy).toHaveBeenCalledWith(['/account'], { replaceUrl: true });
      expect(blocker.block).toHaveBeenCalled();
      // let the cartMerge promise settle to avoid leaks
      await Promise.resolve();
    });

    it('error envelope: toast, no navigation, no auto-login side-effects', () => {
      const { component, adapter, toast, cartMerge, pushManager, navSpy } = setup();
      component.emailVerificationId = 'email-vid-1';
      component.otp.code = '000000';
      adapter.post_v3.and.returnValue(err('OTP_VERIFICATION_FAILED', 'bad', 401));

      component.confirm_email();

      expect(navSpy).not.toHaveBeenCalled();
      expect(cartMerge.mergeIfAny).not.toHaveBeenCalled();
      expect(pushManager.onSignedIn).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_otp_verification_failed', { position: 'top-center' });
    });
  });

  // ----- resend_email_otp -------------------------------------------------

  describe('resend_email_otp()', () => {
    it('guards offline', () => {
      const { component, adapter, toast } = setup();
      component.isOnline = false;
      component.resend_email_otp();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_offline_check_connection', { position: 'top-center' });
    });

    it('re-calls submit, updates email vid, success toast, no step change', () => {
      const { component, adapter, toast } = setup();
      component.registrationToken = 'reg-tok-1';
      component.register.email = 'jane@example.com';
      component.register.password = 'password1';
      component.register.first_name = 'Jane';
      component.register.last_name = 'Doe';
      component.ui_controls.step = 4;
      adapter.post_v3.and.returnValue(ok({ verification_id: 'email-vid-2' }));

      component.resend_email_otp();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/register/submit', {
        registration_token: 'reg-tok-1',
        email: 'jane@example.com',
        password: 'password1',
        first_name: 'Jane',
        last_name: 'Doe',
      });
      expect(component.emailVerificationId).toBe('email-vid-2');
      expect(component.ui_controls.step as number).toBe(4);
      expect(toast.success).toHaveBeenCalledWith('text_otp_sent', { position: 'top-center' });
    });

    it('AUTH_INVALID_TOKEN envelope -> restartExpired (step 1)', () => {
      const { component, adapter } = setup();
      component.ui_controls.step = 4;
      component.registrationToken = 'reg-tok-1';
      adapter.post_v3.and.returnValue(err('AUTH_INVALID_TOKEN', 'expired', 401));
      component.resend_email_otp();
      expect(component.ui_controls.step as number).toBe(1);
      expect(component.registrationToken).toBe('');
    });
  });

  // ----- back_to_details --------------------------------------------------

  describe('back_to_details()', () => {
    it('returns to step 3 and clears otp', () => {
      const { component } = setup();
      component.ui_controls.step = 4;
      component.otp.code = '111111';
      component.back_to_details();
      expect(component.ui_controls.step as number).toBe(3);
      expect(component.otp.code).toBe('');
    });
  });
});
