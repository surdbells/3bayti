import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AddressFormComponent } from './address-form';
import { AddressService } from '../../core/addresses';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Address } from '../../core/addresses';

const V3_BASE = 'https://api-v3.3bayti.ae';

function makeAddress(overrides: Partial<Address> = {}): Address {
  return {
    id: 1,
    recipient_name: 'Jane Doe',
    recipient_phone: '+971501234567',
    emirate: 'Dubai',
    area: 'Jumeirah',
    street_address: '12 Beach Rd',
    building_details: 'Tower B',
    postal_code: '12345',
    label: 'Home',
    is_default_shipping: true,
    is_default_billing: false,
    created_at: '2026-05-19T00:00:00Z',
    updated_at: '2026-05-19T00:00:00Z',
    ...overrides,
  };
}

class StubToastService {
  errors: string[] = [];
  successes: string[] = [];
  error(msg: string): string { this.errors.push(msg); return msg; }
  success(msg: string): string { this.successes.push(msg); return msg; }
  show(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

/** Drain microtasks for chained async/await across runWithLoading + list(). */
async function drainMicrotasks(): Promise<void> {
  for (let i = 0; i < 8; i++) {
    await Promise.resolve();
  }
}

function setup(opts: { address?: Address | null } = {}): {
  fixture: ComponentFixture<AddressFormComponent>;
  component: AddressFormComponent;
  controller: HttpTestingController;
  toast: StubToastService;
} {
  TestBed.configureTestingModule({
    imports: [AddressFormComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      AddressService,
      { provide: ToastService, useValue: new StubToastService() },
    ],
  });

  const controller = TestBed.inject(HttpTestingController);
  const toast = TestBed.inject(ToastService) as unknown as StubToastService;
  const fixture = TestBed.createComponent(AddressFormComponent);
  fixture.componentInstance.address = opts.address ?? null;
  fixture.detectChanges();
  return { fixture, component: fixture.componentInstance, controller, toast };
}

describe('AddressFormComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('create mode', () => {
    it('renders empty fields and shows the default toggle', () => {
      const { fixture } = setup({ address: null });
      const name = fixture.nativeElement.querySelector('[data-testid="addr-name"]') as HTMLInputElement;
      expect(name.value).toBe('');
      expect(fixture.nativeElement.querySelector('[data-testid="addr-default"]')).not.toBeNull();
    });

    it('blocks submit when required fields are empty', async () => {
      const { fixture, controller, component } = setup({ address: null });
      const form = fixture.nativeElement.querySelector('[data-testid="address-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await Promise.resolve();
      controller.expectNone(`${V3_BASE}/v3/me/addresses`);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      expect((component as any).form.controls.recipient_name.touched).toBe(true);
    });

    it('POSTs the input when the form is valid', async () => {
      const { fixture, controller, component } = setup({ address: null });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.patchValue({
        label: 'Home',
        recipient_name: 'Jane',
        recipient_phone: '+971501234567',
        emirate: 'Dubai',
        area: 'JLT',
        street_address: '12 Beach Rd',
        is_default: true,
      });
      const formEl = fixture.nativeElement.querySelector('[data-testid="address-form"]') as HTMLFormElement;
      formEl.dispatchEvent(new Event('submit'));
      await Promise.resolve();

      const post = controller.expectOne(`${V3_BASE}/v3/me/addresses`);
      expect(post.request.method).toBe('POST');
      expect(post.request.body).toEqual({
        label: 'Home',
        recipient_name: 'Jane',
        recipient_phone: '+971501234567',
        emirate: 'Dubai',
        area: 'JLT',
        street_address: '12 Beach Rd',
        building_details: null,
        postal_code: null,
        is_default: true,
      });
    });

    it('emits (saved) with the created Address on success', async () => {
      const { fixture, controller, component } = setup({ address: null });
      let saved: Address | null = null;
      component.saved.subscribe(a => (saved = a));

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.patchValue({
        recipient_name: 'Jane',
        recipient_phone: '+971501234567',
        emirate: 'Dubai',
        area: 'JLT',
      });
      const formEl = fixture.nativeElement.querySelector('[data-testid="address-form"]') as HTMLFormElement;
      formEl.dispatchEvent(new Event('submit'));
      await drainMicrotasks();

      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush(makeAddress({ id: 42 }));
      /* The create() chain calls list() — drain microtasks to let
         that fire, then flush it. */
      await drainMicrotasks();
      controller.expectOne(`${V3_BASE}/v3/me/addresses`).flush({ addresses: [makeAddress({ id: 42 })] });
      await drainMicrotasks();

      expect(saved).not.toBeNull();
      expect(saved!.id).toBe(42);
    });

    it('toasts on network failure', async () => {
      const { fixture, controller, component, toast } = setup({ address: null });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.patchValue({
        recipient_name: 'Jane',
        recipient_phone: '+971501234567',
        emirate: 'Dubai',
        area: 'JLT',
      });
      const formEl = fixture.nativeElement.querySelector('[data-testid="address-form"]') as HTMLFormElement;
      formEl.dispatchEvent(new Event('submit'));
      await drainMicrotasks();

      controller.expectOne(`${V3_BASE}/v3/me/addresses`).error(new ProgressEvent('error'));
      await drainMicrotasks();

      expect(toast.errors).toContain('addresses.errors.network');
    });
  });

  describe('edit mode', () => {
    it('pre-fills the form from the input address', () => {
      const addr = makeAddress({ recipient_name: 'Bob', emirate: 'Sharjah' });
      const { fixture, component } = setup({ address: addr });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      expect((component as any).form.controls.recipient_name.value).toBe('Bob');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      expect((component as any).form.controls.emirate.value).toBe('Sharjah');

      /* Default toggle is hidden in edit mode. */
      expect(fixture.nativeElement.querySelector('[data-testid="addr-default"]')).toBeNull();
    });

    it('PUTs to /v3/me/addresses/:id when submitted', async () => {
      const addr = makeAddress({ id: 5 });
      const { fixture, controller, component } = setup({ address: addr });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.patchValue({ area: 'New Area' });
      const formEl = fixture.nativeElement.querySelector('[data-testid="address-form"]') as HTMLFormElement;
      formEl.dispatchEvent(new Event('submit'));
      await Promise.resolve();

      const put = controller.expectOne(`${V3_BASE}/v3/me/addresses/5`);
      expect(put.request.method).toBe('PUT');
      expect(put.request.body.area).toBe('New Area');
    });
  });

  describe('cancel', () => {
    it('emits (cancelled) when cancel is clicked', () => {
      const { fixture, component } = setup({ address: null });
      let cancelled = false;
      component.cancelled.subscribe(() => (cancelled = true));
      const cancelBtn = fixture.nativeElement.querySelector('[data-testid="addr-cancel"]') as HTMLButtonElement;
      cancelBtn.click();
      expect(cancelled).toBe(true);
    });
  });
});
