import { SimpleChange, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Subject, of, throwError } from 'rxjs';

import { ProductTryOnComponent } from './product-try-on.component';
import { TryOnAdminService, TryOnEligibility, TryOnProduct } from '../../../services/try-on-admin.service';
import { PermissionService } from '../../../services/permission.service';
import { HotToastService } from '../../../shared/toast/toast.service';
import { I18nService } from '../../../i18n.service';

const ELIGIBLE: TryOnEligibility = { eligible: true, reason: null };
const GIF: TryOnEligibility = { eligible: false, reason: 'unsupported_format' };

const product = (id: number, patch: Partial<TryOnProduct> = {}): TryOnProduct => ({
  id,
  slug: `abaya-${id}`,
  name: `Abaya ${id}`,
  status: 'active',
  vendor: null,
  primary_image_url: null,
  try_on_enabled: false,
  eligibility: ELIGIBLE,
  ...patch,
});

function setup(opts: { canEdit?: boolean; svcAvailable?: boolean } = {}) {
  const available = signal(opts.svcAvailable ?? false);
  const svc = {
    available,
    ensureStatus: jasmine.createSpy('ensureStatus'),
    setEnabled: jasmine.createSpy('setEnabled'),
    bumpEnabledCount: jasmine.createSpy('bumpEnabledCount'),
  };
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  const canEdit = opts.canEdit ?? true;
  TestBed.configureTestingModule({
    providers: [
      { provide: TryOnAdminService, useValue: svc },
      { provide: PermissionService, useValue: { can: (p: string) => canEdit || p !== 'products.edit' } },
      { provide: HotToastService, useValue: toast },
      { provide: I18nService, useValue: { t: (k: string, p?: Record<string, unknown>) => (p ? `${k}${JSON.stringify(p)}` : k) } },
    ],
  });
  const component = TestBed.runInInjectionContext(() => new ProductTryOnComponent());
  return { component, svc, toast, available };
}

/** Set inputs the way the parent binds them and fire ngOnChanges. */
function bind(
  component: ProductTryOnComponent,
  inputs: { productId?: number; enabled?: boolean; available?: boolean | null; eligibility?: TryOnEligibility | null },
) {
  const changes: Record<string, SimpleChange> = {};
  for (const [k, v] of Object.entries(inputs)) {
    const prev = (component as any)[k];
    (component as any)[k] = v;
    changes[k] = new SimpleChange(prev, v, false);
  }
  component.ngOnChanges(changes);
}

describe('ProductTryOnComponent (logic)', () => {
  it('shows only while Try-On is available per the admin payload', () => {
    const { component, svc } = setup();
    bind(component, { productId: 42, enabled: false, available: true, eligibility: ELIGIBLE });
    expect(component.visible).toBeTrue();
    expect(svc.ensureStatus).not.toHaveBeenCalled();

    bind(component, { available: false });
    expect(component.visible).toBeFalse();
  });

  it('falls back to the cached status when the payload has no try_on_available', () => {
    const { component, svc, available } = setup();
    bind(component, { productId: 42, enabled: false, available: null, eligibility: null });
    expect(svc.ensureStatus).toHaveBeenCalled();
    expect(component.visible).toBeFalse(); // unknown → hidden
    available.set(true);
    expect(component.visible).toBeTrue();
  });

  it('enables optimistically and applies the server answer', () => {
    const { component, svc, toast } = setup();
    bind(component, { productId: 42, enabled: false, available: true, eligibility: ELIGIBLE });
    component.productName = 'Silk abaya';
    const reply = new Subject<TryOnProduct>();
    svc.setEnabled.and.returnValue(reply);

    component.toggle();
    expect(svc.setEnabled).toHaveBeenCalledOnceWith(42, true);
    expect(component.isOn).toBeTrue();
    expect(component.busy).toBeTrue();
    expect(component.state.disabled).toBeTrue();

    reply.next(product(42, { try_on_enabled: true }));
    reply.complete();
    expect(component.isOn).toBeTrue();
    expect(component.busy).toBeFalse();
    expect(svc.bumpEnabledCount).toHaveBeenCalledWith(1);
    expect(toast.success).toHaveBeenCalledWith('tryon.toggle.enabled{"name":"Silk abaya"}');
  });

  it('rolls back a refused enable and shows the reason the API gave', () => {
    const { component, svc, toast } = setup();
    bind(component, { productId: 42, enabled: false, available: true, eligibility: null });
    svc.setEnabled.and.returnValue(throwError(() => ({
      status: 422,
      error: { error: { code: 'TRYON_IMAGE_INELIGIBLE', message: 'Image is hosted elsewhere.', details: { reason: 'external_image' } } },
    })));

    component.toggle();

    expect(component.isOn).toBeFalse();
    expect(component.currentEligibility).toEqual({ eligible: false, reason: 'external_image' });
    expect(component.state).toEqual({ disabled: true, hintKey: 'tryon.reason.external_image' });
    // the localized reason, not the API's English-only message
    expect(toast.error).toHaveBeenCalledWith('tryon.reason.external_image');
  });

  it('locks enabling an ineligible image but allows disabling it', () => {
    const { component, svc } = setup();
    bind(component, { productId: 42, enabled: false, available: true, eligibility: GIF });
    component.toggle();
    expect(svc.setEnabled).not.toHaveBeenCalled();
    expect(component.eligibilityMessage).toBe('tryon.reason.unsupported_format');

    bind(component, { enabled: true });
    expect(component.enabledButBlocked).toBeTrue();
    svc.setEnabled.and.returnValue(of(product(42, { try_on_enabled: false, eligibility: GIF })));
    component.toggle();
    expect(svc.setEnabled).toHaveBeenCalledOnceWith(42, false);
    expect(component.isOn).toBeFalse();
    expect(svc.bumpEnabledCount).toHaveBeenCalledWith(-1);
  });

  it('is read-only without products.edit', () => {
    const { component, svc } = setup({ canEdit: false });
    bind(component, { productId: 42, enabled: true, available: true, eligibility: ELIGIBLE });
    component.toggle();
    expect(svc.setEnabled).not.toHaveBeenCalled();
    expect(component.toggleHint).toBe('tryon.toggle.read_only');
  });

  it('ignores a save that finishes after switching to another product', () => {
    const { component, svc, toast } = setup();
    bind(component, { productId: 42, enabled: false, available: true, eligibility: ELIGIBLE });
    const reply = new Subject<TryOnProduct>();
    svc.setEnabled.and.returnValue(reply);
    component.toggle();

    bind(component, { productId: 43, enabled: false, eligibility: GIF });
    reply.next(product(42, { try_on_enabled: true }));
    reply.complete();

    expect(component.isOn).toBeFalse();
    expect(component.busy).toBeFalse();
    expect(component.currentEligibility).toEqual(GIF);
    expect(toast.success).not.toHaveBeenCalled();
  });

  it('keeps the optimistic state while saving, then follows new payload values', () => {
    const { component, svc } = setup();
    bind(component, { productId: 42, enabled: false, available: true, eligibility: ELIGIBLE });
    svc.setEnabled.and.returnValue(new Subject<TryOnProduct>());
    component.toggle();
    // a parent refresh mid-save doesn't clobber the in-flight state
    bind(component, { enabled: false });
    expect(component.isOn).toBeTrue();
  });
});
