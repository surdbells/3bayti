import { TestBed } from '@angular/core/testing';
import { ActivatedRoute } from '@angular/router';
import { Location } from '@angular/common';

import { AdminViewProductComponent } from './admin-view-product.component';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { ImageUploadService } from '../../services/image-upload.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { AxConfirmService } from '../../shared/overlays';

/**
 * How the admin product page feeds the Virtual Try-On card from the admin
 * detail payload (no component compilation): the RAW stored flag (never gated
 * by availability), `try_on_available` (null when the payload predates it) and
 * `try_on_eligibility`; the product form's save never sends the flag.
 */
function setup(): AdminViewProductComponent {
  TestBed.configureTestingModule({
    providers: [{ provide: AxConfirmService, useValue: {} }],
  });
  const toast = jasmine.createSpyObj<HotToastService>('HotToastService', ['success', 'error']);
  return TestBed.runInInjectionContext(() => new AdminViewProductComponent(
    {} as ActivatedRoute,
    { back: () => {} } as unknown as Location,
    {} as PortalCrudAdapter,
    {} as ImageUploadService,
    toast,
  ));
}

/** Minimal admin detail shape (no store, so no label request). */
const detail = (extra: Record<string, unknown> = {}) => ({
  id: 42,
  name: 'Silk abaya',
  price: { amount: 450 },
  category_id: 3,
  vendor_id: 0,
  status: 'active',
  ...extra,
});

const apply = (component: AdminViewProductComponent, p: Record<string, unknown>) =>
  (component as any).applyAdminDetail(p);

describe('AdminViewProductComponent – Try-On card inputs (logic)', () => {
  it('maps the raw flag, availability and eligibility from the admin payload', () => {
    const component = setup();
    apply(component, detail({
      try_on_enabled: true,
      try_on_available: false,
      try_on_eligibility: { eligible: false, reason: 'unsupported_format' },
    }));

    // Raw flag stays true even while Try-On is unavailable and the image is a GIF.
    expect(component.tryOn).toEqual({
      enabled: true,
      available: false,
      eligibility: { eligible: false, reason: 'unsupported_format' },
    });
  });

  it('maps an available, eligible product', () => {
    const component = setup();
    apply(component, detail({
      try_on_enabled: false,
      try_on_available: true,
      try_on_eligibility: { eligible: true, reason: null },
    }));
    expect(component.tryOn).toEqual({ enabled: false, available: true, eligibility: { eligible: true, reason: null } });
  });

  it('treats a missing try_on_available / eligibility as unknown (null)', () => {
    const component = setup();
    apply(component, detail());
    expect(component.tryOn).toEqual({ enabled: false, available: null, eligibility: null });

    apply(component, detail({ try_on_enabled: 'yes', try_on_available: 'true', try_on_eligibility: { reason: 'no_image' } }));
    expect(component.tryOn).toEqual({ enabled: false, available: null, eligibility: null });
  });

  it('never sends the Try-On flag from the product form save', () => {
    const component = setup();
    apply(component, detail({ try_on_enabled: true, try_on_available: true, try_on_eligibility: { eligible: true, reason: null } }));

    const body: Record<string, unknown> = (component as any).buildUpdatePayload();
    expect(Object.keys(body).filter((k) => k.startsWith('try_on'))).toEqual([]);
  });
});
