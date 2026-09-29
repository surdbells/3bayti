import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of, throwError } from 'rxjs';
import { HttpErrorResponse } from '@angular/common/http';

import {
  ResetVendorPasswordDialogComponent,
  ResetVendorPasswordDialogData,
  ResetVendorPasswordResult,
} from './reset-vendor-password-dialog.component';
import { AX_MODAL_DATA, AxModalRef } from '../../../shared/overlays/ax-modal.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { I18nService } from '../../../i18n.service';

describe('ResetVendorPasswordDialogComponent', () => {
  let fixture: ComponentFixture<ResetVendorPasswordDialogComponent>;
  let component: ResetVendorPasswordDialogComponent;
  let adapter: jasmine.SpyObj<PortalCrudAdapter>;
  let modalRef: jasmine.SpyObj<AxModalRef<ResetVendorPasswordDialogComponent, ResetVendorPasswordResult>>;

  const data: ResetVendorPasswordDialogData = {
    vendorId: 42,
    storeName: 'Almas Fashion',
    ownerName: 'Mariam Haddad',
    ownerEmail: 'seller@bayti.example',
  };

  const result: ResetVendorPasswordResult = {
    vendor_id: 42,
    owner_user_id: 55,
    mode: 'generate',
    email: 'seller@bayti.example',
    email_sent: true,
    must_change_password: true,
    sessions_revoked: 2,
  };

  beforeEach(async () => {
    adapter = jasmine.createSpyObj<PortalCrudAdapter>('PortalCrudAdapter', ['post_v3']);
    modalRef = jasmine.createSpyObj('AxModalRef', ['close']);

    await TestBed.configureTestingModule({
      imports: [ResetVendorPasswordDialogComponent],
      providers: [
        { provide: AX_MODAL_DATA, useValue: data },
        { provide: AxModalRef, useValue: modalRef },
        { provide: PortalCrudAdapter, useValue: adapter },
        // Echo keys so assertions don't depend on loaded translation files.
        { provide: I18nService, useValue: { t: (key: string) => key } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ResetVendorPasswordDialogComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('defaults to generate mode and posts without a password', () => {
    adapter.post_v3.and.returnValue(of({ data: result }));
    component.reason = '  Locked out  ';

    component.submit();

    expect(component.mode).toBe('generate');
    expect(adapter.post_v3).toHaveBeenCalledOnceWith(
      'POST /admin/vendors/:id/reset-password',
      { mode: 'generate', reason: 'Locked out' },
      { params: { id: '42' } },
    );
    expect(modalRef.close).toHaveBeenCalledOnceWith(result);
  });

  it('omits an empty reason', () => {
    adapter.post_v3.and.returnValue(of({ data: result }));
    component.reason = '   ';

    component.submit();

    expect(adapter.post_v3.calls.mostRecent().args[1]).toEqual({ mode: 'generate' });
  });

  it('blocks manual mode until the password meets the policy', () => {
    component.mode = 'manual';
    component.onModeChange();

    expect(component.canSubmit).toBeFalse();
    expect(component.passwordError).toBe('vendor_reset_password.password_required');

    component.password = 'short';
    expect(component.passwordError).toBe('vendor_reset_password.password_too_short');

    component.password = 'x'.repeat(201);
    expect(component.passwordError).toBe('vendor_reset_password.password_too_long');

    component.submit();
    expect(adapter.post_v3).not.toHaveBeenCalled();
    expect(component.passwordTouched).toBeTrue();
  });

  it('sends the manual password untrimmed', () => {
    adapter.post_v3.and.returnValue(of({ data: { ...result, mode: 'manual' } }));
    component.mode = 'manual';
    component.onModeChange();
    component.password = ' Admin Chosen 9 ';

    component.submit();

    expect(adapter.post_v3.calls.mostRecent().args[1]).toEqual({ mode: 'manual', password: ' Admin Chosen 9 ' });
    expect(component.password).toBe('', 'secret cleared after success');
  });

  it('clears a typed password when switching back to generate', () => {
    component.mode = 'manual';
    component.onModeChange();
    component.password = 'brandN3wPass!';
    component.showPassword = true;

    component.mode = 'generate';
    component.onModeChange();

    expect(component.password).toBe('');
    expect(component.showPassword).toBeFalse();
  });

  it('shows the server error inline and stays open on failure', () => {
    adapter.post_v3.and.returnValue(throwError(() => new HttpErrorResponse({
      status: 422,
      error: { error: { message: 'This account is inactive.' } },
    })));

    component.submit();

    expect(component.submitting).toBeFalse();
    expect(component.serverError).toBeTruthy();
    expect(modalRef.close).not.toHaveBeenCalled();
  });

  it('closes with undefined on cancel', () => {
    component.cancel();
    expect(modalRef.close).toHaveBeenCalledOnceWith(undefined);
  });

  it('ignores cancel while a request is in flight', () => {
    component.submitting = true;
    component.cancel();
    expect(modalRef.close).not.toHaveBeenCalled();
  });
});
