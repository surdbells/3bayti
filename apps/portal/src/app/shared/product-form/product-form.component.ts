import { Component, EventEmitter, inject, Input, OnInit, Output } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import imageCompression from 'browser-image-compression';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { ImageUploadService } from '../../services/image-upload.service';
import { HotToastService } from '../toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { Category } from '../../class/category';
import { Labels } from '../../class/labels';

import { AxRichEditorComponent } from '../rich/ax-rich-editor.component';
import { AxConfirmService } from '../overlays';
import { IconComponent } from '../icon/icon.component';
import {
  AxFileUploadComponent,
  AxUploadFile,
} from '../rich/ax-file-upload.component';
import {
  AxMultiselectComponent,
  AxMultiselectOption,
} from '../forms/ax-multiselect.component';
import { AxComboboxComponent, AxComboboxOption } from '../forms/ax-combobox.component';
import {
  AxAccordionComponent,
  AxAccordionItemComponent,
} from '../overlays';

interface ColorOption {
  id: string;
  text: string;
  hex: string;
}

interface VendorOption {
  id: number;
  name: string;
}

const COLOR_OPTIONS: ColorOption[] = [
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
];

const SIZED_CATEGORIES = [1, 2, 3, 6, 7];

const SIZE_MAP: Record<string, string> = {
  size_xs: 'XS', size_s: 'S', size_m: 'M', size_l: 'L', size_xl: 'XL', size_xxl: 'XXL',
  size_50: '50', size_51: '51', size_52: '52', size_53: '53', size_54: '54', size_55: '55',
  size_56: '56', size_57: '57', size_58: '58', size_59: '59', size_60: '60', size_61: '61',
  size_62: '62', size_63: '63', size_64: '64',
};

/**
 * Shared product create/edit form used by both the vendor pages
 * (create-product, edit-product) and the admin product pages.
 *
 * Behaviour is driven by inputs:
 *   - mode='create'|'edit'   — controls submit verb + page copy.
 *   - adminMode=true          — targets the /admin/products write API and
 *                               shows a required vendor/store selector
 *                               (admins create/edit on behalf of a vendor).
 *   - productId               — when editing, the product to load.
 *
 * The component owns the entire form (fields, images, colors, sizes,
 * collections, labels), self-loads on edit, self-submits to the correct
 * endpoint, and navigates back on success. The host page only supplies
 * the shell + these inputs, so the form stays shell-agnostic.
 */
@Component({
  selector: 'app-product-form',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    AxRichEditorComponent,
    AxFileUploadComponent,
    AxMultiselectComponent,
    AxAccordionComponent,
    AxAccordionItemComponent,
    IconComponent,
    AxComboboxComponent,
  ],
  templateUrl: './product-form.component.html',
  styleUrl: './product-form.component.css',
})
export class ProductFormComponent implements OnInit {
  @Input() mode: 'create' | 'edit' = 'create';
  @Input() adminMode = false;
  @Input() productId = 0;
  /** Optional slug — preferred edit-load key when present (admin list
   *  exposes slug + v3 id but not the legacy id). */
  @Input() productSlug = '';
  /** Where to navigate after a successful save (defaults per role). */
  @Input() returnTo: string | null = null;
  /** Admin create only: pre-select this vendor/store in the selector
   *  (e.g. "New product" opened from a specific store's product page). */
  @Input() vendorId = 0;
  @Output() saved = new EventEmitter<void>();

  private readonly confirm = inject(AxConfirmService);

  // ── Lookup data ───────────────────────────────────────────────
  category?: Category[];
  labels?: Labels[];
  colorOptions: ColorOption[] = COLOR_OPTIONS;
  vendors: VendorOption[] = [];
  /** Admin edit only: the owning store's name, shown read-only (a product's
   *  store is fixed and can't be reassigned from the editor). */
  editVendorName = '';
  /** Store selector options (searchable combobox). */
  get vendorOptions(): AxComboboxOption[] {
    return this.vendors.map((v) => ({ id: v.id, label: v.name }));
  }
  readonly deliveryTimeOptions: AxComboboxOption[] = [
    { id: '1-3', label: '1 – 3 days' },
    { id: '4-7', label: '4 – 7 days' },
    { id: '14-21', label: '14 – 21 days' },
    { id: 'custom', label: 'Custom' },
  ];

  dropdownList: { id: number; collection: string }[] = [];
  selectedCollectionIds: (string | number)[] = [];

  get collectionOptions(): AxMultiselectOption[] {
    return this.dropdownList.map((c) => ({ id: c.id, label: c.collection }));
  }
  get selectedItemsForPayload(): { id: number; collection: string }[] {
    const ids = new Set(this.selectedCollectionIds.map(String));
    return this.dropdownList.filter((c) => ids.has(String(c.id)));
  }

  // ── Images ────────────────────────────────────────────────────
  featuredFiles: AxUploadFile[] = [];
  galleryFiles: AxUploadFile[] = [];
  private galleryUrls: string[] = [];
  existingImages: string[] = [];

  // ── Colors ────────────────────────────────────────────────────
  selected = new Set<string>();

  // ── Session ───────────────────────────────────────────────────
  private session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  ui = { loading: false, creating_label: false, page_loading: false };

  // ── Form model ────────────────────────────────────────────────
  model = {
    vendor_id: null as number | null, // admin mode only
    category: 0 as number,
    name: '', description: '',
    image_1: 'assets/img/placeholder-1.png',
    images: [] as string[],
    quantity: 0,
    allow_checkout_when_out_of_stock: false,
    with_storehouse_management: false,
    stock_status: 'in_stock',
    price: 0, cost_per_item: 0, sale_price: null as number | null,
    minimum_order_quantity: 1, maximum_order_quantity: 1,
    delivery_time: '', custom_delivery_time: '', delivery_note: '',
    size_xs: false, size_s: false, size_m: false, size_l: false, size_xl: false, size_xxl: false,
    size_50: false, size_51: false, size_52: false, size_53: false, size_54: false, size_55: false,
    size_56: false, size_57: false, size_58: false, size_59: false, size_60: false, size_61: false,
    size_62: false, size_63: false, size_64: false,
    size_custom: false,
    require_extra_msmt: false, extra_msmt: '',
    is_featured: false, is_hot: false, is_new: false, is_sale: false,
    colors: '', status: '', label: 0,
  };

  vendor_label_create = { id: 0, token: '', label: '' };

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private imageUpload: ImageUploadService,
    private toast: HotToastService,
  ) {}

  // ═══════════════════════════════════════════════════════════════
  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.vendor_label_create.id = this.user_session.id;
    this.vendor_label_create.token = this.user_session.token;

    this.fetchCategory();
    this.fetchCollections();
    this.fetchVendorLabels();
    if (this.adminMode) this.fetchVendors();
    // Pre-select the store when an admin creates from a specific store's page.
    if (this.adminMode && this.mode === 'create' && this.vendorId) this.model.vendor_id = this.vendorId;
    if (this.mode === 'edit' && (this.productId || this.productSlug)) this.fetchProductById();
  }

  get isEdit(): boolean { return this.mode === 'edit'; }
  get pageTitle(): string { return this.isEdit ? 'Edit product' : 'Create product'; }
  get pageSubtitle(): string {
    return this.isEdit ? 'Update this product.' : 'Add a new product to the storefront.';
  }

  // ═══════════════════════════════════════════════════════════════
  //  DATA LOADING
  // ═══════════════════════════════════════════════════════════════
  fetchCategory(): void {
    this.ui.page_loading = this.mode === 'edit';
    this.adapter.get_v3('GET /utility/categories').subscribe({
      next: (response: any) => {
        if (response) this.category = response.data;
        if (this.mode !== 'edit') this.ui.page_loading = false;
      },
    });
  }

  fetchCollections(): void {
    this.adapter.get_v3('GET /vendor/collections', { query: { limit: 100, offset: 0 } }).subscribe({
      next: (res: any) => {
        this.dropdownList = (Array.isArray(res?.data) ? res.data : res?.data?.items ?? [])
          .map((col: any) => ({ id: col.id, collection: col.collection ?? col.name }));
      },
    });
  }

  fetchVendorLabels(): void {
    this.adapter.get_v3('GET /vendor/labels').subscribe({
      next: (response: any) => {
        if (response?.data) this.labels = Array.isArray(response.data) ? response.data : [];
      },
    });
  }

  fetchVendors(): void {
    this.adapter.get_v3('GET /admin/vendors', { query: { limit: 200, offset: 0 } }).subscribe({
      next: (res: any) => {
        const raw: any[] = res?.vendors ?? res?.data ?? [];
        this.vendors = raw
          .map((v: any) => ({ id: v.id, name: v.name ?? v.store_name ?? `Store #${v.id}` }))
          .sort((a, b) => a.name.localeCompare(b.name));
      },
    });
  }

  fetchProductById(): void {
    this.ui.page_loading = true;
    // Both admin and vendor edit load by v3 id through an ADMIN/OWNER-scoped
    // endpoint that returns the product in ANY status. The public
    // GET /products/{slug} was used before for admin, but it 404s on drafts
    // (and v3-native products), so the draft edit form rendered blank.
    const req = this.adminMode
      ? this.adapter.get_v3('GET /admin/products/:id', { params: { id: String(this.productId) } })
      : this.adapter.get_v3('GET /vendor/products/:id', { params: { id: String(this.productId) } });
    req.subscribe({
      next: (response: any) => {
        const p = response?.data;
        if (!p) { this.ui.page_loading = false; return; }
        // Populate flat fields, tolerating both v3 and legacy-ish shapes.
        this.model.name = p.name ?? '';
        this.model.description = p.description ?? '';
        this.model.price = Number(p.price?.amount ?? p.price ?? 0);
        this.model.cost_per_item = Number(p.cost_per_item ?? 0);
        // sale_price is serialized as a Money object ({amount,currency}) like
        // price, or absent/null when the product isn't discounted. Keep it null
        // when unset so the input renders blank (not "0").
        const rawSale = p.sale_price?.amount ?? p.sale_price;
        this.model.sale_price = rawSale == null || rawSale === '' ? null : Number(rawSale);
        // Coerce to Number so the category radio (bound via [value]="c.id",
        // a number) pre-selects even when the serializer emits a string id.
        this.model.category = Number(p.category?.id ?? p.category_id ?? p.category ?? 0) || 0;
        this.model.quantity = p.stock_quantity ?? p.quantity ?? 0;
        this.model.stock_status = p.stock_status ?? 'in_stock';
        this.model.allow_checkout_when_out_of_stock = !!(p.allow_oversell ?? p.allow_checkout_when_out_of_stock);
        // Coerce 0/blank to 1: the API requires a positive min/max order qty,
        // and `0 ?? 1` keeps 0 (0 isn't nullish) — which then fails on save.
        this.model.minimum_order_quantity = Number(p.min_order_qty ?? p.minimum_order_quantity) || 1;
        this.model.maximum_order_quantity = Number(p.max_order_qty ?? p.maximum_order_quantity) || 1;
        this.model.is_featured = !!p.is_featured;
        this.model.is_hot = !!p.is_hot;
        this.model.is_new = !!p.is_new;
        this.model.is_sale = !!p.is_sale;
        this.model.require_extra_msmt = !!(p.requires_extra_msmt ?? p.require_extra_msmt);
        this.model.extra_msmt = p.extra_msmt ?? '';
        this.model.status = p.status ?? 'active';
        if (this.adminMode) {
          this.model.vendor_id = p.vendor?.id ?? p.vendor_id ?? null;
          this.editVendorName = p.store_name ?? p.vendor?.name
            ?? (this.model.vendor_id ? `Store #${this.model.vendor_id}` : '—');
        }

        // Featured + gallery images. The vendor detail endpoint returns
        // images as [{url, alt, ...}]; tolerate both that and bare strings.
        const primary = p.primary_image?.url ?? p.primary_image_url ?? p.image ?? p.image_1 ?? '';
        if (primary && !String(primary).includes('placeholder')) this.model.image_1 = primary;
        const rawImgs = p.image_urls ?? p.images ?? [];
        this.existingImages = (Array.isArray(rawImgs) ? rawImgs : [])
          .map((img: any) => (typeof img === 'string' ? img : img?.url))
          .filter((src: string) => src && !src.includes('placeholder'));
        this.galleryUrls = [...this.existingImages];

        // Collections multiselect — the product holds a single collection_id;
        // tolerate a legacy `collection` array too.
        const serverCollection = p.collection ?? (p.collection_id != null ? [p.collection_id] : []);
        this.selectedCollectionIds = Array.isArray(serverCollection)
          ? serverCollection.map((c: any) => (typeof c === 'object' ? c.id : c))
          : [];
        // Label (single).
        if (p.label_id != null) this.model.label = p.label_id;

        // Sizes — v3 returns [{label, in_stock}]; tolerate bare strings/flags.
        const sizesArr: string[] = Array.isArray(p.sizes)
          ? p.sizes.map((s: any) => String(typeof s === 'string' ? s : s?.label ?? '').toUpperCase())
          : [];
        for (const [flag, label] of Object.entries(SIZE_MAP)) {
          if (sizesArr.includes(label)) (this.model as any)[flag] = true;
        }

        // Colors — v3 returns [{label, hex_code}]; tolerate array of strings or CSV.
        const colorVals: string[] = Array.isArray(p.colors)
          ? p.colors.map((c: any) => (typeof c === 'string' ? c : c?.label)).filter(Boolean)
          : String(p.colors ?? '').split(',').map((s) => s.trim()).filter(Boolean);
        for (const c of colorVals) this.selected.add(c);
        this.model.colors = colorVals.join(',');

        this.ui.page_loading = false;
      },
      error: () => { this.ui.page_loading = false; this.toast.error('Unable to load the product.'); },
    });
  }

  createVendorLabel(): void {
    if (!this.vendor_label_create.label.length) { this.toast.error('Label name is required'); return; }
    this.ui.creating_label = true;
    this.adapter.post_v3('POST /vendor/labels', this.vendor_label_create).subscribe({
      next: (response: any) => {
        this.ui.creating_label = false;
        if (response) {
          this.vendor_label_create.label = '';
          this.toast.success('Label created');
          this.fetchVendorLabels();
        }
      },
      error: () => { this.ui.creating_label = false; this.toast.error('Unable to create the label.'); },
    });
  }

  // ═══════════════════════════════════════════════════════════════
  //  COLOR SELECTION
  // ═══════════════════════════════════════════════════════════════
  trackById = (_: number, item: ColorOption) => item.id;
  toggle(id: string, checked: boolean): void { checked ? this.selected.add(id) : this.selected.delete(id); }
  isSelected(id: string): boolean { return this.selected.has(id); }
  get selectedColors(): ColorOption[] { return this.colorOptions.filter((c) => this.selected.has(c.id)); }
  needsBorder(hex: string): boolean {
    if (hex.startsWith('linear')) return false;
    const n = hex.replace('#', '');
    const bigint = parseInt(n.length === 3 ? n.split('').map((c) => c + c).join('') : n, 16);
    const r = (bigint >> 16) & 255, g = (bigint >> 8) & 255, b = bigint & 255;
    return (0.299 * r + 0.587 * g + 0.114 * b) > 220;
  }
  getSelectedIdsCsv(): string { return [...this.selected].join(','); }

  get showSizing(): boolean { return SIZED_CATEGORIES.includes(Number(this.model.category)); }

  // ═══════════════════════════════════════════════════════════════
  //  IMAGE HANDLING
  // ═══════════════════════════════════════════════════════════════
  async onFeaturedChange(files: AxUploadFile[]): Promise<void> {
    this.featuredFiles = files;
    const first = files[0];
    if (!first) { this.model.image_1 = 'assets/img/placeholder-1.png'; return; }
    try {
      const compressed = await imageCompression(first.file, { maxSizeMB: 3, maxWidthOrHeight: 1920, useWebWorker: true });
      const result = await this.imageUpload.upload(compressed, 'product');
      this.model.image_1 = result.url;
    } catch (error) {
      this.toast.error('Image upload failed: ' + error);
    }
  }

  async onGalleryChange(files: AxUploadFile[]): Promise<void> {
    if (files.length > 5) { this.toast.error('You can only add up to 5 gallery images'); files = files.slice(0, 5); }
    this.galleryFiles = files;
    try {
      const results = await Promise.all(
        files.map(async (uf) => {
          const compressed = await imageCompression(uf.file, { maxSizeMB: 3, maxWidthOrHeight: 1920, useWebWorker: true });
          const uploaded = await this.imageUpload.upload(compressed, 'product');
          return uploaded.url;
        }),
      );
      // New uploads plus any pre-existing images, capped at 5.
      this.galleryUrls = [...this.existingImages, ...results].slice(0, 5);
    } catch (error) {
      this.toast.error('Image compression failed: ' + error);
    }
  }

  // ═══════════════════════════════════════════════════════════════
  //  VALIDATION + SUBMIT
  // ═══════════════════════════════════════════════════════════════
  private validate(): boolean {
    // Store is only chosen at create time; on edit it's fixed (read-only), so
    // don't block the save on it — the product already belongs to a vendor.
    if (this.adminMode && !this.isEdit && !this.model.vendor_id) { this.toast.error('Select the store this product belongs to'); return false; }
    if (Number(this.model.category) === 0) { this.toast.error('Product category is required'); return false; }
    if (!this.model.name.length) { this.toast.error('Name is required'); return false; }
    if (!this.model.description.length) { this.toast.error('Briefly describe your product'); return false; }
    if (!this.model.image_1.length || this.model.image_1.includes('placeholder')) {
      this.toast.error('Featured image is required'); return false;
    }
    if (!this.isEdit && !this.model.delivery_time.length) { this.toast.error('Delivery time is required'); return false; }
    if (this.model.sale_price != null
        && Number(this.model.sale_price) > 0
        && Number(this.model.sale_price) >= Number(this.model.price)) {
      this.toast.error('Sale price must be lower than the regular price'); return false;
    }
    return true;
  }

  startPublish(): void {
    if (!this.validate()) return;
    this.confirm.confirm({
      title: this.isEdit ? 'Confirm changes' : 'Confirm publish',
      message: this.isEdit
        ? 'Save your changes. The updated product stays live for customers.'
        : 'Make this product live now. It will be visible to customers immediately. Please review price, stock, and images before publishing.',
      confirmLabel: this.isEdit ? 'Save' : 'Publish',
      cancelLabel: 'Cancel',
    }).then((ok) => { if (ok) this.submit('active'); });
  }

  startSave(): void {
    if (!this.validate()) return;
    this.confirm.confirm({
      title: 'Confirm save',
      message: "Save as a draft. It won't be visible to customers until you publish.",
      confirmLabel: 'Save',
      cancelLabel: 'Cancel',
    }).then((ok) => { if (ok) this.submit('draft'); });
  }

  private buildPayload(status: string): Record<string, unknown> {
    const d = this.model;
    const sizes = Object.entries(SIZE_MAP).filter(([k]) => (d as any)[k]).map(([, v]) => v);
    const colors = this.getSelectedIdsCsv().split(',').map((s) => s.trim()).filter(Boolean);
    const primaryImg = d.image_1 && !d.image_1.includes('placeholder') ? d.image_1 : (this.galleryUrls[0] ?? null);
    const galleryImgs = this.galleryUrls.filter((i) => i !== primaryImg).slice(0, 4);
    const payload: Record<string, unknown> = {
      name: d.name,
      description: d.description,
      price: d.price,
      cost_per_item: d.cost_per_item,
      // Send null (not 0) when blank so the product isn't flagged on-sale.
      sale_price:
        d.sale_price === null || (d.sale_price as any) === '' || Number.isNaN(Number(d.sale_price))
          ? null
          : Number(d.sale_price),
      category_id: d.category || null,
      stock_quantity: d.quantity,
      stock_status: d.stock_status,
      allow_oversell: d.allow_checkout_when_out_of_stock,
      min_order_qty: Math.max(1, Number(d.minimum_order_quantity) || 1),
      max_order_qty: Math.max(1, Number(d.maximum_order_quantity) || 1),
      primary_image_url: primaryImg,
      image_urls: galleryImgs,
      sizes,
      colors,
      is_featured: d.is_featured,
      is_new: d.is_new,
      is_hot: d.is_hot,
      is_sale: d.is_sale,
      requires_extra_msmt: d.require_extra_msmt,
      extra_msmt: d.extra_msmt || null,
      status,
    };
    // Collection + label assignment. The product schema holds a SINGLE
    // collection and a single label, so we persist the first selected
    // collection (the picker stays a multiselect for UX) and the chosen label.
    const firstCollection = this.selectedCollectionIds.length ? Number(this.selectedCollectionIds[0]) : null;
    if (firstCollection) payload['collection_id'] = firstCollection;
    if (d.label) payload['label_id'] = Number(d.label);
    // Admin writes specify which vendor the product belongs to.
    if (this.adminMode && d.vendor_id) payload['vendor_id'] = d.vendor_id;
    return payload;
  }

  private submit(status: string): void {
    this.ui.loading = true;
    const body = this.buildPayload(status);
    const done = (ok: boolean, msg: string) => {
      this.ui.loading = false;
      if (ok) {
        this.toast.success(msg);
        this.saved.emit();
        this.router.navigate([this.defaultReturn()]);
      } else {
        this.toast.error('Unable to save the product.');
      }
    };
    // Surface the API's actual reason (e.g. a per-field validation message)
    // instead of an opaque toast, so the vendor knows exactly what to fix.
    const fail = (err: unknown) => {
      this.ui.loading = false;
      this.toast.error(this.apiErrorMessage(err, 'Unable to complete your request at this time.'));
    };

    if (this.isEdit) {
      const key = this.adminMode ? 'PUT /admin/products/:id' : 'PUT /vendor/products/:id';
      this.adapter.put_v3(key as any, body, { params: { id: String(this.productId) } }).subscribe({
        next: (r: any) => done(!!r?.data?.id, 'Product updated successfully'), error: fail,
      });
    } else {
      const key = this.adminMode ? 'POST /admin/products' : 'POST /vendor/products';
      this.adapter.post_v3(key as any, body).subscribe({
        next: (r: any) => done(!!r?.data?.id, 'Product saved successfully'), error: fail,
      });
    }
  }

  /**
   * Best human-readable message from a v3 error envelope
   * ({ error: { message, details: { field: [msg] } } }). Prefers the first
   * field-level validation message, then the top-level message, then a fallback.
   */
  private apiErrorMessage(err: any, fallback: string): string {
    const body = err?.error?.error ?? err?.error;
    const details = body?.details;
    if (details && typeof details === 'object') {
      for (const key of Object.keys(details)) {
        const v = (details as Record<string, unknown>)[key];
        const msg = Array.isArray(v) ? v[0] : v;
        if (msg) return String(msg);
      }
    }
    return body?.message || fallback;
  }

  private defaultReturn(): string {
    if (this.returnTo) return this.returnTo;
    if (this.adminMode) return '/admin_products';
    return '/products';
  }

  goBack(): void {
    this.router.navigate([this.defaultReturn()]);
  }
}
