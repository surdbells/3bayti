import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AddressBookPageComponent } from './address-book';
import { AddressService } from '../../core/addresses';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { Address } from '../../core/addresses';

function makeAddress(overrides: Partial<Address> = {}): Address {
  return {
    id: 1,
    recipient_name: 'Jane Doe',
    recipient_phone: '+971501234567',
    emirate: 'Dubai',
    area: 'Jumeirah',
    street_address: '12 Beach Rd',
    building_details: null,
    postal_code: null,
    label: 'Home',
    is_default_shipping: true,
    is_default_billing: false,
    created_at: '2026-05-19T00:00:00Z',
    updated_at: '2026-05-19T00:00:00Z',
    ...overrides,
  };
}

class StubAddressService {
  private _addresses = signal<Address[]>([]);
  private _isLoading = signal(false);
  addresses = this._addresses.asReadonly();
  isLoading = this._isLoading.asReadonly();
  defaultShipping = signal<Address | null>(null).asReadonly();
  defaultBilling = signal<Address | null>(null).asReadonly();
  hasAny = signal(false).asReadonly();

  listCalls = 0;
  setDefaultCalls: Array<{ id: number; input: unknown }> = [];
  deleteCalls: number[] = [];
  shouldThrowList = false;
  shouldThrowSetDefault = false;
  shouldThrowDelete = false;

  setAddresses(addrs: Address[]): void {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (this as any).addresses = signal<Address[]>(addrs).asReadonly();
  }

  async list(): Promise<Address[]> {
    this.listCalls++;
    if (this.shouldThrowList) throw new Error('list failed');
    return this.addresses();
  }
  async create(): Promise<Address> { return makeAddress(); }
  async update(): Promise<Address> { return makeAddress(); }
  async delete(id: number): Promise<void> {
    this.deleteCalls.push(id);
    if (this.shouldThrowDelete) throw new Error('delete failed');
  }
  async setDefault(id: number, input: unknown): Promise<Address> {
    this.setDefaultCalls.push({ id, input });
    if (this.shouldThrowSetDefault) throw new Error('set-default failed');
    return makeAddress({ id });
  }
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

function setup(opts: { addresses?: Address[] } = {}): {
  fixture: ComponentFixture<AddressBookPageComponent>;
  component: AddressBookPageComponent;
  addressService: StubAddressService;
  toast: StubToastService;
} {
  const addressService = new StubAddressService();
  if (opts.addresses !== undefined) addressService.setAddresses(opts.addresses);

  TestBed.configureTestingModule({
    imports: [AddressBookPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AddressService, useValue: addressService },
      { provide: ToastService, useValue: new StubToastService() },
    ],
  });

  const toast = TestBed.inject(ToastService) as unknown as StubToastService;
  const fixture = TestBed.createComponent(AddressBookPageComponent);
  fixture.detectChanges();
  return { fixture, component: fixture.componentInstance, addressService, toast };
}

describe('AddressBookPageComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('rendering', () => {
    it('calls list() on init', () => {
      const { addressService } = setup({ addresses: [] });
      expect(addressService.listCalls).toBeGreaterThanOrEqual(1);
    });

    it('renders the empty state when no addresses', () => {
      const { fixture } = setup({ addresses: [] });
      expect(fixture.nativeElement.querySelector('[data-testid="address-book-empty"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="address-card"]')).toBeNull();
    });

    it('renders one card per address when populated', () => {
      const { fixture } = setup({
        addresses: [makeAddress({ id: 1 }), makeAddress({ id: 2, label: 'Office' })],
      });
      const cards = fixture.nativeElement.querySelectorAll('[data-testid="address-card"]');
      expect(cards).toHaveLength(2);
    });

    it('shows the default badge on the default-shipping address', () => {
      const { fixture } = setup({
        addresses: [
          makeAddress({ id: 1, is_default_shipping: false }),
          makeAddress({ id: 2, is_default_shipping: true }),
        ],
      });
      const badges = fixture.nativeElement.querySelectorAll('[data-testid="address-default-badge"]');
      expect(badges).toHaveLength(1);
    });

    it('hides the set-default action on the default address', () => {
      const { fixture } = setup({
        addresses: [makeAddress({ id: 5, is_default_shipping: true })],
      });
      expect(fixture.nativeElement.querySelector('[data-testid="address-set-default-5"]')).toBeNull();
    });

    it('shows the set-default action on non-default addresses', () => {
      const { fixture } = setup({
        addresses: [
          makeAddress({ id: 1, is_default_shipping: true }),
          makeAddress({ id: 2, is_default_shipping: false }),
        ],
      });
      expect(fixture.nativeElement.querySelector('[data-testid="address-set-default-2"]')).not.toBeNull();
    });

    it('disables delete when there is only one address', () => {
      const { fixture } = setup({ addresses: [makeAddress({ id: 1 })] });
      const del = fixture.nativeElement.querySelector('[data-testid="address-delete-1"]') as HTMLButtonElement;
      expect(del.disabled).toBe(true);
    });

    it('enables delete when there are multiple addresses', () => {
      const { fixture } = setup({
        addresses: [makeAddress({ id: 1 }), makeAddress({ id: 2 })],
      });
      const del = fixture.nativeElement.querySelector('[data-testid="address-delete-1"]') as HTMLButtonElement;
      expect(del.disabled).toBe(false);
    });
  });

  describe('mode transitions', () => {
    it('clicking add CTA switches to create mode', () => {
      const { fixture } = setup({ addresses: [makeAddress()] });
      expect(fixture.nativeElement.querySelector('[data-testid="address-form"]')).toBeNull();

      const cta = fixture.nativeElement.querySelector('[data-testid="address-add-cta"]') as HTMLButtonElement;
      cta.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="address-form"]')).not.toBeNull();
    });

    it('clicking add from empty state opens the form', () => {
      const { fixture } = setup({ addresses: [] });
      const cta = fixture.nativeElement.querySelector('[data-testid="address-add-cta-empty"]') as HTMLButtonElement;
      cta.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="address-form"]')).not.toBeNull();
    });

    it('clicking edit on a card switches to edit mode', () => {
      const { fixture } = setup({ addresses: [makeAddress({ id: 7 })] });
      const edit = fixture.nativeElement.querySelector('[data-testid="address-edit-7"]') as HTMLButtonElement;
      edit.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="address-form"]')).not.toBeNull();
    });
  });

  describe('set-default action', () => {
    it('calls AddressService.setDefault with shipping:true', async () => {
      const { fixture, addressService } = setup({
        addresses: [
          makeAddress({ id: 1, is_default_shipping: true }),
          makeAddress({ id: 2, is_default_shipping: false }),
        ],
      });
      const btn = fixture.nativeElement.querySelector('[data-testid="address-set-default-2"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(addressService.setDefaultCalls).toEqual([{ id: 2, input: { shipping: true } }]);
    });

    it('toasts on set-default failure', async () => {
      const { fixture, addressService, toast } = setup({
        addresses: [
          makeAddress({ id: 1, is_default_shipping: true }),
          makeAddress({ id: 2, is_default_shipping: false }),
        ],
      });
      addressService.shouldThrowSetDefault = true;
      const btn = fixture.nativeElement.querySelector('[data-testid="address-set-default-2"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      await Promise.resolve();
      expect(toast.errors).toContain('addresses.errors.unexpected');
    });
  });

  describe('delete action', () => {
    it('confirms then calls AddressService.delete on confirm=true', async () => {
      const { fixture, addressService } = setup({
        addresses: [makeAddress({ id: 1 }), makeAddress({ id: 5 })],
      });
      const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
      const btn = fixture.nativeElement.querySelector('[data-testid="address-delete-5"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(confirmSpy).toHaveBeenCalled();
      expect(addressService.deleteCalls).toEqual([5]);
    });

    it('cancels delete on confirm=false', async () => {
      const { fixture, addressService } = setup({
        addresses: [makeAddress({ id: 1 }), makeAddress({ id: 5 })],
      });
      vi.spyOn(window, 'confirm').mockReturnValue(false);
      const btn = fixture.nativeElement.querySelector('[data-testid="address-delete-5"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      expect(addressService.deleteCalls).toEqual([]);
    });

    it('toasts on delete failure', async () => {
      const { fixture, addressService, toast } = setup({
        addresses: [makeAddress({ id: 1 }), makeAddress({ id: 5 })],
      });
      addressService.shouldThrowDelete = true;
      vi.spyOn(window, 'confirm').mockReturnValue(true);
      const btn = fixture.nativeElement.querySelector('[data-testid="address-delete-5"]') as HTMLButtonElement;
      btn.click();
      await Promise.resolve();
      await Promise.resolve();
      expect(toast.errors).toContain('addresses.errors.unexpected');
    });
  });

  describe('load failure', () => {
    it('toasts when list() fails on init', async () => {
      const addressService = new StubAddressService();
      addressService.shouldThrowList = true;

      TestBed.configureTestingModule({
        imports: [AddressBookPageComponent],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          provideI18n(),
          { provide: AddressService, useValue: addressService },
          { provide: ToastService, useValue: new StubToastService() },
        ],
      });

      const toast = TestBed.inject(ToastService) as unknown as StubToastService;
      const fixture = TestBed.createComponent(AddressBookPageComponent);
      fixture.detectChanges();
      await Promise.resolve();
      await Promise.resolve();
      expect(toast.errors).toContain('addresses.errors.loadFailed');
    });
  });
});
