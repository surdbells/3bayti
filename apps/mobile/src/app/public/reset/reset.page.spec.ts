import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { BehaviorSubject, of, throwError } from 'rxjs';

import { ResetPage } from './reset.page';
import { ConnectionService } from '../../service/connection.service';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

// ---------------------------------------------------------------------------
// Stubs (useValue) — no real network / no real constructors.
// ---------------------------------------------------------------------------

class ConnectionStub {
  online$ = new BehaviorSubject<boolean>(true);
  setReachabilityCheck(_enabled: boolean, _url?: string) {}
}

class AdapterStub {
  post_v3 = jasmine.createSpy('post_v3').and.returnValue(
    of({ response_code: 200, status: 'success', data: {} }),
  );
}

class ToastStub {
  error = jasmine.createSpy('error');
  success = jasmine.createSpy('success');
}

class I18nStub {
  t = (key: string) => key;
}

function setup() {
  const net = new ConnectionStub();
  const adapter = new AdapterStub();
  const toast = new ToastStub();

  TestBed.configureTestingModule({
    imports: [ResetPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: ConnectionService, useValue: net },
      { provide: MobileNetworkAdapter, useValue: adapter },
      { provide: AxNotificationService, useValue: toast },
      { provide: I18nService, useValue: new I18nStub() },
    ],
  });

  const fixture = TestBed.createComponent(ResetPage);
  const component = fixture.componentInstance;
  const router = TestBed.inject(Router);
  const navSpy = spyOn(router, 'navigate').and.resolveTo(true);

  return { component, adapter, toast, net, navSpy };
}

function ok(data: any) {
  return of({ response_code: 200, status: 'success', data });
}
function err(error_code: string, message = 'boom', response_code = 401) {
  return of({ response_code, status: 'error', message, error_code });
}

describe('ResetPage (v3 two-option reset flow)', () => {
  it('should create', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  // ----- setChannel -------------------------------------------------------

  describe('setChannel()', () => {
    it('sets channel to phone then back to email', () => {
      const { component } = setup();
      component.setChannel('phone');
      expect(component.ui_controls.channel).toBe('phone');
      component.setChannel('email');
      expect(component.ui_controls.channel).toBe('email');
    });
  });

  // ----- send_reset_otp (Step 1) ------------------------------------------

  describe('send_reset_otp()', () => {
    it('guards offline', () => {
      const { component, adapter, toast } = setup();
      component.isOnline = false;
      component.reset_p.email = 'jane@example.com';
      component.send_reset_otp();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_offline_check_connection', { position: 'top-center' });
    });

    it('guards empty email (email channel)', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = '';
      component.send_reset_otp();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_email_required', { position: 'top-center' });
    });

    it('guards invalid email format (email channel)', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = 'not-an-email';
      component.send_reset_otp();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_invalid_email_format', { position: 'top-center' });
    });

    it('guards empty phone (phone channel)', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'phone';
      component.reset_p.phone = '';
      component.send_reset_otp();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_phone_required', { position: 'top-center' });
    });

    it('email channel: sends {channel:email,email} and advances to step 2', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = 'jane@example.com';
      adapter.post_v3.and.returnValue(ok({ verification_id: 'reset-vid-1' }));

      component.send_reset_otp();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/reset', {
        channel: 'email',
        email: 'jane@example.com',
      });
      expect(component.verification_id).toBe('reset-vid-1');
      expect(component.ui_controls.step as number).toBe(2);
      expect(toast.success).toHaveBeenCalledWith('text_otp_sent', { position: 'top-center' });
    });

    it('phone channel: sends {channel:phone,phone:fullPhone} ONLY (no email key)', () => {
      const { component, adapter } = setup();
      component.ui_controls.channel = 'phone';
      component.reset_p.phone = '0501234567';
      adapter.post_v3.and.returnValue(ok({ verification_id: 'reset-vid-2' }));

      component.send_reset_otp();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/reset', {
        channel: 'phone',
        phone: '+971501234567',
      });
      expect(component.ui_controls.step as number).toBe(2);
    });

    it('error envelope (OTP_RATE_LIMITED): toast, stays on step 1', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = 'jane@example.com';
      adapter.post_v3.and.returnValue(err('OTP_RATE_LIMITED', 'slow down', 429));

      component.send_reset_otp();

      expect(component.ui_controls.step as number).toBe(1);
      expect(component.verification_id).toBe('');
      expect(toast.error).toHaveBeenCalledWith('text_otp_rate_limited', { position: 'top-center' });
    });

    it('missing verification_id: request_failed, stays on step 1', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = 'jane@example.com';
      adapter.post_v3.and.returnValue(ok({}));

      component.send_reset_otp();

      expect(component.ui_controls.step as number).toBe(1);
      expect(toast.error).toHaveBeenCalledWith('text_request_failed', { position: 'top-center' });
    });

    it('rxjs error -> handleHttpError generic request_failed', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = 'jane@example.com';
      adapter.post_v3.and.returnValue(throwError(() => ({ message: 'net down' })));

      component.send_reset_otp();

      expect(component.ui_controls.loading).toBeFalse();
      expect(toast.error).toHaveBeenCalledWith('text_request_failed', { position: 'top-center' });
    });
  });

  // ----- reset_password (Step 2 confirm) ----------------------------------

  describe('reset_password()', () => {
    function fillStep2(component: ResetPage) {
      component.verification_id = 'reset-vid-1';
      component.reset_p.code = '123456';
      component.reset_p.new_password = 'newpass1';
      component.reset_p.confirm_password = 'newpass1';
    }

    it('guards offline', () => {
      const { component, adapter, toast } = setup();
      fillStep2(component);
      component.isOnline = false;
      component.reset_password();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_offline_check_connection', { position: 'top-center' });
    });

    it('guards empty otp', () => {
      const { component, adapter, toast } = setup();
      fillStep2(component);
      component.reset_p.code = '';
      component.reset_password();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_otp_required', { position: 'top-center' });
    });

    it('guards short password', () => {
      const { component, adapter, toast } = setup();
      fillStep2(component);
      component.reset_p.new_password = 'short';
      component.reset_p.confirm_password = 'short';
      component.reset_password();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_password_min_length', { position: 'top-center' });
    });

    it('guards password mismatch', () => {
      const { component, adapter, toast } = setup();
      fillStep2(component);
      component.reset_p.confirm_password = 'different1';
      component.reset_password();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_passwords_do_not_match', { position: 'top-center' });
    });

    it('confirms: navigates to /login, NO auto-login (does NOT go to /account)', () => {
      const { component, adapter, toast, navSpy } = setup();
      fillStep2(component);
      // v3 confirm returns tokens; the page must DISCARD them. The reset
      // page deliberately has NO cartMerge/pushManager/Preferences
      // collaborators in its success path — the only observable success
      // effect is a success toast + navigation to /login (NOT /account).
      adapter.post_v3.and.returnValue(ok({
        access_token: 'should-be-discarded',
        user: { id: 1 },
      }));

      component.reset_password();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/reset/confirm', {
        verification_id: 'reset-vid-1',
        code: '123456',
        new_password: 'newpass1',
      });
      expect(toast.success).toHaveBeenCalledWith('text_password_reset_success', { position: 'top-center' });
      expect(navSpy).toHaveBeenCalledWith(['/login'], { replaceUrl: true });
      // negative: must NOT auto-login into the account.
      expect(navSpy).not.toHaveBeenCalledWith(['/account'], { replaceUrl: true });
    });

    it('error envelope (OTP_VERIFICATION_FAILED): toast, no navigation', () => {
      const { component, adapter, toast, navSpy } = setup();
      fillStep2(component);
      adapter.post_v3.and.returnValue(err('OTP_VERIFICATION_FAILED', 'bad', 401));

      component.reset_password();

      expect(navSpy).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_otp_verification_failed', { position: 'top-center' });
    });

    it('rxjs error -> handleHttpError, no navigation', () => {
      const { component, adapter, toast, navSpy } = setup();
      fillStep2(component);
      adapter.post_v3.and.returnValue(throwError(() => ({ error: { error: { code: 'OTP_PROVIDER_ERROR' } } })));

      component.reset_password();

      expect(navSpy).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_otp_provider_error', { position: 'top-center' });
    });
  });

  // ----- resend_otp -------------------------------------------------------

  describe('resend_otp()', () => {
    it('guards offline', () => {
      const { component, adapter, toast } = setup();
      component.isOnline = false;
      component.resend_otp();
      expect(adapter.post_v3).not.toHaveBeenCalled();
      expect(toast.error).toHaveBeenCalledWith('text_offline_check_connection', { position: 'top-center' });
    });

    it('re-issues using resetBody (email channel), updates vid, success toast, no step change', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = 'jane@example.com';
      component.ui_controls.step = 2;
      adapter.post_v3.and.returnValue(ok({ verification_id: 'reset-vid-3' }));

      component.resend_otp();

      expect(adapter.post_v3).toHaveBeenCalledWith('POST /auth/reset', {
        channel: 'email',
        email: 'jane@example.com',
      });
      expect(component.verification_id).toBe('reset-vid-3');
      expect(component.ui_controls.step as number).toBe(2);
      expect(toast.success).toHaveBeenCalledWith('text_otp_sent', { position: 'top-center' });
    });

    it('error envelope: toast, no step change', () => {
      const { component, adapter, toast } = setup();
      component.ui_controls.channel = 'email';
      component.reset_p.email = 'jane@example.com';
      component.ui_controls.step = 2;
      adapter.post_v3.and.returnValue(err('OTP_RATE_LIMITED', 'slow', 429));

      component.resend_otp();

      expect(component.ui_controls.step as number).toBe(2);
      expect(toast.error).toHaveBeenCalledWith('text_otp_rate_limited', { position: 'top-center' });
    });
  });

  // ----- back_to_identifier -----------------------------------------------

  describe('back_to_identifier()', () => {
    it('returns to step 1 and clears code/passwords, keeps channel', () => {
      const { component } = setup();
      component.ui_controls.step = 2;
      component.ui_controls.channel = 'phone';
      component.reset_p.code = '123456';
      component.reset_p.new_password = 'newpass1';
      component.reset_p.confirm_password = 'newpass1';

      component.back_to_identifier();

      expect(component.ui_controls.step as number).toBe(1);
      expect(component.reset_p.code).toBe('');
      expect(component.reset_p.new_password).toBe('');
      expect(component.reset_p.confirm_password).toBe('');
      expect(component.ui_controls.channel).toBe('phone');
    });
  });

  // ----- nav helpers ------------------------------------------------------

  describe('nav helpers', () => {
    it('sign_in() navigates to /login', () => {
      const { component, navSpy } = setup();
      component.sign_in();
      expect(navSpy).toHaveBeenCalledWith(['/', 'login']);
    });

    it('user_register() navigates to /register', () => {
      const { component, navSpy } = setup();
      component.user_register();
      expect(navSpy).toHaveBeenCalledWith(['/', 'register']);
    });
  });
});
