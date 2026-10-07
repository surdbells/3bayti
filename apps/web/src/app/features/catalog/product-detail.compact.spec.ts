import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, ParamMap, Router, convertToParamMap, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { PLATFORM_ID, signal } from '@angular/core';
import { BehaviorSubject, of } from 'rxjs';

import { ProductDetailComponent } from './product-detail';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SeoService } from '../../core/seo/seo.service';
import { RecommendationsService } from './recommendations.service';
import { CompleteTheLookService, type CompleteLookItem, type CompleteLookResult } from './complete-the-look.service';
import { AnalyticsService } from '../../core/monitoring/analytics.service';
import { CartService } from '../../core/cart/cart.service';
import { CartDrawerService } from '../../core/cart/cart-drawer.service';
import { AuthService } from '../../core/auth/auth.service';
import { MeasurementService } from '../account/measurement.service';
import { provideI18n } from '../../core/i18n';
import type { Product, ProductDetail } from './product.model';
import en from '../../../../public/i18n/en.json';

/**
 * Compact / modern PDP coverage: required-selection hints that appear only
 * after an add-to-cart attempt, the sticky bottom bar, the seller-note
 * disclosure, two-row colour collapse (clipped from the first render), the
 * "Complete the look" strip under the buy area (and the same-size skeleton
 * that holds its slot while it loads, so nothing shifts), the Description /
 * Details accordions, the swipeable gallery (thumbnail row below the stage,
 * kept scrolled to the active image; arrows that stay focusable at the
 * ends), the #reviews link, and state reset + request de-duplication when
 * the router reuses the component for another product.
 */

/** jsdom has no IntersectionObserver. This stub records what each observer
 *  watches so a test can report an element as (not) on screen. */
class StubIntersectionObserver {
  static instances: StubIntersectionObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(private readonly callback: IntersectionObserverCallback) {
    StubIntersectionObserver.instances.push(this);
  }
  observe(target: Element): void { this.targets.add(target); }
  unobserve(target: Element): void { this.targets.delete(target); }
  disconnect(): void { this.targets.clear(); }
  takeRecords(): IntersectionObserverEntry[] { return []; }

  /** Deliver one entry for `target` to every observer watching it. */
  static emit(target: Element, entry: Partial<IntersectionObserverEntry>): void {
    for (const io of StubIntersectionObserver.instances) {
      if (!io.targets.has(target)) continue;
      const full = {
        target,
        isIntersecting: false,
        intersectionRatio: 0,
        boundingClientRect: target.getBoundingClientRect(),
        intersectionRect: target.getBoundingClientRect(),
        rootBounds: null,
        time: 0,
        ...entry,
      } as IntersectionObserverEntry;
      io.callback([full], io as unknown as IntersectionObserver);
    }
  }

  /** Report every observed element as visible (triggers @defer on viewport). */
  static revealAll(): void {
    for (const io of [...StubIntersectionObserver.instances]) {
      for (const target of [...io.targets]) {
        StubIntersectionObserver.emit(target, { isIntersecting: true, intersectionRatio: 1 });
      }
    }
  }
}

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
    ],
    colors: [
      { label: 'Black', in_stock: true },
      { label: 'Sand', in_stock: true },
    ],
    vendor: { slug: 'noor-atelier', name: 'Noor Atelier' },
    ...overrides,
  } as ProductDetail;
}

function makeComplement(id: number): CompleteLookItem {
  return {
    id,
    slug: `piece-${id}`,
    name: `Piece ${id}`,
    price: { amount: 150, currency: 'AED' },
    sale_price: null,
    primary_image: { url: `https://example.com/p${id}.jpg` },
    in_stock: true,
  } as CompleteLookItem;
}

function makeRelated(id: number): Product {
  return {
    id,
    slug: `rel-${id}`,
    name: `Related ${id}`,
    price: { amount: 90, currency: 'AED' },
    sale_price: null,
    primary_image: null,
    in_stock: true,
  } as Product;
}

interface Ctx {
  fixture: ComponentFixture<ProductDetailComponent>;
  c: ProductDetailComponent;
  el: HTMLElement;
  paramMap: BehaviorSubject<ParamMap>;
  get: ReturnType<typeof vi.fn>;
  addItem: ReturnType<typeof vi.fn>;
  openDrawer: ReturnType<typeof vi.fn>;
  ctl: { forProduct: ReturnType<typeof vi.fn>; recordEvent: ReturnType<typeof vi.fn> };
  recs: { forProduct: ReturnType<typeof vi.fn> };
  analytics: { event: ReturnType<typeof vi.fn> };
  /** With `deferLooks`: the pending "Complete the look" request per product id. */
  pendingLooks: Map<number, PendingLook>;
}

interface PendingLook {
  resolve: (result: CompleteLookResult) => void;
  reject: (error: unknown) => void;
}

interface SetupOpts {
  products?: ProductDetail[];
  looks?: Record<number, CompleteLookResult>;
  recs?: Product[];
  fragment?: string | null;
  /** Keep every "Complete the look" request pending until the test settles it. */
  deferLooks?: boolean;
  /** Return right after the FIRST render (no promise flush / second pass). */
  skipSettle?: boolean;
}

async function setup(opts: SetupOpts = {}): Promise<Ctx> {
  const products = opts.products ?? [makeProduct()];
  const bySlug = new Map(products.map((p) => [p.slug, p]));
  const paramMap = new BehaviorSubject<ParamMap>(convertToParamMap({ slug: products[0].slug }));
  const get = vi.fn((_route: string, req: { params: { slug: string } }) => of({ data: bySlug.get(req.params.slug) }));
  const addItem = vi.fn(() => Promise.resolve({}));
  const openDrawer = vi.fn();
  const pendingLooks = new Map<number, PendingLook>();
  const ctl = {
    forProduct: vi.fn((id: number) =>
      opts.deferLooks
        ? new Promise<CompleteLookResult>((resolve, reject) => pendingLooks.set(id, { resolve, reject }))
        : Promise.resolve(opts.looks?.[id] ?? ({ interactionId: null, items: [] } as CompleteLookResult)),
    ),
    recordEvent: vi.fn(),
  };
  const recs = { forProduct: vi.fn(() => Promise.resolve((opts.recs ?? []).map((product) => ({ product })))) };
  const analytics = { event: vi.fn() };

  TestBed.configureTestingModule({
    imports: [ProductDetailComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ActivatedRoute, useValue: { paramMap, snapshot: { fragment: opts.fragment ?? null } } },
      { provide: RoutedHttpClient, useValue: { get, post: vi.fn(() => of({ data: {} })) } },
      { provide: SeoService, useValue: { set: vi.fn(), setStructuredData: vi.fn() } },
      { provide: RecommendationsService, useValue: recs },
      { provide: CompleteTheLookService, useValue: ctl },
      { provide: AnalyticsService, useValue: analytics },
      { provide: CartService, useValue: { addItem } },
      { provide: CartDrawerService, useValue: { open: openDrawer } },
      { provide: AuthService, useValue: { isAuthenticated: signal(false).asReadonly() } },
      { provide: MeasurementService, useValue: { getDefault: vi.fn(() => Promise.resolve(null)) } },
      { provide: PLATFORM_ID, useValue: 'browser' },
    ],
  });
  await TestBed.compileComponents();
  const fixture = TestBed.createComponent(ProductDetailComponent);
  fixture.detectChanges();
  if (!opts.skipSettle) await settle(fixture);
  return {
    fixture,
    c: fixture.componentInstance,
    el: fixture.nativeElement as HTMLElement,
    paramMap,
    get,
    addItem,
    openDrawer,
    ctl,
    recs,
    analytics,
    pendingLooks,
  };
}

/** Let the promise-backed streams (recommendations, complete-the-look) land. */
async function settle(fixture: ComponentFixture<ProductDetailComponent>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
  fixture.detectChanges();
  await fixture.whenStable();
  fixture.detectChanges();
}

/** Flush resolved promises (cart adds) and re-render. */
async function flush(fixture: ComponentFixture<ProductDetailComponent>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0));
  fixture.detectChanges();
}

const q = <T extends Element = HTMLElement>(el: HTMLElement, sel: string): T | null => el.querySelector<T>(sel);
const addToCartButton = (el: HTMLElement) =>
  el.querySelector<HTMLButtonElement>('[data-testid="pdp-add-to-cart"] button')!;

/** Give the colour chips a fake flex-wrap layout: `perRow` chips per row. */
function layoutColorChips(el: HTMLElement, perRow: number): void {
  const chips = Array.from(el.querySelectorAll<HTMLElement>('[data-color-label]'));
  chips.forEach((chip, i) =>
    Object.defineProperty(chip, 'offsetTop', { configurable: true, get: () => 3 + Math.floor(i / perRow) * 40 }),
  );
}

describe('ProductDetail — compact layout behaviour', () => {
  beforeEach(() => {
    TestBed.resetTestingModule();
    StubIntersectionObserver.instances = [];
    vi.stubGlobal('IntersectionObserver', StubIntersectionObserver);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  describe('required-selection hints', () => {
    it('shows no "select a size / colour" hints until the shopper tries to add to cart', async () => {
      const { el } = await setup();
      expect(q(el, '[data-testid="pdp-hint-size"]')).toBeNull();
      expect(q(el, '[data-testid="pdp-hint-color"]')).toBeNull();
      // The button stays enabled so a tap can explain what's missing.
      expect(addToCartButton(el).disabled).toBe(false);
    });

    it('a tap without a selection reveals each hint next to its option (aria-live) and focuses the first missing one', async () => {
      const { fixture, el, addItem } = await setup();
      addToCartButton(el).click();
      fixture.detectChanges();

      expect(addItem).not.toHaveBeenCalled();
      const sizeGroup = q(el, '[data-testid="pdp-size-group"]')!;
      const colorGroup = q(el, '[data-testid="pdp-color-group"]')!;
      const sizeHint = sizeGroup.querySelector('[data-testid="pdp-hint-size"]');
      const colorHint = colorGroup.querySelector('[data-testid="pdp-hint-color"]');
      expect(sizeHint).not.toBeNull();
      expect(colorHint).not.toBeNull();
      expect(sizeHint!.closest('[aria-live="polite"]')).not.toBeNull();
      expect(colorHint!.closest('[aria-live="polite"]')).not.toBeNull();
      expect(sizeGroup.getAttribute('aria-describedby')).toBe(sizeHint!.id);
      expect(colorGroup.getAttribute('aria-describedby')).toBe(colorHint!.id);
      // Focus lands on the first selectable size.
      expect(document.activeElement?.textContent?.trim()).toBe('S');
      expect(sizeGroup.contains(document.activeElement)).toBe(true);
    });

    it('clears each hint as its option is chosen, then a valid add goes through', async () => {
      const { fixture, c, el, addItem, openDrawer } = await setup();
      await c.addToCart();
      fixture.detectChanges();
      c.selectSize({ label: 'M', in_stock: true });
      fixture.detectChanges();
      expect(q(el, '[data-testid="pdp-hint-size"]')).toBeNull();
      expect(q(el, '[data-testid="pdp-hint-color"]')).not.toBeNull();

      c.selectColor({ label: 'Sand', in_stock: true });
      fixture.detectChanges();
      expect(q(el, '[data-testid="pdp-hint-color"]')).toBeNull();

      await c.addToCart();
      expect(addItem).toHaveBeenCalledTimes(1);
      expect(addItem.mock.calls[0][0]).toMatchObject({ product_id: 100, size: 'M', color: 'Sand', note: null });
      expect(openDrawer).toHaveBeenCalledOnce();
      expect(c.attemptedAdd()).toBe(false);
    });

    it('also waits for an attempt before flagging a missing extra measurement', async () => {
      const { fixture, c, el } = await setup({
        products: [makeProduct({ requires_measurement: true, sizes: [], colors: [] })],
      });
      expect(q(el, '[data-testid="pdp-hint-measurement"]')).toBeNull();
      await c.addToCart();
      fixture.detectChanges();
      expect(q(el, '[data-testid="pdp-hint-measurement"]')).not.toBeNull();
      const input = q<HTMLTextAreaElement>(el, '[data-testid="pdp-measurement-input"]')!;
      expect(input.getAttribute('aria-invalid')).toBe('true');
      expect(document.activeElement).toBe(input);
    });

    it('keeps the out-of-stock state: a disabled button and no hints', async () => {
      const { fixture, c, el, addItem } = await setup({ products: [makeProduct({ in_stock: false })] });
      expect(addToCartButton(el).disabled).toBe(true);
      await c.addToCart();
      fixture.detectChanges();
      expect(addItem).not.toHaveBeenCalled();
      expect(q(el, '[data-testid="pdp-hint-size"]')).toBeNull();
      expect(q(el, '[data-testid="pdp-sticky-cta"]')).toBeNull();
    });
  });

  describe('sticky bottom bar', () => {
    it('appears only once the buy box scrolls above the viewport, runs the same validation, and hides near the end', async () => {
      const { fixture, el, addItem } = await setup();
      const bar = q(el, '[data-testid="pdp-sticky-cta"]')!;
      expect(bar.classList.contains('is-visible')).toBe(false);
      expect(bar.hasAttribute('inert')).toBe(true);

      // Buy box scrolled up and out of view (above the header-adjusted root).
      const buyBox = q(el, '[data-testid="pdp-buybox"]')!;
      StubIntersectionObserver.emit(buyBox, {
        isIntersecting: false,
        boundingClientRect: { top: -140, bottom: -80 } as DOMRectReadOnly,
        rootBounds: { top: 96 } as DOMRectReadOnly,
      });
      fixture.detectChanges();
      expect(bar.classList.contains('is-visible')).toBe(true);
      expect(bar.hasAttribute('inert')).toBe(false);

      // Same add-to-cart logic: a missing selection reveals the hints.
      bar.querySelector<HTMLButtonElement>('button')!.click();
      fixture.detectChanges();
      expect(addItem).not.toHaveBeenCalled();
      expect(q(el, '[data-testid="pdp-hint-size"]')).not.toBeNull();

      // Reaching the end-of-content sentinel hides it again.
      StubIntersectionObserver.emit(q(el, '.pdp-cta-sentinel')!, { isIntersecting: true });
      fixture.detectChanges();
      expect(bar.classList.contains('is-visible')).toBe(false);
    });

    it('stays hidden while the buy box is merely below the fold', async () => {
      const { fixture, el } = await setup();
      StubIntersectionObserver.emit(q(el, '[data-testid="pdp-buybox"]')!, {
        isIntersecting: false,
        boundingClientRect: { top: 1200, bottom: 1252 } as DOMRectReadOnly,
        rootBounds: { top: 96, bottom: 844 } as DOMRectReadOnly,
      });
      fixture.detectChanges();
      expect(q(el, '[data-testid="pdp-sticky-cta"]')!.classList.contains('is-visible')).toBe(false);
    });
  });

  describe('seller note disclosure', () => {
    it('sits behind a toggle, stays open while it holds text, and is sent exactly as before', async () => {
      const { fixture, c, el, addItem } = await setup({ products: [makeProduct({ sizes: [], colors: [] })] });
      const toggle = q<HTMLButtonElement>(el, '[data-testid="pdp-note-toggle"]')!;
      const panel = q(el, '#pdp-note-panel')!;
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      expect(toggle.getAttribute('aria-controls')).toBe('pdp-note-panel');
      expect(panel.hidden).toBe(true);

      toggle.click();
      fixture.detectChanges();
      expect(toggle.getAttribute('aria-expanded')).toBe('true');
      expect(panel.hidden).toBe(false);

      const input = q<HTMLTextAreaElement>(el, '[data-testid="pdp-note-input"]')!;
      input.value = '  Buttons from top to bottom  ';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();

      // Toggling never hides a typed note.
      toggle.click();
      fixture.detectChanges();
      expect(panel.hidden).toBe(false);

      await c.addToCart();
      expect(addItem.mock.calls[0][0]).toMatchObject({ note: 'Buttons from top to bottom' });
    });

    it('collapses again when it is empty', async () => {
      const { fixture, el } = await setup();
      const toggle = q<HTMLButtonElement>(el, '[data-testid="pdp-note-toggle"]')!;
      toggle.click();
      fixture.detectChanges();
      toggle.click();
      fixture.detectChanges();
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      expect(q(el, '#pdp-note-panel')!.hidden).toBe(true);
    });
  });

  describe('quantity stepper', () => {
    it('keeps its buttons focusable at 1 and 99 (aria-disabled, no-op click), so focus is never dropped', async () => {
      const { fixture, c, el } = await setup();
      const dec = q<HTMLButtonElement>(el, '[data-testid="pdp-qty-dec"]')!;
      const inc = q<HTMLButtonElement>(el, '[data-testid="pdp-qty-inc"]')!;
      expect(dec.getAttribute('aria-disabled')).toBe('true'); // starts at 1
      expect(dec.disabled).toBe(false);
      expect(inc.getAttribute('aria-disabled')).toBeNull();

      // Keyboard user steps up once, then back down to the floor.
      inc.click();
      fixture.detectChanges();
      expect(c.quantity()).toBe(2);
      expect(dec.getAttribute('aria-disabled')).toBeNull();
      dec.focus();
      dec.click();
      fixture.detectChanges();
      expect(c.quantity()).toBe(1);
      expect(dec.getAttribute('aria-disabled')).toBe('true');
      expect(dec.disabled).toBe(false);
      expect(document.activeElement).toBe(dec);
      dec.click(); // at the floor: nothing happens, focus stays
      fixture.detectChanges();
      expect(c.quantity()).toBe(1);
      expect(document.activeElement).toBe(dec);

      // Same at the ceiling.
      c.quantity.set(98);
      fixture.detectChanges();
      inc.focus();
      inc.click();
      fixture.detectChanges();
      expect(c.quantity()).toBe(99);
      expect(inc.getAttribute('aria-disabled')).toBe('true');
      expect(inc.disabled).toBe(false);
      inc.click();
      fixture.detectChanges();
      expect(c.quantity()).toBe(99);
      expect(document.activeElement).toBe(inc);
    });
  });

  describe('colour chips', () => {
    const manyColours = () =>
      makeProduct({
        sizes: [{ label: 'S', in_stock: true }],
        colors: Array.from({ length: 12 }, (_, i) => ({ label: `Colour ${i}`, in_stock: true })),
      });

    it('are clipped to two rows from the first render, so the list never paints taller and then shrinks once measured', async () => {
      const { el } = await setup({ products: [manyColours()], skipSettle: true });
      const chips = q(el, '[data-testid="pdp-color-chips"]')!;
      // Nothing measured as overflowing yet (no toggle, no hidden chips)...
      expect(q(el, '[data-testid="pdp-colors-toggle"]')).toBeNull();
      expect(el.querySelectorAll('[data-color-label].is-hidden')).toHaveLength(0);
      // ...but the two-row clip is already in place on that very render.
      expect(chips.classList.contains('is-collapsed')).toBe(true);
    });

    it('collapse to two rows behind a "+N more" toggle, and the expanded state is remembered', async () => {
      const { fixture, c, el } = await setup({ products: [manyColours()] });
      expect(q(el, '[data-testid="pdp-colors-toggle"]')).toBeNull(); // nothing measured yet

      layoutColorChips(el, 4); // 3 rows of 4
      c.measureColors();
      fixture.detectChanges();

      const toggle = q<HTMLButtonElement>(el, '[data-testid="pdp-colors-toggle"]')!;
      expect(toggle).not.toBeNull();
      expect(c.hiddenColorCount()).toBe(4);
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      expect(toggle.getAttribute('aria-controls')).toBe('pdp-color-chips');
      expect(q(el, '[data-testid="pdp-color-chips"]')!.classList.contains('is-collapsed')).toBe(true);
      expect(el.querySelectorAll('[data-color-label].is-hidden')).toHaveLength(4);

      toggle.click();
      fixture.detectChanges();
      expect(toggle.getAttribute('aria-expanded')).toBe('true');
      expect(el.querySelectorAll('[data-color-label].is-hidden')).toHaveLength(0);
      expect(q(el, '[data-testid="pdp-color-chips"]')!.classList.contains('is-collapsed')).toBe(false);

      // Other interaction re-renders the page; the colours stay expanded.
      c.selectSize({ label: 'S', in_stock: true });
      fixture.detectChanges();
      expect(c.colorsExpanded()).toBe(true);
      expect(el.querySelectorAll('[data-color-label].is-hidden')).toHaveLength(0);
    });

    it('never hides the selected colour when collapsing', async () => {
      const { fixture, c, el } = await setup({ products: [manyColours()] });
      layoutColorChips(el, 4);
      c.measureColors();
      fixture.detectChanges();

      c.toggleColors(); // expand
      fixture.detectChanges();
      c.selectColor({ label: 'Colour 10', in_stock: true });
      fixture.detectChanges();

      c.toggleColors(); // collapse: Colour 10 would fall in the hidden third row
      fixture.detectChanges();
      expect(c.orderedColors()[0].label).toBe('Colour 10');

      layoutColorChips(el, 4); // the browser re-lays out the new order
      c.measureColors();
      fixture.detectChanges();
      const selected = el.querySelector('[data-color-label="Colour 10"]')!;
      expect(selected.classList.contains('is-hidden')).toBe(false);
      expect(selected.getAttribute('aria-pressed')).toBe('true');
      expect(c.hiddenColorCount()).toBe(4);
    });
  });

  describe('Complete the look', () => {
    const look: CompleteLookResult = {
      interactionId: 77,
      items: [makeComplement(2001), makeComplement(2002), makeComplement(2003)],
    };

    it('renders as a compact strip in the info column right after the buy area, before "You may also like"', async () => {
      const { fixture, el, ctl } = await setup({ looks: { 100: look }, recs: [makeRelated(1)] });
      StubIntersectionObserver.revealAll();
      await fixture.whenStable();
      fixture.detectChanges();

      const strip = q(el, '[data-testid="pdp-ctl"]')!;
      expect(strip).not.toBeNull();
      expect(strip.closest('.pdp-info')).not.toBeNull();
      const buyBox = q(el, '[data-testid="pdp-buybox"]')!;
      expect(buyBox.compareDocumentPosition(strip) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      const related = q(el, '[data-testid="pdp-related"]')!;
      expect(related).not.toBeNull();
      expect(strip.compareDocumentPosition(related) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

      // One horizontally scrollable row of small cards: image, name, price, quick add.
      const items = strip.querySelectorAll('ul.pdp-rail > li[data-rail-item]');
      expect(items).toHaveLength(3);
      const first = items[0];
      expect(first.querySelector('a')!.getAttribute('href')).toBe('/product/piece-2001');
      expect(first.querySelector('.pdp-ctl__name')!.textContent).toContain('Piece 2001');
      expect(first.querySelector('.pdp-ctl__price')!.textContent).toContain('AED 150');
      const img = first.querySelector('img')!;
      expect(img.getAttribute('loading')).toBe('lazy');
      expect(img.getAttribute('width')).toBe('112');
      expect(first.querySelector('[data-testid="pdp-ctl-quick-2001"]')).not.toBeNull();
      expect(q(el, '[data-testid="pdp-ctl-add-look"]')).not.toBeNull();

      // Fetched once, by v3 id.
      expect(ctl.forProduct).toHaveBeenCalledTimes(1);
      expect(ctl.forProduct).toHaveBeenCalledWith(100);
    });

    it('quick-adds one complement (with its beacon), and "Add the look" adds them all', async () => {
      const { fixture, el, addItem, openDrawer, ctl, analytics } = await setup({ looks: { 100: look } });
      q<HTMLButtonElement>(el, '[data-testid="pdp-ctl-quick-2002"]')!.click();
      await flush(fixture);

      expect(addItem).toHaveBeenCalledWith({ product_id: 2002, quantity: 1, size: null, color: null, is_custom: false });
      expect(ctl.recordEvent).toHaveBeenCalledWith('complete_look_item_added', { interaction_id: 77, product_id: 2002 });
      expect(openDrawer).toHaveBeenCalledTimes(1);
      expect(q(el, '[data-testid="pdp-ctl-quick-2002"]')!.classList.contains('is-added')).toBe(true);
      expect(q(el, '[data-testid="pdp-ctl-status"]')!.textContent!.trim()).not.toBe('');

      addItem.mockClear();
      q<HTMLButtonElement>(el, '[data-testid="pdp-ctl-add-look"]')!.click();
      await flush(fixture);
      expect(addItem.mock.calls.map((call) => (call as unknown as [{ product_id: number }])[0].product_id)).toEqual([2001, 2002, 2003]);
      expect(ctl.recordEvent).toHaveBeenCalledWith('complete_look_added', { interaction_id: 77, count: 3 });
      expect(analytics.event).toHaveBeenCalledWith('complete_look_added', { count: 3 });
    });

    it('beacons a complement click', async () => {
      const { el, ctl } = await setup({ looks: { 100: look } });
      const link = q<HTMLAnchorElement>(el, '[data-testid="pdp-ctl"] a')!;
      link.addEventListener('click', (e) => e.preventDefault()); // no jsdom navigation
      link.click();
      expect(ctl.recordEvent).toHaveBeenCalledWith('ai_product_clicked', { interaction_id: 77, product_id: 2001 });
    });

    it('keeps a wishlist heart on each complement (guests are sent to sign in and back)', async () => {
      const { el } = await setup({ looks: { 100: look } });
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      const heart = q<HTMLButtonElement>(el, '[data-testid="pdp-ctl-save-2001"]')!;
      expect(heart).not.toBeNull();
      expect(heart.getAttribute('aria-pressed')).toBe('false');
      expect(heart.closest('a')).toBeNull(); // not nested inside the card link
      heart.click();
      expect(navigate).toHaveBeenCalledWith(['/login'], { queryParams: { returnUrl: '/product/abaya-01' } });
    });

    it('is hidden when there are no complements', async () => {
      const { el } = await setup();
      expect(q(el, '[data-testid="pdp-ctl"]')).toBeNull();
      expect(q(el, '[data-testid="pdp-ctl-skeleton"]')).toBeNull();
    });

    it('holds its slot with an inert, same-structure skeleton from the first product paint until the complements arrive', async () => {
      const { fixture, el, pendingLooks } = await setup({ deferLooks: true, skipSettle: true });

      // The very render that first paints the product already reserves the slot.
      const buyBox = q(el, '[data-testid="pdp-buybox"]')!;
      expect(buyBox).not.toBeNull();
      const skeleton = q(el, '[data-testid="pdp-ctl-skeleton"]')!;
      expect(skeleton).not.toBeNull();
      expect(skeleton.closest('.pdp-info')).not.toBeNull();
      expect(buyBox.compareDocumentPosition(skeleton) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(q(el, '[data-testid="pdp-ctl"]')).toBeNull();

      // Built from the strip's own layout classes: container, head (heading +
      // tools with two arrows + the "Add the look" pill), rail of 3:4 cards
      // with a name and a price line.
      expect(skeleton.classList.contains('pdp-ctl')).toBe(true);
      expect(skeleton.querySelector('.pdp-ctl__head > h2.pdp-ctl__heading')).not.toBeNull();
      const tools = skeleton.querySelector('.pdp-ctl__head > .pdp-ctl__tools')!;
      expect(tools.querySelectorAll(':scope > .pdp-rail-btn')).toHaveLength(2);
      expect(tools.querySelector(':scope > .pdp-ctl__add')).not.toBeNull();
      const rail = skeleton.querySelector('ul.pdp-rail.pdp-ctl__rail')!;
      const cards = rail.querySelectorAll(':scope > li.pdp-ctl__item');
      expect(cards).toHaveLength(6);
      for (const card of Array.from(cards)) {
        expect(card.querySelector('.pdp-ctl__link > .pdp-ctl__img')).not.toBeNull();
        expect(card.querySelector('.pdp-ctl__link > .pdp-ctl__name')).not.toBeNull();
        expect(card.querySelector('.pdp-ctl__link > .pdp-ctl__price')).not.toBeNull();
      }

      // Inert for assistive tech and the keyboard: placeholders are
      // aria-hidden, nothing is focusable (no arrows, no "Add the look", no
      // scrolling rail), and one visually hidden line explains it to
      // screen-reader users browsing into the section. It is plain text,
      // not a live region: a status inside an aria-busy section that is
      // replaced (never un-busied) would not be announced anyway.
      expect(skeleton.getAttribute('aria-busy')).toBe('true');
      expect(tools.getAttribute('aria-hidden')).toBe('true');
      expect(rail.getAttribute('aria-hidden')).toBe('true');
      expect(rail.hasAttribute('id')).toBe(false); // not the real (scroll-rail) list
      expect(skeleton.querySelectorAll('a, button, input, select, textarea, [tabindex]')).toHaveLength(0);
      expect(skeleton.querySelectorAll('[role="status"], [role="alert"], [aria-live]')).toHaveLength(0);
      const loadingLine = q(skeleton, '[data-testid="pdp-ctl-loading"]')!;
      expect(loadingLine).not.toBeNull();
      expect(loadingLine.classList.contains('visually-hidden')).toBe(true);
      expect(loadingLine.closest('[aria-hidden="true"]')).toBeNull();

      // Still pending after the stream settles: the same skeleton, with its
      // (static) heading and the "Add the look" label it is sized by (real
      // English strings: answer the i18n loader's pending request).
      for (const req of TestBed.inject(HttpTestingController).match((r) => r.url.endsWith('/i18n/en.json'))) {
        req.flush(en);
      }
      await settle(fixture);
      expect(pendingLooks.has(100)).toBe(true);
      expect(q(el, '[data-testid="pdp-ctl-skeleton"]')).toBe(skeleton);
      const skeletonHeading = skeleton.querySelector('.pdp-ctl__heading')!.textContent!.trim();
      const skeletonAddLabel = tools.querySelector('.pdp-ctl__add')!.textContent!.trim();
      expect(skeletonHeading).toBe(en.product.completeTheLookHeading);
      expect(skeletonAddLabel).toBe(en.product.addTheLook);
      expect(loadingLine.textContent!.trim()).toBe(en.product.ctl.loading);

      // The answer replaces the skeleton with the real strip, in the same slot.
      pendingLooks.get(100)!.resolve(look);
      await settle(fixture);
      expect(q(el, '[data-testid="pdp-ctl-skeleton"]')).toBeNull();
      const strip = q(el, '[data-testid="pdp-ctl"]')!;
      expect(strip).not.toBeNull();
      expect(buyBox.compareDocumentPosition(strip) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(strip.querySelectorAll('ul.pdp-rail > li[data-rail-item]')).toHaveLength(3);
      // Same heading text and the same "Add the look" label (so the same
      // head width / wrapping) as the skeleton it replaced.
      expect(strip.querySelector('.pdp-ctl__head > h2.pdp-ctl__heading')!.textContent!.trim()).toBe(skeletonHeading);
      expect(q(el, '[data-testid="pdp-ctl-add-look"]')!.textContent!.trim()).toBe(skeletonAddLabel);
      expect(strip.querySelectorAll('.pdp-ctl__tools > .pdp-rail-btn')).toHaveLength(2);
    });

    it('renders nothing once the request answers empty, or fails', async () => {
      const empty = await setup({ deferLooks: true });
      expect(q(empty.el, '[data-testid="pdp-ctl-skeleton"]')).not.toBeNull();
      empty.pendingLooks.get(100)!.resolve({ interactionId: null, items: [] });
      await settle(empty.fixture);
      expect(q(empty.el, '[data-testid="pdp-ctl-skeleton"]')).toBeNull();
      expect(q(empty.el, '[data-testid="pdp-ctl"]')).toBeNull();

      TestBed.resetTestingModule();
      const failed = await setup({ deferLooks: true });
      expect(q(failed.el, '[data-testid="pdp-ctl-skeleton"]')).not.toBeNull();
      failed.pendingLooks.get(100)!.reject(new Error('network'));
      await settle(failed.fixture);
      expect(q(failed.el, '[data-testid="pdp-ctl-skeleton"]')).toBeNull();
      expect(q(failed.el, '[data-testid="pdp-ctl"]')).toBeNull();
    });

    it('shows the skeleton again, never the previous product\'s complements, while the next product\'s load', async () => {
      const b = makeProduct({ id: 200, slug: 'kaftan-02', name: 'Second' });
      const { fixture, c, el, paramMap, pendingLooks } = await setup({ products: [makeProduct(), b], deferLooks: true });
      pendingLooks.get(100)!.resolve(look);
      await settle(fixture);
      expect(q(el, '[data-testid="pdp-ctl-quick-2001"]')).not.toBeNull();

      // e.g. a complement card was clicked: the render that first paints
      // product B swaps A's strip for the skeleton straight away.
      paramMap.next(convertToParamMap({ slug: 'kaftan-02' }));
      fixture.detectChanges();
      expect(c.product()?.id).toBe(200);
      expect(c.completeTheLookStatus()).toBe('loading');
      expect(c.completeTheLookItems()).toEqual([]);
      expect(q(el, '[data-testid="pdp-ctl-skeleton"]')).not.toBeNull();
      expect(q(el, '[data-testid="pdp-ctl"]')).toBeNull();
      expect(q(el, '[data-testid="pdp-ctl-quick-2001"]')).toBeNull();

      await settle(fixture);
      expect(q(el, '[data-testid="pdp-ctl-skeleton"]')).not.toBeNull(); // B still pending
      pendingLooks.get(200)!.resolve({ interactionId: 9, items: [makeComplement(3001)] });
      await settle(fixture);
      expect(q(el, '[data-testid="pdp-ctl-skeleton"]')).toBeNull();
      expect(q(el, '[data-testid="pdp-ctl-quick-3001"]')).not.toBeNull();
      expect(q(el, '[data-testid="pdp-ctl-quick-2001"]')).toBeNull();
    });

    it('keeps both rail arrows rendered (so the head never re-wraps) and focusable at the edges, with a no-op click', async () => {
      const { fixture, el } = await setup({ looks: { 100: look } });
      const prev = q<HTMLButtonElement>(el, '[data-testid="pdp-ctl-prev"]')!;
      const next = q<HTMLButtonElement>(el, '[data-testid="pdp-ctl-next"]')!;
      // Rendered before (and whether or not) the rail turns out to overflow.
      expect(prev).not.toBeNull();
      expect(next).not.toBeNull();

      // Fake an overflowing rail: 900px of cards in a 300px window.
      const rail = q(el, '[data-testid="pdp-ctl-rail"]')!;
      let scrollLeft = 0;
      Object.defineProperty(rail, 'scrollWidth', { configurable: true, get: () => 900 });
      Object.defineProperty(rail, 'clientWidth', { configurable: true, get: () => 300 });
      Object.defineProperty(rail, 'scrollLeft', {
        configurable: true,
        get: () => scrollLeft,
        set: (v: number) => { scrollLeft = v; },
      });
      const scrollBy = vi.fn((opts: ScrollToOptions) => {
        scrollLeft = Math.min(600, Math.max(0, scrollLeft + (opts.left ?? 0)));
        rail.dispatchEvent(new Event('scroll'));
      });
      (rail as unknown as { scrollBy: typeof scrollBy }).scrollBy = scrollBy;
      rail.dispatchEvent(new Event('scroll'));
      fixture.detectChanges();
      expect(prev.getAttribute('aria-disabled')).toBe('true');
      expect(next.getAttribute('aria-disabled')).toBeNull();

      // Keyboard user presses "Next" until the end: focus stays on it.
      next.focus();
      for (let i = 0; i < 3; i++) {
        next.click();
        fixture.detectChanges();
      }
      expect(scrollLeft).toBe(600);
      expect(next.getAttribute('aria-disabled')).toBe('true');
      expect(next.disabled).toBe(false);
      expect(document.activeElement).toBe(next);
      next.click();
      fixture.detectChanges();
      expect(scrollBy).toHaveBeenCalledTimes(3); // no-op at the end
      expect(document.activeElement).toBe(next);
      expect(prev.getAttribute('aria-disabled')).toBeNull();
    });
  });

  describe('info column extras', () => {
    it('renders Description (open) and Details incl. SKU (collapsed) as accessible accordions', async () => {
      const { fixture, el } = await setup({
        products: [makeProduct({ description: '<p>Flowing crepe.</p>', fabric: 'Crepe', sku: 'AB-1' })],
      });
      const desc = q<HTMLButtonElement>(el, '[data-testid="pdp-acc-description"]')!;
      const details = q<HTMLButtonElement>(el, '[data-testid="pdp-acc-details"]')!;
      const descPanel = q(el, '#' + desc.getAttribute('aria-controls'))!;
      const detailsPanel = q(el, '#' + details.getAttribute('aria-controls'))!;

      expect(desc.getAttribute('aria-expanded')).toBe('true');
      expect(descPanel.getAttribute('role')).toBe('region');
      expect(descPanel.getAttribute('aria-labelledby')).toBe(desc.id);
      expect(descPanel.hidden).toBe(false);
      expect(descPanel.textContent).toContain('Flowing crepe.');

      expect(details.getAttribute('aria-expanded')).toBe('false');
      expect(detailsPanel.hidden).toBe(true);
      expect(detailsPanel.textContent).toContain('AB-1');
      details.click();
      fixture.detectChanges();
      expect(details.getAttribute('aria-expanded')).toBe('true');
      expect(detailsPanel.hidden).toBe(false);

      // The old tab strip is gone.
      expect(q(el, '[role="tablist"]')).toBeNull();
    });

    it('keeps the gift-card nudge (one slim line), share buttons and a compact 3-item trust row', async () => {
      const { el } = await setup();
      const info = q(el, '.pdp-info')!;
      expect(info.querySelector('[data-testid="gift-card-nudge-slim"]')).not.toBeNull();
      expect(info.querySelector('ui-share-buttons')).not.toBeNull();
      expect(info.querySelectorAll('.pdp-trust > li')).toHaveLength(3);
    });

    it('keeps the try-on (Ain) and customization entry points', async () => {
      const { el } = await setup({ products: [makeProduct({ try_on_enabled: true })] });
      expect(q(el, '[data-testid="pdp-tryon-trigger"]')).not.toBeNull();
      const custom = q(el, '[data-testid="pdp-customization-trigger"]')!;
      expect(custom.classList.contains('pdp-textlink')).toBe(true);
    });
  });

  describe('gallery', () => {
    const cdn = (n: number) => `https://api-v3.3bayti.ae/uploads/products/look-${n}.jpg`;
    const withImages = (count = 3) =>
      makeProduct({
        images: Array.from({ length: count }, (_, i) => ({ url: cdn(i + 1) })),
        primary_image: { url: cdn(1) },
      });

    /**
     * Fake the thumbnail row's layout (jsdom has none): a `width`px row with
     * 4px padding showing 56px thumbs 8px apart, laid out from the inline
     * start (the left in LTR, the right in RTL). Rects are viewport-relative
     * and follow scrollLeft as in Chromium (RTL scrollLeft runs 0 -> negative).
     */
    function fakeThumbRow(row: HTMLElement, dir: 'ltr' | 'rtl', width = 200) {
      const pad = 4;
      const thumb = 56;
      const step = thumb + 8;
      let scrollLeft = 0;
      row.style.paddingLeft = `${pad}px`;
      row.style.paddingRight = `${pad}px`;
      Object.defineProperty(row, 'scrollLeft', {
        configurable: true,
        get: () => scrollLeft,
        set: (v: number) => { scrollLeft = v; },
      });
      const scrollTo = vi.fn((opts: ScrollToOptions) => { scrollLeft = opts.left ?? scrollLeft; });
      row.scrollTo = scrollTo as unknown as typeof row.scrollTo;
      const rect = (left: number, w: number) =>
        ({ left, right: left + w, width: w, top: 0, bottom: 75, height: 75, x: left, y: 0, toJSON: () => ({}) }) as DOMRect;
      row.getBoundingClientRect = () => rect(0, width);
      Array.from(row.children).forEach((li, i) => {
        (li as HTMLElement).getBoundingClientRect = () =>
          rect((dir === 'ltr' ? pad + i * step : width - pad - thumb - i * step) - scrollLeft, thumb);
      });
      return { scrollTo, scrollLeft: () => scrollLeft };
    }

    /** Spy on Element#scrollIntoView (absent in jsdom) for the duration of `run`. */
    async function withScrollIntoViewSpy(run: (spy: ReturnType<typeof vi.fn>) => Promise<void>): Promise<void> {
      const original = Object.getOwnPropertyDescriptor(Element.prototype, 'scrollIntoView');
      const spy = vi.fn();
      Object.defineProperty(Element.prototype, 'scrollIntoView', { configurable: true, writable: true, value: spy });
      try {
        await run(spy);
      } finally {
        if (original) Object.defineProperty(Element.prototype, 'scrollIntoView', original);
        else delete (Element.prototype as unknown as Record<string, unknown>)['scrollIntoView'];
      }
    }

    it('puts the thumbnail row BELOW the stage (DOM and visual order), and sizes images for the 488px max stage', async () => {
      const { el } = await setup({ products: [withImages()] });
      const gallery = q(el, '.pdp-gallery')!;
      const stage = gallery.querySelector('.pdp-stage')!;
      const row = q(el, '[data-testid="pdp-thumbnails"]')!;
      // Stage first, then the row: no CSS `order` trick, so DOM, tab and visual order agree.
      expect(Array.from(gallery.children)).toEqual([stage, row]);
      expect(row.querySelectorAll(':scope > li > button.pdp-thumb')).toHaveLength(3);
      // Desktop: the viewport-height-derived stage width (3/4 of the
      // clamp(floor, first-screen fit, 650px) stage height); otherwise the
      // stage is never wider than 487.5px (650px tall at 3:4); phones 100vw.
      const first = el.querySelector<HTMLImageElement>('[data-testid="pdp-gallery-track"] img')!;
      expect(first.getAttribute('sizes')).toBe(
        '(min-width: 1024px) calc(clamp(min(420px, 100vh - 226px), 100vh - 270px, 650px) * 3 / 4), ' +
          '(min-width: 488px) 488px, 100vw',
      );
    });

    it('re-reveals the active thumbnail (instantly) when the row appears or changes width', async () => {
      // jsdom has no ResizeObserver: record each observer and what it watches.
      const observers: { callback: ResizeObserverCallback; targets: Set<Element> }[] = [];
      class StubResizeObserver {
        private readonly entry: { callback: ResizeObserverCallback; targets: Set<Element> };
        constructor(callback: ResizeObserverCallback) {
          this.entry = { callback, targets: new Set<Element>() };
          observers.push(this.entry);
        }
        observe(target: Element): void { this.entry.targets.add(target); }
        unobserve(target: Element): void { this.entry.targets.delete(target); }
        disconnect(): void { this.entry.targets.clear(); }
      }
      vi.stubGlobal('ResizeObserver', StubResizeObserver);
      const resized = (target: Element) => {
        for (const o of observers) {
          if (o.targets.has(target)) o.callback([], {} as ResizeObserver);
        }
      };

      await withScrollIntoViewSpy(async (scrollIntoView) => {
        const { fixture, c, el } = await setup({ products: [withImages(8)] });
        const row = q(el, '[data-testid="pdp-thumbnails"]')!;
        expect(observers.some((o) => o.targets.has(row))).toBe(true);

        // Phone layout: the row is display:none (0 wide), so swiping to
        // image 7 can't scroll it.
        const fake = fakeThumbRow(row, 'ltr', 0);
        c.goToSlide(6);
        fixture.detectChanges();
        expect(c.activeImageIndex()).toBe(6);
        expect(fake.scrollTo).not.toHaveBeenCalled();

        // Rotate to the desktop layout: the row appears 200px wide. Thumb 6
        // sits at 388..444 (past the 196px edge): revealed, instantly.
        row.getBoundingClientRect = () =>
          ({ left: 0, right: 200, width: 200, top: 0, bottom: 75, height: 75, x: 0, y: 0, toJSON: () => ({}) }) as DOMRect;
        resized(row);
        expect(fake.scrollTo).toHaveBeenCalledTimes(1);
        expect(fake.scrollTo).toHaveBeenLastCalledWith({ left: 248, behavior: 'auto' });

        // A resize that leaves it in view scrolls nothing.
        resized(row);
        expect(fake.scrollTo).toHaveBeenCalledTimes(1);
        expect(scrollIntoView).not.toHaveBeenCalled();
      });
    });

    it('keeps the active thumbnail in view by scrolling the row itself (thumbnail, arrows, keyboard, lightbox), never via scrollIntoView', async () => {
      await withScrollIntoViewSpy(async (scrollIntoView) => {
        const { fixture, c, el } = await setup({ products: [withImages(6)] });
        const row = q(el, '[data-testid="pdp-thumbnails"]')!;
        const fake = fakeThumbRow(row, 'ltr');
        // Visible window: 4..196px. Thumb 3 sits at 196..252: 56px past the end.
        el.querySelectorAll<HTMLButtonElement>('.pdp-thumb')[3].click();
        fixture.detectChanges();
        expect(c.activeImageIndex()).toBe(3);
        expect(fake.scrollTo).toHaveBeenLastCalledWith(expect.objectContaining({ left: 56 }));

        // Stage "Next" arrow: thumb 4 (260..316 at scroll 0) needs 120px.
        q<HTMLButtonElement>(el, '[data-testid="pdp-gallery-next"]')!.click();
        fixture.detectChanges();
        expect(c.activeImageIndex()).toBe(4);
        expect(fake.scrollTo).toHaveBeenLastCalledWith(expect.objectContaining({ left: 120 }));

        // Keyboard Home on the gallery: back to the start of the row.
        el.querySelectorAll('.pdp-slide__btn')[4].dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
        fixture.detectChanges();
        expect(c.activeImageIndex()).toBe(0);
        expect(fake.scrollTo).toHaveBeenLastCalledWith(expect.objectContaining({ left: 0 }));

        // Cycling backwards in the lightbox wraps to the last image: the row follows.
        el.querySelector<HTMLButtonElement>('.pdp-slide__btn')!.click();
        fixture.detectChanges();
        q(el, '.pdp-lightbox [role="dialog"]')!.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
        fixture.detectChanges();
        expect(c.activeImageIndex()).toBe(5);
        expect(fake.scrollTo).toHaveBeenLastCalledWith(expect.objectContaining({ left: 184 }));

        // A thumbnail already in view never scrolls the row.
        const calls = fake.scrollTo.mock.calls.length;
        el.querySelectorAll<HTMLButtonElement>('.pdp-thumb')[4].click();
        fixture.detectChanges();
        expect(fake.scrollTo).toHaveBeenCalledTimes(calls);
        expect(scrollIntoView).not.toHaveBeenCalled();
      });
    });

    it('keeps the active thumbnail in view in RTL (scrollLeft runs negative)', async () => {
      await withScrollIntoViewSpy(async (scrollIntoView) => {
        const { fixture, c, el } = await setup({ products: [withImages(6)] });
        const row = q(el, '[data-testid="pdp-thumbnails"]')!;
        row.style.direction = 'rtl';
        const fake = fakeThumbRow(row, 'rtl');
        // Thumbs start at the right; thumb 3 sits at -52..4: 56px past the (left) end.
        el.querySelectorAll<HTMLButtonElement>('.pdp-thumb')[3].click();
        fixture.detectChanges();
        expect(fake.scrollTo).toHaveBeenLastCalledWith(expect.objectContaining({ left: -56 }));
        expect(fake.scrollLeft()).toBe(-56);

        q<HTMLButtonElement>(el, '[data-testid="pdp-gallery-next"]')!.click();
        fixture.detectChanges();
        expect(c.activeImageIndex()).toBe(4);
        expect(fake.scrollTo).toHaveBeenLastCalledWith(expect.objectContaining({ left: -120 }));

        c.goToSlide(0);
        fixture.detectChanges();
        expect(fake.scrollTo).toHaveBeenLastCalledWith(expect.objectContaining({ left: 0 }));
        expect(scrollIntoView).not.toHaveBeenCalled();
      });
    });

    it('keeps the stage arrows focusable at the first / last image (aria-disabled, no-op click), so focus is never dropped', async () => {
      const { fixture, c, el } = await setup({ products: [withImages()] });
      const prev = q<HTMLButtonElement>(el, '[data-testid="pdp-gallery-prev"]')!;
      const next = q<HTMLButtonElement>(el, '[data-testid="pdp-gallery-next"]')!;
      expect(prev.getAttribute('aria-disabled')).toBe('true');
      expect(prev.disabled).toBe(false);
      expect(next.getAttribute('aria-disabled')).toBeNull();

      next.focus();
      next.click();
      fixture.detectChanges();
      next.click();
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(2);
      expect(next.getAttribute('aria-disabled')).toBe('true');
      expect(next.disabled).toBe(false);
      expect(document.activeElement).toBe(next);
      next.click(); // at the end: nothing happens, focus stays
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(2);
      expect(document.activeElement).toBe(next);
      expect(prev.getAttribute('aria-disabled')).toBeNull();

      prev.focus();
      prev.click();
      fixture.detectChanges();
      prev.click();
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(0);
      expect(prev.getAttribute('aria-disabled')).toBe('true');
      expect(document.activeElement).toBe(prev);
      prev.click();
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(0);
      expect(document.activeElement).toBe(prev);
    });

    it('renders a swipeable track: first image eager + high priority with a srcset, the rest lazy', async () => {
      const { el } = await setup({ products: [withImages()] });
      const slides = el.querySelectorAll<HTMLImageElement>('[data-testid="pdp-gallery-track"] img');
      expect(slides).toHaveLength(3);
      const [first, second] = Array.from(slides);
      expect(first.getAttribute('loading')).toBe('eager');
      expect(first.getAttribute('fetchpriority')).toBe('high');
      expect(first.getAttribute('srcset')).toContain(' 480w');
      expect(first.getAttribute('srcset')).toContain(' 1200w');
      expect(first.getAttribute('sizes')).toBeTruthy();
      expect(first.getAttribute('width')).toBe('900');
      expect(first.getAttribute('height')).toBe('1200');
      expect(second.getAttribute('loading')).toBe('lazy');
      expect(second.getAttribute('decoding')).toBe('async');
      expect(second.getAttribute('fetchpriority')).toBeNull();

      // Compact thumbnails use the small transform, not the 900px detail image.
      const thumbs = el.querySelectorAll<HTMLImageElement>('.pdp-thumbnails img');
      expect(thumbs).toHaveLength(3);
      expect(thumbs[0].getAttribute('src')).toContain('width=200');
      expect(thumbs[0].getAttribute('loading')).toBe('lazy');
    });

    it('thumbnails and arrow keys switch the image (one tab stop on the active slide)', async () => {
      const { fixture, c, el } = await setup({ products: [withImages()] });
      const thumbs = el.querySelectorAll<HTMLButtonElement>('.pdp-thumb');
      thumbs[2].click();
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(2);
      expect(thumbs[2].getAttribute('aria-pressed')).toBe('true');

      const slideButtons = el.querySelectorAll<HTMLButtonElement>('.pdp-slide__btn');
      expect(Array.from(slideButtons).map((b) => b.getAttribute('tabindex'))).toEqual(['-1', '-1', '0']);

      slideButtons[2].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(1);
      expect(document.activeElement).toBe(slideButtons[1]);

      slideButtons[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(0);
    });

    it('opens the zoom lightbox from the active slide and closes it on Escape', async () => {
      const { fixture, el } = await setup({ products: [withImages()] });
      el.querySelector<HTMLButtonElement>('.pdp-slide__btn')!.click();
      fixture.detectChanges();
      const dialog = q(el, '.pdp-lightbox [role="dialog"]')!;
      expect(dialog).not.toBeNull();
      dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      fixture.detectChanges();
      expect(q(el, '.pdp-lightbox')).toBeNull();
    });

    it('closing the lightbox after cycling images leaves the track, the active index and focus on the same image', async () => {
      const { fixture, c, el } = await setup({ products: [withImages()] });
      const track = q(el, '[data-testid="pdp-gallery-track"]')!;
      const slideButtons = Array.from(el.querySelectorAll<HTMLButtonElement>('.pdp-slide__btn'));

      // Fake browser geometry: 300px slides, scrollTo moves the track, and
      // focus() WITHOUT preventScroll scrolls the track to the focused slide
      // (what Chrome / Firefox do for an off-screen element in a scroller).
      let scrollLeft = 0;
      Object.defineProperty(track, 'clientWidth', { configurable: true, get: () => 300 });
      Object.defineProperty(track, 'scrollLeft', {
        configurable: true,
        get: () => scrollLeft,
        set: (v: number) => { scrollLeft = v; },
      });
      track.scrollTo = ((opts: ScrollToOptions) => { scrollLeft = opts.left ?? scrollLeft; }) as typeof track.scrollTo;
      slideButtons.forEach((btn, i) => {
        const nativeFocus = btn.focus.bind(btn);
        btn.focus = (opts?: FocusOptions) => {
          nativeFocus(opts);
          if (!opts?.preventScroll) scrollLeft = i * 300;
        };
      });

      // Open from the first slide (a click focuses the button in Chrome).
      slideButtons[0].focus();
      slideButtons[0].click();
      fixture.detectChanges();
      await new Promise((resolve) => setTimeout(resolve, 0)); // close button takes focus
      const dialog = q(el, '.pdp-lightbox [role="dialog"]')!;
      expect(document.activeElement).toBe(q(el, '.pdp-lightbox__close'));

      dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
      dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      fixture.detectChanges();

      expect(q(el, '.pdp-lightbox')).toBeNull();
      expect(c.activeImageIndex()).toBe(2);
      expect(scrollLeft).toBe(600); // the track shows image 3, not the slide it was opened from
      expect(document.activeElement).toBe(slideButtons[2]);
      expect(slideButtons.map((b) => b.getAttribute('tabindex'))).toEqual(['-1', '-1', '0']);
      expect(el.querySelectorAll('.pdp-thumb')[2].getAttribute('aria-pressed')).toBe('true');
      expect(q(el, '[data-testid="pdp-gallery-count"]')!.textContent!.trim()).toBe('3 / 3');

      // The scroll the close caused settles on the same image (no drift back).
      track.dispatchEvent(new Event('scroll'));
      await new Promise((resolve) => setTimeout(resolve, 50));
      fixture.detectChanges();
      expect(c.activeImageIndex()).toBe(2);
    });
  });

  it('the rating link stays on the page and moves focus to the Reviews section', async () => {
    const { el } = await setup({ products: [makeProduct({ rating: 4.5, review_count: 3 })] });
    const link = q<HTMLAnchorElement>(el, '[data-testid="pdp-rating-link"]')!;
    expect(link.getAttribute('href')).toBe('/product/abaya-01#reviews');
    const click = new MouseEvent('click', { bubbles: true, cancelable: true });
    link.dispatchEvent(click);
    expect(click.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(q(el, 'section#reviews'));
  });

  it('navigating to another product resets the page state and requests each endpoint once per product', async () => {
    const a = makeProduct();
    const b = makeProduct({ id: 200, slug: 'kaftan-02', name: 'Second' });
    const { fixture, c, el, paramMap, get, recs, ctl } = await setup({
      products: [a, b],
      looks: { 100: { interactionId: 1, items: [makeComplement(3001)] } },
    });

    // Dirty the page: selections, an attempted add, a note, a gallery position, a quick add.
    c.selectSize({ label: 'S', in_stock: true });
    await c.addToCart();
    c.note.set('Shorter sleeves');
    c.activeImageIndex.set(2);
    q<HTMLButtonElement>(el, '[data-testid="pdp-ctl-quick-3001"]')!.click();
    await flush(fixture);
    expect(c.attemptedAdd()).toBe(true);
    expect(c.ctlState(3001)).toBe('added');

    paramMap.next(convertToParamMap({ slug: 'kaftan-02' }));
    await settle(fixture);

    expect(c.product()?.id).toBe(200);
    expect(c.selectedSize()).toBeNull();
    expect(c.selectedColor()).toBeNull();
    expect(c.attemptedAdd()).toBe(false);
    expect(c.note()).toBe('');
    expect(c.noteOpen()).toBe(false);
    expect(c.activeImageIndex()).toBe(0);
    expect(c.ctlState(3001)).toBeNull();
    expect(q(el, '[data-testid="pdp-hint-size"]')).toBeNull();
    expect(q(el, '[data-testid="pdp-ctl"]')).toBeNull(); // product B has no complements

    // One product fetch, one recommendations fetch and one complete-the-look
    // fetch per product: nothing duplicated by the navigation.
    const productCalls = get.mock.calls.filter((call) => call[0] === 'GET /products/:slug');
    expect(productCalls.map((call) => (call[1] as { params: { slug: string } }).params.slug)).toEqual(['abaya-01', 'kaftan-02']);
    expect(recs.forProduct.mock.calls.map((call) => (call as unknown as [string])[0])).toEqual(['abaya-01', 'kaftan-02']);
    expect(ctl.forProduct.mock.calls.map((call) => (call as unknown as [number])[0])).toEqual([100, 200]);
  });
});
