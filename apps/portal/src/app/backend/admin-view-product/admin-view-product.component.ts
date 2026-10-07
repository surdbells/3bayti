import { Component, DestroyRef, inject, OnInit } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import { CommonModule, Location } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { combineLatest, of, Subject } from 'rxjs';
import { catchError, distinctUntilChanged, map, switchMap } from 'rxjs/operators';
import imageCompression from 'browser-image-compression';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { ImageUploadService } from '../../services/image-upload.service';
import { HotToastService } from '../../shared/toast/toast.service';

import { Category } from '../../class/category';
import { Labels } from '../../class/labels';

// Ax design-system components
import { AxRichEditorComponent } from '../../shared/rich/ax-rich-editor.component';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { CfImagePipe } from '../../shared/cf-image.pipe';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxComboboxComponent, AxComboboxOption } from '../../shared/forms/ax-combobox.component';
import {
  AxAccordionComponent,
  AxAccordionItemComponent,
} from '../../shared/overlays';
import { apiErrorMessage } from '../../shared/http/api-error';

interface ColorOption {
  id: string;
  text: string;
  hex: string;
}

/** A single before→after field change rendered in the history timeline. */
interface HistoryChange {
  field: string;
  before: string;
  after: string;
}

/**
 * Visual treatment per audit action, mirrors the audit-log console so the
 * badge colours/labels read the same across the portal.
 */
const HISTORY_ACTION_META: Record<string, { label: string; badge: string; dot: string }> = {
  created:    { label: 'Created',    badge: 'ax-badge ax-badge-success', dot: 'ph-dot-created' },
  updated:    { label: 'Updated',    badge: 'ax-badge ax-badge-info',    dot: 'ph-dot-updated' },
  deleted:    { label: 'Deleted',    badge: 'ax-badge ax-badge-danger',  dot: 'ph-dot-deleted' },
  overridden: { label: 'Overridden', badge: 'ax-badge ax-badge-warning', dot: 'ph-dot-overridden' },
  viewed:     { label: 'Viewed',     badge: 'ax-badge ax-badge-neutral', dot: 'ph-dot-viewed' },
  default:    { label: 'Changed',    badge: 'ax-badge ax-badge-neutral', dot: 'ph-dot-default' },
};

/** Field changes past this count are hidden behind a "show all" toggle. */
const HISTORY_PREVIEW_ROWS = 5;

const PLACEHOLDER_IMAGE = 'assets/img/placeholder-1.png';

/** Stock fields the API applies together (see buildUpdatePayload). */
const STOCK_KEYS = ['stock_quantity', 'stock_status', 'allow_oversell'] as const;

/** A positive whole number, or null (null / blank / 0 / negative / non-numeric). */
function positiveIntOrNull(v: unknown): number | null {
  if (v == null || (typeof v === 'string' && v.trim() === '')) return null;
  const n = Math.trunc(Number(v));
  return Number.isFinite(n) && n > 0 ? n : null;
}

/**
 * Size checkbox flag → stored size label, for the sizes this page offers.
 * Labels are stored upper-case ('CUSTOM'), matching the shared product form
 * and the API. Stored sizes this page has no checkbox for (e.g. '51', '64')
 * are kept verbatim on save (see `extraSizes`), never silently dropped.
 */
const SIZE_FLAGS: Record<string, string> = {
  size_xs: 'XS', size_s: 'S', size_m: 'M', size_l: 'L', size_xl: 'XL', size_xxl: 'XXL',
  size_50: '50', size_52: '52', size_54: '54', size_56: '56', size_58: '58', size_60: '60', size_62: '62',
  size_custom: 'CUSTOM',
};

/**
 * Admin product detail / quick-edit page (routes `admin/products/:id` and the
 * legacy `adminviewproduct?id=`).
 *
 * The route param (or `?id=`) is the product's **v3 id**, the id every admin
 * list and the top-bar search hand out. The page loads the admin detail shape
 * (GET /admin/products/:id, any status), saves through PUT /admin/products/:id
 * and reads the audit trail from GET /admin/products/:id/history, all with that
 * same id. Labels are store-scoped: the picker lists, and "Add" creates, labels
 * of the PRODUCT's store (GET/POST /admin/vendors/:id/labels), never the
 * admin's own session store.
 */
@Component({
  selector: 'app-admin-view-product',
  standalone: true,
  imports: [CfImagePipe,
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AxRichEditorComponent,
    AxAccordionComponent,
    AxAccordionItemComponent, IconComponent, AxComboboxComponent],
  templateUrl: './admin-view-product.component.html',
  styleUrl: './admin-view-product.component.css',
})
export class AdminViewProductComponent implements OnInit {
  readonly deliveryTimeOptions: AxComboboxOption[] = [
    { id: '1-3', label: '1 – 3 days' },
    { id: '4-7', label: '4 – 7 days' },
    { id: '14-21', label: '14 – 21 days' },
    { id: 'custom', label: 'Custom' },
  ];
  category?: Category[];
  /** Active labels of the product's store (undefined until loaded). */
  labels?: Labels[];

  private readonly confirm = inject(AxConfirmService);
  private readonly destroyRef = inject(DestroyRef);
  colorOptions: ColorOption[] = [];

  ui_controls = {
    is_loading: false,
    is_creating_label: false,
    page_loading: true,
    /** Re-reading the product after a save (the form stays visible). */
    refreshing: false,
    labels_loading: false,
    featured_uploading: false,
    nav_open: false,
  };
  /** Why the product couldn't be loaded (blank while it's fine). */
  load_error = '';
  labels_error = '';
  featured_progress = 0;

  image_url: any = 'https://api-v3.3bayti.ae/vendors/products/';

  /** The product's v3 id, from the route (or ?id=). */
  productId = 0;
  /** Owning store (v3 vendor id + name); a product's store is fixed. */
  vendorId = 0;
  vendorName = '';
  productStatus = '';
  /** The label stored on the product when it was loaded (null = none). */
  currentLabelId: number | null = null;
  /** Stored sizes this page has no checkbox for, round-tripped as-is. */
  extraSizes: string[] = [];
  /** True once the featured image was replaced in this session. */
  private featuredChanged = false;
  /**
   * Bumped for every featured-image upload and whenever the page switches
   * product, so an upload that finishes after the admin opened another
   * product (top-bar search) is dropped instead of landing on that one.
   */
  private featuredUploadSeq = 0;
  /** Comparable snapshot of the "send-when-changed" fields after load. */
  private baseline: Record<string, string> = {};

  update: any = this.emptyModel();

  newLabelName = '';

  // ── Change history (audit timeline) ──────────────────────────────────
  history: any[] = [];
  history_loading = false;
  history_error = '';
  private readonly historyExpanded = new Set<number>();

  selected = new Set<string>();
  trackById = (_: number, item: ColorOption) => item.id;

  /** Product loads, switchMap'd so a late response for a previous id never lands. */
  private readonly load$ = new Subject<{ id: number; silent: boolean }>();
  /** Label loads, switchMap'd per store for the same reason. */
  private readonly labels$ = new Subject<number>();

  constructor(
    private route: ActivatedRoute,
    private location: Location,
    private adapter: PortalCrudAdapter,
    private imageUpload: ImageUploadService,
    private toast: HotToastService,
  ) {}

  toggle(id: string, checked: boolean) {
    checked ? this.selected.add(id) : this.selected.delete(id);
  }

  isSelected(id: string): boolean {
    return this.selected.has(id);
  }

  get selectedColors(): ColorOption[] {
    return this.colorOptions.filter(c => this.selected.has(c.id));
  }

  needsBorder(hex: string): boolean {
    if (hex.startsWith('linear')) return false;
    const rgb = this.hexToRgb(hex);
    const brightness = 0.299 * rgb.r + 0.587 * rgb.g + 0.114 * rgb.b;
    return brightness > 220;
  }

  private hexToRgb(hex: string) {
    const n = hex.replace('#', '');
    const bigint = parseInt(n.length === 3 ? n.split('').map(c => c + c).join('') : n, 16);
    return { r: (bigint >> 16) & 255, g: (bigint >> 8) & 255, b: bigint & 255 };
  }

  getSelectedIdsCsv(delimiter = ','): string {
    return [...this.selected].map(String).join(delimiter);
  }

  /**
   * Resolve an image reference to a URL. v3 images are absolute URLs (or a
   * freshly uploaded one); bare file names are legacy relative paths.
   */
  getImageUrl(src: string): string {
    if (!src) return PLACEHOLDER_IMAGE;
    if (/^(https?:)?\/\//i.test(src) || src.startsWith('data:') || src.startsWith('assets/')) return src;
    return src.length > 100 ? src : this.image_url + src;
  }

  ngOnInit(): void {
    this.load$
      .pipe(
        switchMap(({ id, silent }) =>
          this.adapter.get_v3('GET /admin/products/:id', { params: { id: String(id) } }).pipe(
            map((res: any) => ({ id, silent, res, err: null as unknown })),
            catchError((err: unknown) => of({ id, silent, res: null as any, err })),
          ),
        ),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(({ id, silent, res, err }) => {
        if (id !== this.productId) return;
        this.ui_controls.refreshing = false;
        const p = res?.data ?? null;
        if (err || !p || typeof p !== 'object') {
          this.ui_controls.page_loading = false;
          if (silent) {
            // The save itself succeeded; only the refresh failed. Keep the form.
            this.error_notification(apiErrorMessage(err, 'Saved, but the product could not be refreshed.'));
          } else {
            this.load_error = apiErrorMessage(err, 'Unable to load this product.');
          }
          return;
        }
        this.applyAdminDetail(p);
        this.ui_controls.page_loading = false;
        this.get_product_history(this.productId);
      });

    this.labels$
      .pipe(
        switchMap((vendorId) =>
          this.adapter.get_v3('GET /admin/vendors/:id/labels', { params: { id: String(vendorId) } }).pipe(
            map((res: any) => ({ vendorId, res, err: null as unknown })),
            catchError((err: unknown) => of({ vendorId, res: null as any, err })),
          ),
        ),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe(({ vendorId, res, err }) => {
        if (vendorId !== this.vendorId) return;
        this.ui_controls.labels_loading = false;
        if (err) {
          this.labels = [];
          this.labels_error = apiErrorMessage(err, 'Unable to load this store\'s labels.');
          return;
        }
        const raw = res?.data ?? res;
        this.labels = Array.isArray(raw) ? raw : [];
      });

    // React to the id changing in place too: the top-bar search can navigate
    // from one product to another while this page stays mounted.
    combineLatest([this.route.paramMap, this.route.queryParamMap])
      .pipe(
        map(([params, query]) => params.get('id') ?? query.get('id') ?? ''),
        distinctUntilChanged(),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((raw) => this.openProduct(raw));

    this.get_category();

    this.colorOptions = [
      { id: 'black', text: 'Black', hex: '#000000' },
      { id: 'white', text: 'White', hex: '#FFFFFF' },
      { id: 'off-white', text: 'Off White', hex: '#FAF9F6' },
      { id: 'charcoal', text: 'Charcoal', hex: '#333333' },
      { id: 'gray', text: 'Gray', hex: '#808080' },
      { id: 'light-gray', text: 'Light Gray', hex: '#D3D3D3' },
      { id: 'beige', text: 'Beige', hex: '#F5F5DC' },
      { id: 'tan', text: 'Tan', hex: '#D2B48C' },
      { id: 'camel', text: 'Camel', hex: '#C19A6B' },
      { id: 'brown', text: 'Brown', hex: '#8B4513' },
      { id: 'chocolate', text: 'Chocolate', hex: '#5D3A00' },
      { id: 'navy', text: 'Navy', hex: '#001F3F' },
      { id: 'blue', text: 'Blue', hex: '#1F75FE' },
      { id: 'light-blue', text: 'Light Blue', hex: '#87CEEB' },
      { id: 'sky-blue', text: 'Sky Blue', hex: '#00BFFF' },
      { id: 'denim', text: 'Denim', hex: '#274472' },
      { id: 'teal', text: 'Teal', hex: '#008080' },
      { id: 'aqua', text: 'Aqua', hex: '#00FFFF' },
      { id: 'mint', text: 'Mint', hex: '#98FF98' },
      { id: 'green', text: 'Green', hex: '#2E8B57' },
      { id: 'lime', text: 'Lime', hex: '#32CD32' },
      { id: 'olive', text: 'Olive', hex: '#808000' },
      { id: 'forest', text: 'Forest Green', hex: '#228B22' },
      { id: 'red', text: 'Red', hex: '#C0392B' },
      { id: 'crimson', text: 'Crimson', hex: '#DC143C' },
      { id: 'burgundy', text: 'Burgundy', hex: '#800020' },
      { id: 'pink', text: 'Pink', hex: '#FFC0CB' },
      { id: 'hot-pink', text: 'Hot Pink', hex: '#FF69B4' },
      { id: 'rose', text: 'Rose', hex: '#FF007F' },
      { id: 'purple', text: 'Purple', hex: '#800080' },
      { id: 'lavender', text: 'Lavender', hex: '#E6E6FA' },
      { id: 'violet', text: 'Violet', hex: '#8A2BE2' },
      { id: 'orange', text: 'Orange', hex: '#FF8C00' },
      { id: 'peach', text: 'Peach', hex: '#FFDAB9' },
      { id: 'coral', text: 'Coral', hex: '#FF7F50' },
      { id: 'yellow', text: 'Yellow', hex: '#FFD200' },
      { id: 'mustard', text: 'Mustard', hex: '#FFDB58' },
      { id: 'gold', text: 'Gold (Metallic)', hex: '#D4AF37' },
      { id: 'silver', text: 'Silver (Metallic)', hex: '#C0C0C0' },
      { id: 'bronze', text: 'Bronze', hex: '#CD7F32' },
      { id: 'champagne', text: 'Champagne', hex: '#F7E7CE' },
      { id: 'ivory', text: 'Ivory', hex: '#FFFFF0' },
      { id: 'multicolor', text: 'Multicolor', hex: 'linear-gradient(90deg, red, orange, yellow, green, blue, indigo, violet)' },
    ];
  }

  /** Reset the page and load the product with v3 id `raw` (route value). */
  private openProduct(raw: string) {
    const id = Number(raw);
    this.productId = Number.isInteger(id) && id > 0 ? id : 0;
    this.update = this.emptyModel();
    this.selected = new Set<string>();
    this.extraSizes = [];
    this.currentLabelId = null;
    this.featuredChanged = false;
    // Orphan any featured-image upload still running for the previous product.
    this.featuredUploadSeq++;
    this.ui_controls.featured_uploading = false;
    this.featured_progress = 0;
    this.baseline = {};
    this.vendorId = 0;
    this.vendorName = '';
    this.productStatus = '';
    this.labels = undefined;
    this.labels_error = '';
    this.newLabelName = '';
    this.history = [];
    this.history_error = '';
    this.historyExpanded.clear();
    this.load_error = '';
    this.ui_controls.refreshing = false;
    this.ui_controls.is_creating_label = false;

    if (!this.productId) {
      this.ui_controls.page_loading = false;
      this.load_error = 'This link has no valid product id.';
      return;
    }
    this.ui_controls.page_loading = true;
    this.load$.next({ id: this.productId, silent: false });
  }

  retryLoad() {
    if (!this.productId) return;
    this.load_error = '';
    this.ui_controls.page_loading = true;
    this.load$.next({ id: this.productId, silent: false });
  }

  goBack() {
    this.location.back();
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get canSave(): boolean {
    return this.productId > 0 && !this.load_error && !this.ui_controls.page_loading
      && !this.ui_controls.is_loading && !this.ui_controls.refreshing
      && !this.ui_controls.featured_uploading;
  }

  updateProduct() {
    if (!this.canSave) return;
    if (!String(this.update.name ?? '').trim()) {
      this.error_notification('Name is required');
      return;
    }
    if (!(Number(this.update.price) > 0)) {
      this.error_notification('Price must be greater than zero');
      return;
    }
    this.update.colors = this.getSelectedIdsCsv();
    this.ui_controls.is_loading = true;
    const productId = this.productId;
    const body = this.buildUpdatePayload();
    this.adapter.put_v3('PUT /admin/products/:id', body, { params: { id: String(productId) } }).subscribe({
      next: () => {
        this.ui_controls.is_loading = false;
        this.success_notification('Product updated.');
        // The page may have moved on to another product (top-bar search)
        // while the save was in flight; only refresh the one that was saved.
        if (productId !== this.productId) return;
        // Re-read the saved product (and its history) so the page shows what
        // was actually persisted, not just what was typed.
        this.ui_controls.refreshing = true;
        this.load$.next({ id: productId, silent: true });
      },
      error: (e: any) => {
        console.error(e);
        // A 422 on label_id (another store's / deleted label) carries a
        // field message; apiErrorMessage surfaces it. Nothing was saved.
        this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
        this.ui_controls.is_loading = false;
      },
    });
  }

  /**
   * PUT body (VendorProductInput + label_id). Core fields are always sent;
   * the rest only when the admin changed them, so a field the admin detail
   * shape doesn't return (or a value the form had to default) never
   * overwrites the stored one. sale_price is never sent (this page has no
   * sale-price input; omitting the key keeps the stored discount).
   */
  private buildUpdatePayload(): Record<string, unknown> {
    const u = this.update;
    const payload: Record<string, unknown> = {
      name: String(u.name ?? '').trim(),
      description: u.description ?? '',
      price: Number(u.price),
    };
    const categoryId = Number(u.category) || 0;
    if (categoryId > 0) payload['category_id'] = categoryId;

    const values = this.optionalPayloadValues();
    for (const [key, value] of Object.entries(values)) {
      if (this.comparable(value) !== this.baseline[key]) payload[key] = value;
    }
    // Stock fields travel together: the API auto-flips stock_status when only
    // stock_quantity is sent, so an unchanged status/oversell rides along with
    // any stock change and the admin's explicit choice wins.
    if (STOCK_KEYS.some((k) => k in payload)) {
      for (const k of STOCK_KEYS) payload[k] = values[k];
    }

    if (this.featuredChanged && this.update.image_1 && !String(this.update.image_1).includes('placeholder')) {
      payload['primary_image_url'] = this.update.image_1;
    }

    // Store label. Must be an active label of THIS product's store (the API
    // 422s otherwise); re-sending the current one is a no-op. There is no
    // "clear": absent keeps the stored label.
    const labelId = Number(u.label) || 0;
    if (labelId > 0) payload['label_id'] = labelId;
    return payload;
  }

  /** Fields sent only when they differ from what was loaded. */
  private optionalPayloadValues(): Record<string, unknown> {
    const u = this.update;
    return {
      sizes: this.selectedSizeLabels(),
      colors: [...this.selected],
      delivery_info: {
        time: u.delivery_time || null,
        custom_time: u.delivery_time === 'custom' ? (u.custom_delivery_time || null) : null,
        note: u.delivery_note || null,
      },
      stock_status: u.stock_status,
      stock_quantity: Math.max(0, Math.trunc(Number(u.quantity) || 0)),
      allow_oversell: !!u.allow_checkout_when_out_of_stock,
      // Positive whole number, or null (blank / 0): a stored null ("no
      // limit") loads blank and stays unsent unless the admin enters a limit.
      min_order_qty: positiveIntOrNull(u.minimum_order_quantity),
      max_order_qty: positiveIntOrNull(u.maximum_order_quantity),
      cost_per_item: Math.max(0, Number(u.cost_per_item) || 0),
      is_featured: !!u.is_featured,
      requires_extra_msmt: !!u.require_extra_msmt,
      // A string, so clearing the text sends '' and actually clears it (the
      // API ignores null). A stored null loads as '' and so stays unsent.
      extra_msmt: String(u.extra_msmt ?? ''),
    };
  }

  /** Order-insensitive comparison key (arrays are compared as sets). */
  private comparable(v: unknown): string {
    if (Array.isArray(v)) return JSON.stringify([...v].map(String).sort());
    return JSON.stringify(v ?? null);
  }

  private selectedSizeLabels(): string[] {
    const fromFlags = Object.entries(SIZE_FLAGS)
      .filter(([flag]) => !!this.update[flag])
      .map(([, label]) => label);
    return [...fromFlags, ...this.extraSizes.filter((s) => !fromFlags.includes(s))];
  }

  /**
   * Map the admin detail shape (ProductSerializer::vendorDetailShape) onto
   * the page model. Tolerates storefront-ish variants of each field too.
   */
  private applyAdminDetail(p: any) {
    const u = this.emptyModel();
    u.name = p.name ?? '';
    u.description = p.description ?? '';
    u.price = Number(p.price?.amount ?? p.price ?? 0) || 0;
    if (p.cost_per_item != null) u.cost_per_item = Number(p.cost_per_item) || 0;
    // `category` is the category NAME in this shape; the id is `category_id`.
    // Number-coerce so the radio ([value]="c.id") pre-selects.
    u.category = Number(p.category_id ?? p.category?.id ?? 0) || 0;
    u.quantity = Number(p.stock_quantity ?? p.quantity ?? 0) || 0;
    u.stock_status = p.stock_status || 'in_stock';
    u.allow_checkout_when_out_of_stock = !!(p.allow_oversell ?? p.allow_checkout_when_out_of_stock);
    // Detail-shape keys min_order_quantity / max_order_quantity (the _qty
    // keys are identical aliases). Null ("no limit") stays null, shown blank.
    u.minimum_order_quantity = positiveIntOrNull(p.min_order_quantity ?? p.min_order_qty);
    u.maximum_order_quantity = positiveIntOrNull(p.max_order_quantity ?? p.max_order_qty);
    u.is_featured = !!p.is_featured;
    u.is_hot = !!p.is_hot;
    u.is_new = !!p.is_new;
    u.is_sale = !!p.is_sale;
    u.require_extra_msmt = !!(p.requires_extra_msmt ?? p.requires_measurement);
    u.extra_msmt = p.extra_msmt ?? p.measurement_instructions ?? '';

    const di = p.delivery_info ?? {};
    u.delivery_time = di.time ?? '';
    u.custom_delivery_time = di.custom_time ?? '';
    u.delivery_note = di.note ?? '';

    // Images: the shape returns [{url, alt, …}] (primary first) plus a nested
    // primary_image and a flat `image`; tolerate bare strings too.
    const primary = p.primary_image?.url ?? p.primary_image_url ?? p.image ?? p.image_1 ?? '';
    u.image_1 = primary && !String(primary).includes('placeholder') ? primary : PLACEHOLDER_IMAGE;
    const rawImgs = Array.isArray(p.images) ? p.images : (Array.isArray(p.image_urls) ? p.image_urls : []);
    u.images = rawImgs
      .map((img: any) => (typeof img === 'string' ? img : img?.url))
      .filter((src: any) => typeof src === 'string' && src && !src.includes('placeholder'));

    // Label (single, store-scoped).
    const labelId = Number(p.label_id ?? 0) || 0;
    u.label = labelId;
    this.currentLabelId = labelId > 0 ? labelId : null;

    // Sizes: [{label, in_stock}] (or bare strings) → checkbox flags; anything
    // without a checkbox here is kept as-is.
    const sizeLabels: string[] = (Array.isArray(p.sizes) ? p.sizes : [])
      .map((s: any) => String(typeof s === 'string' ? s : s?.label ?? '').trim())
      .filter(Boolean);
    const known = new Set(Object.values(SIZE_FLAGS));
    for (const [flag, label] of Object.entries(SIZE_FLAGS)) {
      u[flag] = sizeLabels.some((s) => s.toUpperCase() === label);
    }
    this.extraSizes = sizeLabels.filter((s) => !known.has(s.toUpperCase()));

    // Colours: [{label, hex_code}] (or strings / CSV) → selected set + CSV.
    const colorVals: string[] = Array.isArray(p.colors)
      ? p.colors.map((c: any) => (typeof c === 'string' ? c : c?.label)).filter(Boolean).map(String)
      : String(p.colors ?? '').split(',').map((s) => s.trim()).filter(Boolean);
    this.selected = new Set(colorVals);
    u.colors = colorVals.join(',');

    // Owning store (v3 id); labels are loaded for it.
    const vendorId = Number(p.vendor_id ?? p.vendor?.id ?? 0) || 0;
    u.store = vendorId;
    this.vendorName = p.store_name ?? p.vendor?.name ?? (vendorId ? `Store #${vendorId}` : '');
    this.productStatus = p.status ?? '';

    this.update = u;
    this.featuredChanged = false;
    this.baseline = Object.fromEntries(
      Object.entries(this.optionalPayloadValues()).map(([k, v]) => [k, this.comparable(v)]),
    );

    if (vendorId !== this.vendorId || this.labels === undefined) {
      this.vendorId = vendorId;
      this.get_store_labels();
    }
  }

  private emptyModel(): any {
    return {
      store: 0,
      category: 0,
      name: '',
      description: '',
      image_1: PLACEHOLDER_IMAGE,
      images: [] as string[],
      quantity: 0,
      allow_checkout_when_out_of_stock: false,
      with_storehouse_management: false,
      stock_status: 'in_stock',
      price: 0,
      minimum_order_quantity: null,
      maximum_order_quantity: null,
      cost_per_item: 0,
      delivery_time: '',
      custom_delivery_time: '',
      size_xs: false, size_s: false, size_m: false, size_l: false,
      size_xl: false, size_xxl: false,
      size_50: false, size_52: false, size_54: false, size_56: false,
      size_58: false, size_60: false, size_62: false,
      require_extra_msmt: false,
      extra_msmt: '',
      size_custom: false,
      is_hot: false, is_new: false, is_sale: false, is_featured: false,
      delivery_note: '',
      colors: '',
      label: 0,
    };
  }

  // ── Store labels (the PRODUCT's store, via the admin endpoints) ───────

  get_store_labels() {
    this.labels_error = '';
    if (!this.vendorId) {
      this.labels = [];
      this.ui_controls.labels_loading = false;
      return;
    }
    this.labels = undefined;
    this.ui_controls.labels_loading = true;
    this.labels$.next(this.vendorId);
  }

  /** The product's stored label is not among the store's active labels. */
  get currentLabelInactive(): boolean {
    return this.currentLabelId != null && Array.isArray(this.labels)
      && !this.labels.some((l) => Number(l.id) === this.currentLabelId);
  }

  create_store_label() {
    const name = this.newLabelName.trim();
    if (!name) {
      this.error_notification('Label name is required');
      return;
    }
    if (!this.vendorId) {
      this.error_notification('This product has no store to add a label to.');
      return;
    }
    const vendorId = this.vendorId;
    this.ui_controls.is_creating_label = true;
    this.adapter.post_v3('POST /admin/vendors/:id/labels', { label: name }, { params: { id: String(vendorId) } }).subscribe({
      next: (response: any) => {
        this.ui_controls.is_creating_label = false;
        if (vendorId !== this.vendorId) return;
        this.newLabelName = '';
        this.success_notification(`Label "${name}" added to ${this.vendorName || 'this store'}.`);
        // Select the new label; it takes effect on Save.
        const createdId = Number(response?.data?.id ?? response?.id ?? 0) || 0;
        if (createdId > 0) this.update.label = createdId;
        this.get_store_labels();
      },
      error: (e: any) => {
        console.error(e);
        this.ui_controls.is_creating_label = false;
        this.error_notification(apiErrorMessage(e, 'Unable to create the label.'));
      },
    });
  }

  // ── Featured image ───────────────────────────────────────────────────

  async select_image_1(event: any) {
    const input = event?.target as HTMLInputElement | null;
    const file = input?.files?.[0];
    if (!file) return;
    // A (post-save) re-read would replace the model and drop the new image.
    if (!this.productId || this.ui_controls.page_loading || this.ui_controls.refreshing) {
      if (input) input.value = '';
      return;
    }
    // Tie this upload to the product it started on (see featuredUploadSeq).
    const forId = this.productId;
    const seq = ++this.featuredUploadSeq;
    const isCurrent = () => seq === this.featuredUploadSeq && forId === this.productId;
    this.ui_controls.featured_uploading = true;
    this.featured_progress = 0;
    try {
      // Compress, then upload; the API stores the URL (base64 isn't accepted).
      const compressed = await imageCompression(file, {
        maxSizeMB: 3, maxWidthOrHeight: 1920, useWebWorker: true,
        onProgress: (p: number) => { if (isCurrent()) this.featured_progress = Math.round(p * 0.4); },
      });
      if (!isCurrent()) return;
      const result = await this.imageUpload.upload(compressed, 'product',
        (p) => { if (isCurrent()) this.featured_progress = 40 + Math.round(p * 0.6); });
      // The admin opened another product meanwhile: never apply it there.
      if (!isCurrent()) return;
      this.update.image_1 = result.url;
      this.featuredChanged = true;
      this.featured_progress = 100;
    } catch (e) {
      console.error(e);
      if (isCurrent()) {
        this.error_notification(apiErrorMessage(e, 'Image upload failed. Please try again.'));
      }
    } finally {
      // openProduct() already reset the flags for a superseded upload.
      if (isCurrent()) this.ui_controls.featured_uploading = false;
      if (input) input.value = '';
    }
  }

  get_category() {
    // Deliberately doesn't set ui_controls.page_loading: categories load in
    // the background and must never hold the page behind the spinner.
    this.adapter.get_v3('GET /utility/categories').pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (response: any) => {
        const raw = response?.data ?? response;
        if (Array.isArray(raw)) this.category = raw;
      },
      error: (e: any) => this.error_notification(apiErrorMessage(e, 'Unable to load categories.')),
    });
  }

  /**
   * Load the product's change history (audit timeline) from the append-only
   * audit_log, keyed by the same v3 product id the page loads + saves with.
   * Newest first; actor names are denormalised server-side.
   */
  get_product_history(productId: number) {
    this.history_loading = true;
    this.history_error = '';
    this.adapter.get_v3('GET /admin/products/:id/history', { params: { id: String(productId) } }).subscribe({
      next: (res: any) => {
        if (productId !== this.productId) return;
        // Envelope shape is defensive: `logs` may sit at the top level or
        // under `data`, matching the audit-log console's own access.
        const body = res?.logs ? res : (res?.data ?? res);
        this.history = Array.isArray(body?.logs) ? body.logs : [];
        this.history_loading = false;
      },
      error: (e: any) => {
        if (productId !== this.productId) return;
        this.history_loading = false;
        this.history_error = apiErrorMessage(e, 'Unable to load product history.');
      },
    });
  }

  start_update() {
    if (!this.canSave) return;
    this.confirm
      .confirm({
        title: 'Confirm update',
        message: 'Save your changes. Your product update will go live immediately.',
        confirmLabel: 'Save',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) this.updateProduct();
      });
  }

  // ── History presentation helpers ─────────────────────────────────────

  historyActionMeta(action: string) {
    return HISTORY_ACTION_META[action] ?? HISTORY_ACTION_META['default'];
  }

  historyActorName(log: any): string {
    const a = log?.actor;
    if (!a) return 'System';
    return a.name || a.email || `User #${a.id}`;
  }

  historyActorInitials(log: any): string {
    if (!log?.actor) return 'SYS';
    const name = this.historyActorName(log);
    const parts = (name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    return (parts[0][0] + (parts[1]?.[0] ?? '')).toUpperCase();
  }

  /**
   * Normalise a log's `changes` payload into before→after rows. Handles the
   * diff shape ({ before, after }), create/delete (only one side present) and
   * the rare flat map. Empty when there's nothing structured to show.
   */
  historyChangeRows(log: any): HistoryChange[] {
    const c = log?.changes;
    if (!c || typeof c !== 'object') return [];

    const hasBefore = c.before && typeof c.before === 'object';
    const hasAfter = c.after && typeof c.after === 'object';
    if (hasBefore || hasAfter) {
      const before = c.before ?? {};
      const after = c.after ?? {};
      const keys = Array.from(new Set([...Object.keys(before), ...Object.keys(after)])).sort();
      return keys.map((k) => ({
        field: this.humanizeKey(k),
        before: this.formatValue(before[k]),
        after: this.formatValue(after[k]),
      }));
    }

    return Object.entries(c)
      .filter(([k]) => k !== 'before' && k !== 'after')
      .map(([k, v]) => ({ field: this.humanizeKey(k), before: '', after: this.formatValue(v) }));
  }

  /** Rows to actually render for a log, respecting the collapsed preview cap. */
  historyVisibleRows(log: any): HistoryChange[] {
    const rows = this.historyChangeRows(log);
    if (this.isHistoryExpanded(log?.id) || rows.length <= HISTORY_PREVIEW_ROWS) {
      return rows;
    }
    return rows.slice(0, HISTORY_PREVIEW_ROWS);
  }

  historyHiddenCount(log: any): number {
    const total = this.historyChangeRows(log).length;
    return total > HISTORY_PREVIEW_ROWS ? total - HISTORY_PREVIEW_ROWS : 0;
  }

  isHistoryExpanded(id: number): boolean {
    return this.historyExpanded.has(id);
  }

  toggleHistoryEntry(id: number) {
    if (this.historyExpanded.has(id)) {
      this.historyExpanded.delete(id);
    } else {
      this.historyExpanded.add(id);
    }
  }

  relativeTime(iso: string): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const s = Math.max(0, Math.round((Date.now() - then) / 1000));
    if (s < 45) return 'just now';
    const m = Math.round(s / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h}h ago`;
    const d = Math.round(h / 24);
    if (d < 30) return `${d}d ago`;
    const mo = Math.round(d / 30);
    if (mo < 12) return `${mo}mo ago`;
    return `${Math.round(mo / 12)}y ago`;
  }

  absoluteTime(iso: string): string {
    if (!iso) return '';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('en-AE');
  }

  private humanizeKey(k: string): string {
    return k
      .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/\b\w/g, (c) => c.toUpperCase());
  }

  private formatValue(v: any): string {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? 'Yes' : 'No';
    if (typeof v === 'object') { try { return JSON.stringify(v); } catch { return String(v); } }
    return String(v);
  }
}
