import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, provideRouter, Router } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountOrderReturnPageComponent } from './account-order-return-page';
import { OrderService } from '../../core/orders';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type {
  Order, OrderItem, OrderAddress,
  SubmitReturnInput, ReturnRequestResponse,
} from '../../core/orders';

function makeAddr(): OrderAddress {
  return {
    first_name: 'Jane', last_name: 'Doe', phone: '+971501234567',
    email: 'jane@example.com', street: null, city: 'Dubai',
    state_province: 'JLT', country_code: 'AE', postal_code: null,
  };
}

function makeOrderItem(o: Partial<OrderItem> = {}): OrderItem {
  return {
    id: 1, product_id: 100, vendor_id: 5, product_name: 'Item',
    product_image: '', quantity: 1, unit_price: '100.00',
    subtotal: '100.00', size: null, color: null, is_custom: false,
    measurement: null, extra_measurement: null, note: null,
    item_status: 'delivered', store: 5, ...o,
  };
}

function makeOrder(o: Partial<Order> = {}): Order {
  return {
    id: 42, order_reference: 'BAYTI-2026-042', status: 'delivered',
    date: '2026-05-19T10:00:00Z', subtotal: '200.00',
    delivery_fee: '25.00', discount: '0.00', total: '225.00',
    currency: 'AED', paid_at: '2026-05-19T10:05:00Z',
    items: [makeOrderItem()], applied_promo: null,
    shipping_address: makeAddr(), billing_address: makeAddr(),
    ...o,
  } as Order;
}

class StubOrderService {
  isLoadingDetail = signal(false).asReadonly();
  getCalls: number[] = [];
  submitCalls: Array<{ id: number; input: SubmitReturnInput }> = [];
  orderResponse: Order = makeOrder();
  submitResponse: ReturnRequestResponse = {
    id: 5, status: 'pending', reason: 'defective',
    requested_at: '2026-05-20T00:00:00Z', item_count: 1,
  };
  shouldThrowGet = false;
  shouldThrowSubmit = false;
  submitErrorCode: string | null = null;

  async getById(id: number): Promise<Order> {
    this.getCalls.push(id);
    if (this.shouldThrowGet) throw new Error('get failed');
    return this.orderResponse;
  }
  async submitReturn(orderId: number, input: SubmitReturnInput): Promise<ReturnRequestResponse> {
    this.submitCalls.push({ id: orderId, input });
    if (this.shouldThrowSubmit) {
      if (this.submitErrorCode !== null) {
        /* Construct an HttpErrorResponse-shaped error so mapApiErrors
           can interpret it. */
        throw new HttpErrorResponse({
          status: 422, statusText: 'Unprocessable',
          url: '/v3/orders/x/returns',
          error: { error_code: this.submitErrorCode, message: 'err' },
        });
      }
      throw new Error('submit failed');
    }
    return this.submitResponse;
  }
}

class StubToastService {
  errors: string[] = [];
  successes: string[] = [];
  error(m: string): string { this.errors.push(m); return m; }
  success(m: string): string { this.successes.push(m); return m; }
  show(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function setup(opts: {
  routeId?: string | null;
  order?: Order;
  shouldThrowGet?: boolean;
  shouldThrowSubmit?: boolean;
  submitErrorCode?: string | null;
} = {}): {
  fixture: ComponentFixture<AccountOrderReturnPageComponent>;
  orderService: StubOrderService;
  toast: StubToastService;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const orderService = new StubOrderService();
  if (opts.order !== undefined) orderService.orderResponse = opts.order;
  if (opts.shouldThrowGet === true) orderService.shouldThrowGet = true;
  if (opts.shouldThrowSubmit === true) orderService.shouldThrowSubmit = true;
  if (opts.submitErrorCode !== undefined && opts.submitErrorCode !== null) {
    orderService.submitErrorCode = opts.submitErrorCode;
    orderService.shouldThrowSubmit = true;
  }

  const idParam = opts.routeId !== undefined ? opts.routeId : '42';
  const activatedRouteStub = {
    snapshot: {
      paramMap: {
        get: (key: string): string | null =>
          idParam !== null && key === 'id' ? idParam : null,
      },
    },
  };

  TestBed.configureTestingModule({
    imports: [AccountOrderReturnPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: OrderService, useValue: orderService },
      { provide: ToastService, useValue: new StubToastService() },
      { provide: ActivatedRoute, useValue: activatedRouteStub },
    ],
  });

  const router = TestBed.inject(Router);
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const toast = TestBed.inject(ToastService) as unknown as StubToastService;
  const fixture = TestBed.createComponent(AccountOrderReturnPageComponent);
  fixture.detectChanges();
  return { fixture, orderService, toast, navigateByUrlSpy };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 6; i++) await Promise.resolve();
}

describe('AccountOrderReturnPageComponent', () => {
  /** Build a FileList-like array from File[] since jsdom lacks DataTransfer. */
  function makeFileList(files: File[]): FileList {
    const list: FileList = {
      length: files.length,
      item: (i: number) => files[i] ?? null,
      [Symbol.iterator]: function*() { for (const f of files) yield f; },
    } as unknown as FileList;
    for (let i = 0; i < files.length; i++) {
      Object.defineProperty(list, i, { value: files[i], enumerable: true });
    }
    return list;
  }

  function setFiles(input: HTMLInputElement, files: File[]): void {
    Object.defineProperty(input, 'files', {
      value: makeFileList(files),
      configurable: true,
    });
    input.dispatchEvent(new Event('change'));
  }

  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('loading the order', () => {
    it('fetches the order on init', async () => {
      const { orderService } = setup({ routeId: '7' });
      await flush();
      expect(orderService.getCalls).toEqual([7]);
    });

    it('renders the form once order loaded', async () => {
      const { fixture } = setup({});
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-form"]')).not.toBeNull();
    });

    it('renders one row per item', async () => {
      const { fixture } = setup({
        order: makeOrder({
          items: [makeOrderItem({ id: 1 }), makeOrderItem({ id: 2 })],
        }),
      });
      await flush();
      fixture.detectChanges();
      const items = fixture.nativeElement.querySelectorAll('[data-testid="return-item"]');
      expect(items).toHaveLength(2);
    });

    it('shows error when fetch fails', async () => {
      const { fixture } = setup({ shouldThrowGet: true });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-error"]')).not.toBeNull();
    });
  });

  describe('item selection', () => {
    it('clicking a checkbox toggles its selected state', async () => {
      const { fixture } = setup({
        order: makeOrder({ items: [makeOrderItem({ id: 11 })] }),
      });
      await flush();
      fixture.detectChanges();
      const cb = fixture.nativeElement.querySelector('input[type=checkbox]') as HTMLInputElement;
      cb.click();
      fixture.detectChanges();
      const item = fixture.nativeElement.querySelector('[data-testid="return-item"]');
      expect(item?.classList.contains('return-item--selected')).toBe(true);
      cb.click();
      fixture.detectChanges();
      expect(item?.classList.contains('return-item--selected')).toBe(false);
    });
  });

  describe('submit validation', () => {
    it('shows item-required error when no items selected', async () => {
      const { fixture } = setup({});
      await flush();
      fixture.detectChanges();
      const form = fixture.nativeElement.querySelector('[data-testid="return-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-items-error"]')).not.toBeNull();
    });

    it('does not call submitReturn when no items selected', async () => {
      const { fixture, orderService } = setup({});
      await flush();
      fixture.detectChanges();
      const form = fixture.nativeElement.querySelector('[data-testid="return-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await flush();
      expect(orderService.submitCalls).toEqual([]);
    });

    it("requires notes when reason is 'other'", async () => {
      const { fixture, orderService } = setup({
        order: makeOrder({ items: [makeOrderItem({ id: 1 })] }),
      });
      await flush();
      fixture.detectChanges();
      /* Select an item. */
      const cb = fixture.nativeElement.querySelector('input[type=checkbox]') as HTMLInputElement;
      cb.click();
      fixture.detectChanges();
      /* Set reason='other' via the component instance — Angular
         ReactiveForms-driven radios don't always surface a value
         attribute that querySelector('input[value="other"]') can
         match in jsdom, so we drive the form control directly. */
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (fixture.componentInstance as any).form.controls.reason.setValue('other');
      fixture.detectChanges();
      /* Submit without notes. */
      const form = fixture.nativeElement.querySelector('[data-testid="return-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      fixture.detectChanges();
      /* submitReturn should not be called because the form is invalid. */
      await flush();
      expect(orderService.submitCalls).toEqual([]);
      expect(fixture.nativeElement.querySelector('[data-testid="return-notes-error"]')).not.toBeNull();
    });
  });

  describe('photo handling', () => {
    it('adds files to the photo list on input change', async () => {
      const { fixture } = setup({});
      await flush();
      fixture.detectChanges();
      const fileInput = fixture.nativeElement.querySelector('[data-testid="return-photos-input"]') as HTMLInputElement;
      const file = new File(['x'.repeat(100)], 'p1.jpg', { type: 'image/jpeg' });
      setFiles(fileInput, [file]);
      fixture.detectChanges();
      const photos = fixture.nativeElement.querySelectorAll('[data-testid="return-photo"]');
      expect(photos).toHaveLength(1);
    });

    it('rejects files larger than the per-photo limit', async () => {
      const { fixture, toast } = setup({});
      await flush();
      fixture.detectChanges();
      const fileInput = fixture.nativeElement.querySelector('[data-testid="return-photos-input"]') as HTMLInputElement;
      /* 6 MB file. */
      const big = new File([new Uint8Array(6 * 1024 * 1024)], 'big.jpg', { type: 'image/jpeg' });
      setFiles(fileInput, [big]);
      fixture.detectChanges();
      expect(toast.errors).toContain('orders.returns.errors.photoTooLarge');
      expect(fixture.nativeElement.querySelector('[data-testid="return-photo"]')).toBeNull();
    });

    it('rejects when adding would exceed the 5-photo cap', async () => {
      const { fixture, toast } = setup({});
      await flush();
      fixture.detectChanges();
      const fileInput = fixture.nativeElement.querySelector('[data-testid="return-photos-input"]') as HTMLInputElement;
      /* Add 6 small files at once. */
      const files: File[] = [];
      for (let i = 0; i < 6; i++) {
        files.push(new File(['x'], `p${i}.jpg`, { type: 'image/jpeg' }));
      }
      setFiles(fileInput, files);
      fixture.detectChanges();
      expect(toast.errors).toContain('orders.returns.errors.tooManyPhotos');
    });

    it('remove (×) button removes a photo from the list', async () => {
      const { fixture } = setup({});
      await flush();
      fixture.detectChanges();
      const fileInput = fixture.nativeElement.querySelector('[data-testid="return-photos-input"]') as HTMLInputElement;
      const file = new File(['x'], 'p.jpg', { type: 'image/jpeg' });
      setFiles(fileInput, [file]);
      fixture.detectChanges();
      const removeBtn = fixture.nativeElement.querySelector('[data-testid="return-photo-remove"]') as HTMLButtonElement;
      removeBtn.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="return-photo"]')).toBeNull();
    });
  });

  describe('successful submission', () => {
    it('calls submitReturn with the full payload', async () => {
      const { fixture, orderService } = setup({
        order: makeOrder({ id: 99, items: [makeOrderItem({ id: 1 }), makeOrderItem({ id: 2 })] }),
      });
      await flush();
      fixture.detectChanges();

      /* Select items. */
      const checkboxes = fixture.nativeElement.querySelectorAll('input[type=checkbox]') as NodeListOf<HTMLInputElement>;
      checkboxes[0].click();
      checkboxes[1].click();
      fixture.detectChanges();

      /* Reason: defective is the default. */
      /* Submit. */
      const form = fixture.nativeElement.querySelector('[data-testid="return-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await flush();

      expect(orderService.submitCalls).toHaveLength(1);
      const call = orderService.submitCalls[0];
      expect(call.id).toBe(99);
      expect(call.input.reason).toBe('defective');
      expect(call.input.order_item_ids).toEqual([1, 2]);
      expect(call.input.photos).toEqual([]);
    });

    it('toasts success and navigates back to detail on success', async () => {
      const { fixture, toast, navigateByUrlSpy } = setup({
        order: makeOrder({ id: 99 }),
      });
      await flush();
      fixture.detectChanges();
      /* Select an item. */
      (fixture.nativeElement.querySelector('input[type=checkbox]') as HTMLInputElement).click();
      fixture.detectChanges();
      const form = fixture.nativeElement.querySelector('[data-testid="return-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await flush();
      expect(toast.successes).toContain('orders.returns.success');
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/account/orders/99');
    });
  });

  describe('failed submission', () => {
    it('toasts networkError on non-HTTP failure', async () => {
      const { fixture, toast } = setup({
        shouldThrowSubmit: true,
      });
      await flush();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('input[type=checkbox]') as HTMLInputElement).click();
      fixture.detectChanges();
      const form = fixture.nativeElement.querySelector('[data-testid="return-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await flush();
      expect(toast.errors).toContain('orders.returns.errors.networkError');
    });

    it('toasts submitFailed when error_code is unmapped', async () => {
      const { fixture, toast } = setup({
        submitErrorCode: 'UNKNOWN_ERROR_CODE',
      });
      await flush();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('input[type=checkbox]') as HTMLInputElement).click();
      fixture.detectChanges();
      const form = fixture.nativeElement.querySelector('[data-testid="return-form"]') as HTMLFormElement;
      form.dispatchEvent(new Event('submit'));
      await flush();
      expect(toast.errors).toContain('orders.returns.errors.submitFailed');
    });
  });
});
