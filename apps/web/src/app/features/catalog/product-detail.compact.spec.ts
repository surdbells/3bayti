import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { ActivatedRoute, ParamMap, Router, convertToParamMap, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
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

/**
 * Compact / modern PDP coverage: required-selection hints that appear only
 * after an add-to-cart attempt, the sticky bottom bar, the seller-note
 * disclosure, two-row colour collapse, the "Complete the look" strip under
 * the buy area, the Description / Details accordions, the swipeable gallery,
 * the #reviews link, and state reset + request de-duplication when the
 * router reuses the component for another product.
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
}

interface SetupOpts {
  products?: ProductDetail[];
  looks?: Record<number, CompleteLookResult>;
  recs?: Product[];
  fragment?: string | null;
}

async function setup(opts: SetupOpts = {}): Promise<Ctx> {
  const products = opts.products ?? [makeProduct()];
  const bySlug = new Map(products.map((p) => [p.slug, p]));
  const paramMap = new BehaviorSubject<ParamMap>(convertToParamMap({ slug: products[0].slug }));
  const get = vi.fn((_route: string, req: { params: { slug: string } }) => of({ data: bySlug.get(req.params.slug) }));
  const addItem = vi.fn(() => Promise.resolve({}));
  const openDrawer = vi.fn();
  const ctl = {
    forProduct: vi.fn((id: number) =>
      Promise.resolve(opts.looks?.[id] ?? ({ interactionId: null, items: [] } as CompleteLookResult)),
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
  await settle(fixture);
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

  describe('colour chips', () => {
    const manyColours = () =>
      makeProduct({
        sizes: [{ label: 'S', in_stock: true }],
        colors: Array.from({ length: 12 }, (_, i) => ({ label: `Colour ${i}`, in_stock: true })),
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
    const withImages = () =>
      makeProduct({ images: [{ url: cdn(1) }, { url: cdn(2) }, { url: cdn(3) }], primary_image: { url: cdn(1) } });

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
