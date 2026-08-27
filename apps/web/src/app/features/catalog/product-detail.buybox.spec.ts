import { describe, it, expect, beforeEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { PLATFORM_ID, signal } from '@angular/core';
import { of } from 'rxjs';

import { ProductDetailComponent } from './product-detail';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SeoService } from '../../core/seo/seo.service';
import { RecommendationsService } from './recommendations.service';
import { CartService } from '../../core/cart/cart.service';
import { CartDrawerService } from '../../core/cart/cart-drawer.service';
import { AuthService } from '../../core/auth/auth.service';
import { MeasurementService } from '../account/measurement.service';
import { provideI18n } from '../../core/i18n';
import type { ProductDetail } from './product.model';

/**
 * Buy-box coverage for the PDP (Phase A). Exercises variant selection,
 * quantity, the validity/gating computeds, and the add-to-cart handler
 * (success, failure, in-flight, and no-op paths) against the real cart
 * contract. The product is driven through the component's route → fetch
 * pipeline exactly as it is at runtime.
 */
function makeProduct(overrides: Partial<ProductDetail> = {}): ProductDetail {
  return {
    id: 100,
    slug: 'abaya-01',
    name: 'Test Abaya',
    price: { amount: 530, currency: 'AED' },
    sale_price: null,
    primary_image: null,
    in_stock: true,
    description: '',
    images: [],
    sizes: [
      { label: 'S', in_stock: true },
      { label: 'M', in_stock: true },
      { label: 'L', in_stock: false },
    ],
    colors: [
      { label: 'Black', in_stock: true },
      { label: 'Red', in_stock: false },
    ],
    ...overrides,
  } as ProductDetail;
}

let addItemMock: ReturnType<typeof vi.fn>;
let openDrawerMock: ReturnType<typeof vi.fn>;

interface SetupOpts {
  authenticated?: boolean;
  savedMeasurement?: Record<string, number> | null;
}

function setup(
  product: ProductDetail = makeProduct(),
  opts: SetupOpts = {},
): ComponentFixture<ProductDetailComponent> {
  addItemMock = vi.fn(() => Promise.resolve({}));
  openDrawerMock = vi.fn();

  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ActivatedRoute, useValue: { paramMap: of(convertToParamMap({ slug: product.slug })) } },
      { provide: RoutedHttpClient, useValue: { get: vi.fn(() => of({ data: product })) } },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
      { provide: RecommendationsService, useValue: { forProduct: vi.fn(() => Promise.resolve([])) } },
      { provide: CartService, useValue: { addItem: addItemMock } },
      { provide: CartDrawerService, useValue: { open: openDrawerMock } },
      { provide: AuthService, useValue: { isAuthenticated: signal(opts.authenticated ?? false).asReadonly() } },
      {
        provide: MeasurementService,
        useValue: {
          getDefault: vi.fn(() =>
            Promise.resolve(opts.savedMeasurement ? { values: opts.savedMeasurement } : null),
          ),
        },
      },
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });

  const fixture = TestBed.createComponent(ProductDetailComponent);
  fixture.detectChanges();
  return fixture;
}

describe('ProductDetail buy box', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
  });

  it('loads the product through the route pipeline', () => {
    const c = setup().componentInstance;
    expect(c.product()?.id).toBe(100);
  });

  describe('variant selection', () => {
    it('selects an in-stock size and toggles it off on reselect', () => {
      const c = setup().componentInstance;
      c.selectSize({ label: 'M', in_stock: true });
      expect(c.selectedSize()).toBe('M');
      c.selectSize({ label: 'M', in_stock: true });
      expect(c.selectedSize()).toBeNull();
    });

    it('ignores an out-of-stock size', () => {
      const c = setup().componentInstance;
      c.selectSize({ label: 'L', in_stock: false });
      expect(c.selectedSize()).toBeNull();
    });

    it('selects an in-stock colour and ignores an out-of-stock one', () => {
      const c = setup().componentInstance;
      c.selectColor({ label: 'Black', in_stock: true });
      expect(c.selectedColor()).toBe('Black');
      c.selectColor({ label: 'Red', in_stock: false });
      expect(c.selectedColor()).toBe('Black');
    });
  });

  describe('quantity stepper', () => {
    it('clamps between 1 and 99', () => {
      const c = setup().componentInstance;
      expect(c.quantity()).toBe(1);
      c.decrementQuantity();
      expect(c.quantity()).toBe(1); // floor
      c.incrementQuantity();
      expect(c.quantity()).toBe(2);
      for (let i = 0; i < 200; i++) c.incrementQuantity();
      expect(c.quantity()).toBe(99); // ceiling
    });
  });

  describe('selectionValid + canAddToCart', () => {
    it('requires an in-stock selection on every present axis', () => {
      const c = setup().componentInstance;
      expect(c.selectionValid()).toBe(false);
      c.selectSize({ label: 'M', in_stock: true });
      expect(c.selectionValid()).toBe(false); // colour still unselected
      c.selectColor({ label: 'Black', in_stock: true });
      expect(c.selectionValid()).toBe(true);
      expect(c.canAddToCart()).toBe(true);
    });

    it('rejects a stale selection not present in the current product', () => {
      const c = setup().componentInstance;
      c.selectedSize.set('XXL'); // not an option on this product
      c.selectColor({ label: 'Black', in_stock: true });
      expect(c.selectionValid()).toBe(false);
    });

    it('is valid with no variant axes (simple product)', () => {
      const c = setup(makeProduct({ sizes: [], colors: [] })).componentInstance;
      expect(c.selectionValid()).toBe(true);
      expect(c.canAddToCart()).toBe(true);
    });

    it('cannot add when the product is out of stock', () => {
      const c = setup(makeProduct({ in_stock: false, sizes: [], colors: [] })).componentInstance;
      expect(c.canAddToCart()).toBe(false);
    });
  });

  describe('addToCart', () => {
    it('sends the exact cart payload and opens the drawer on success', async () => {
      const c = setup().componentInstance;
      c.selectSize({ label: 'M', in_stock: true });
      c.selectColor({ label: 'Black', in_stock: true });
      c.incrementQuantity(); // 2
      await c.addToCart();
      expect(addItemMock).toHaveBeenCalledWith({
        product_id: 100,
        quantity: 2,
        size: 'M',
        color: 'Black',
        is_custom: false,
        measurement: null,
        extra_measurement: null,
      });
      expect(openDrawerMock).toHaveBeenCalledOnce();
      expect(c.adding()).toBe(false);
      expect(c.addError()).toBeNull();
    });

    it('sends null variant axes for a simple product', async () => {
      const c = setup(makeProduct({ sizes: [], colors: [] })).componentInstance;
      await c.addToCart();
      expect(addItemMock).toHaveBeenCalledWith({
        product_id: 100,
        quantity: 1,
        size: null,
        color: null,
        is_custom: false,
        measurement: null,
        extra_measurement: null,
      });
    });

    it('surfaces an error and does not open the drawer on failure', async () => {
      const c = setup().componentInstance;
      addItemMock.mockRejectedValueOnce(new Error('network'));
      c.selectSize({ label: 'M', in_stock: true });
      c.selectColor({ label: 'Black', in_stock: true });
      await c.addToCart();
      expect(c.addError()).toBeTruthy();
      expect(openDrawerMock).not.toHaveBeenCalled();
      expect(c.adding()).toBe(false);
    });

    it('does nothing when the selection is invalid', async () => {
      const c = setup().componentInstance;
      await c.addToCart(); // nothing selected
      expect(addItemMock).not.toHaveBeenCalled();
    });

    it('reflects an in-flight state while the request is pending', async () => {
      const c = setup().componentInstance;
      let resolveAdd!: (v: unknown) => void;
      addItemMock.mockReturnValueOnce(new Promise((res) => (resolveAdd = res)));
      c.selectSize({ label: 'M', in_stock: true });
      c.selectColor({ label: 'Black', in_stock: true });
      const pending = c.addToCart();
      expect(c.adding()).toBe(true);
      expect(c.canAddToCart()).toBe(false); // disabled while adding
      resolveAdd({});
      await pending;
      expect(c.adding()).toBe(false);
    });
  });

  describe('extra measurement', () => {
    it('blocks add-to-cart until the extra measurement is entered', () => {
      const c = setup(makeProduct({ requires_measurement: true, sizes: [], colors: [] })).componentInstance;
      expect(c.requiresExtraMeasurement()).toBe(true);
      expect(c.selectionValid()).toBe(false);
      expect(c.canAddToCart()).toBe(false);

      c.extraMeasurement.set('Length 56 inches');
      expect(c.selectionValid()).toBe(true);
      expect(c.canAddToCart()).toBe(true);
    });

    it('treats a whitespace-only extra measurement as empty', () => {
      const c = setup(makeProduct({ requires_measurement: true, sizes: [], colors: [] })).componentInstance;
      c.extraMeasurement.set('   ');
      expect(c.selectionValid()).toBe(false);
    });

    it('sends the trimmed extra_measurement on add-to-cart', async () => {
      const c = setup(makeProduct({ requires_measurement: true, sizes: [], colors: [] })).componentInstance;
      c.extraMeasurement.set('  Length 56 inches  ');
      await c.addToCart();
      expect(addItemMock).toHaveBeenCalledWith({
        product_id: 100,
        quantity: 1,
        size: null,
        color: null,
        is_custom: false,
        measurement: null,
        extra_measurement: 'Length 56 inches',
      });
    });
  });

  describe('custom size', () => {
    const customProduct = (): ProductDetail =>
      makeProduct({ sizes: [{ label: 'CUSTOM', in_stock: true }], colors: [] });

    function fillMeasurements(c: ProductDetailComponent): void {
      for (const f of c.measurementFields) {
        c.onCustomMeasurementInput(f, { target: { value: '80' } } as unknown as Event);
      }
    }

    it('blocks add-to-cart and shows a sign-in prompt when not signed in', () => {
      const fix = setup(customProduct(), { authenticated: false });
      const c = fix.componentInstance;
      c.selectSize({ label: 'CUSTOM', in_stock: true });
      fix.detectChanges();
      expect(c.isCustomSize()).toBe(true);
      expect(c.canAddToCart()).toBe(false);
      expect(
        (fix.nativeElement as HTMLElement).querySelector('[data-testid="pdp-custom-signin"]'),
      ).not.toBeNull();
    });

    it('requires a complete measurement before add-to-cart when signed in', () => {
      const c = setup(customProduct(), { authenticated: true }).componentInstance;
      c.selectSize({ label: 'CUSTOM', in_stock: true });
      expect(c.canAddToCart()).toBe(false);
      fillMeasurements(c);
      expect(c.customMeasurementComplete()).toBe(true);
      expect(c.canAddToCart()).toBe(true);
    });

    it('snapshots is_custom + the measurement JSON on add-to-cart', async () => {
      const c = setup(customProduct(), { authenticated: true }).componentInstance;
      c.selectSize({ label: 'CUSTOM', in_stock: true });
      fillMeasurements(c);
      await c.addToCart();

      const payload = addItemMock.mock.calls[0][0] as Record<string, unknown>;
      expect(payload['is_custom']).toBe(true);
      expect(payload['size']).toBe('CUSTOM');
      const snap = JSON.parse(payload['measurement'] as string);
      expect(snap.bust_chest).toBe(80);
      expect(Object.keys(snap)).toHaveLength(c.measurementFields.length);
    });

    it('prefills the form from the saved account default', async () => {
      const fix = setup(customProduct(), {
        authenticated: true,
        savedMeasurement: { bust_chest: 92, waist: 74, hips: 96, shoulder: 38, sleeve_length: 58, height: 165 },
      });
      const c = fix.componentInstance;
      c.selectSize({ label: 'CUSTOM', in_stock: true });
      fix.detectChanges();   // runs the prefill effect -> getDefault()
      await Promise.resolve();
      await Promise.resolve();
      expect(c.customMeasurementValue('bust_chest')).toBe('92');
      expect(c.customMeasurementComplete()).toBe(true);
    });
  });

  describe('size-optional categories', () => {
    it('does not require a size (even with CUSTOM available) for an exempt category', () => {
      const c = setup(
        makeProduct({
          category_slug: 'kaftans-3',
          sizes: [
            { label: 'M', in_stock: true },
            { label: 'CUSTOM', in_stock: true },
          ],
          colors: [],
        }),
        { authenticated: false },
      ).componentInstance;
      expect(c.isSizeOptional()).toBe(true);
      // No size selected, not signed in, still addable.
      expect(c.canAddToCart()).toBe(true);
    });

    it('allows CUSTOM in an exempt category without measurements or sign-in', () => {
      const c = setup(
        makeProduct({
          category_slug: 'bags-2',
          sizes: [{ label: 'CUSTOM', in_stock: true }],
          colors: [],
        }),
        { authenticated: false },
      ).componentInstance;
      c.selectSize({ label: 'CUSTOM', in_stock: true });
      expect(c.isCustomSize()).toBe(true);
      expect(c.isSizeOptional()).toBe(true);
      expect(c.canAddToCart()).toBe(true);
    });
  });
});
