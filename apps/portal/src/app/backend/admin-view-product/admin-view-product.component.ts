import { Component, inject, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { CommonModule, Location } from '@angular/common';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { FormsModule } from '@angular/forms';

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
  AxMultiselectComponent,
  AxMultiselectOption,
} from '../../shared/forms/ax-multiselect.component';
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

@Component({
  selector: 'app-admin-view-product',
  standalone: true,
  imports: [CfImagePipe, 
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AxRichEditorComponent,
    AxMultiselectComponent,
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
  labels?: Labels[];

  /** Server list of collections. */
  dropdownList: { id: number; collection: string }[] = [];
  /** Ids selected by the AxMultiselect. */
  selectedCollectionIds: (string | number)[] = [];

  get collectionOptions(): AxMultiselectOption[] {
    return this.dropdownList.map(c => ({ id: c.id, label: c.collection }));
  }

  get selectedItemsForPayload(): { id: number; collection: string }[] {
    const ids = new Set(this.selectedCollectionIds.map(String));
    return this.dropdownList.filter(c => ids.has(String(c.id)));
  }

  private readonly confirm = inject(AxConfirmService);
  colorOptions: ColorOption[] = [];
  base64String: any;

  ui_controls = {
    is_loading: false,
    is_creating_label: false,
    page_loading: false,
    nav_open: false,
  };

  session_data: any = '';
  image_url: any = 'https://api-v3.3bayti.ae/vendors/products/';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  update: any = {
    id: 0,
    token: '',
    product: 0,
    store: 0,
    category: 0,
    name: '',
    description: '',
    image_1: 'assets/img/placeholder-1.png',
    images: [] as string[],
    collection: {},
    quantity: 0,
    allow_checkout_when_out_of_stock: false,
    with_storehouse_management: false,
    stock_status: 'in_stock',
    price: 0,
    minimum_order_quantity: 1,
    maximum_order_quantity: 1,
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

  vendor_labels = { id: 0, token: '' };
  vendor_label_create = { id: 0, token: '', label: '' };
  single_product = { id: 0, product: 0, token: '' };

  // ── Change history (audit timeline) ──────────────────────────────────
  history: any[] = [];
  history_loading = false;
  history_error = '';
  private readonly historyExpanded = new Set<number>();

  selected = new Set<string>();
  trackById = (_: number, item: ColorOption) => item.id;

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private location: Location,
    private adapter: PortalCrudAdapter,
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

  getImageUrl(src: string): string {
    if (!src) return 'assets/img/placeholder-1.png';
    return src.length > 100 ? src : this.image_url + src;
  }

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    const productId = Number(this.route.snapshot.paramMap.get('id') ?? this.route.snapshot.queryParamMap.get('id'));

    this.update.id = this.user_session.id;
    this.update.token = this.user_session.token;
    this.update.product = productId;

    this.single_product.id = this.user_session.id;
    this.single_product.token = this.user_session.token;
    this.single_product.product = productId;

    this.vendor_labels.id = this.user_session.id;
    this.vendor_labels.token = this.user_session.token;
    this.vendor_label_create.id = this.user_session.id;
    this.vendor_label_create.token = this.user_session.token;

    this.get_product_by_id();
    this.get_category();
    this.get_collections();
    this.get_vendor_labels();

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

  goBack() {
    this.location.back();
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  updateProduct() {
    this.update.colors = this.getSelectedIdsCsv();
    this.update.collection = this.selectedItemsForPayload;
    this.ui_controls.is_loading = true;
    const avp2Id = this.update.product_id ?? this.update.id;
    this.adapter.put_v3('PUT /admin/products/:id', this.update, { params: { id: String(avp2Id) } }).subscribe({
      next: (response: any) => {
        this.ui_controls.is_loading = false;
        if (response) {
          this.success_notification(response.message);
        } else if (false) {
          this.error_notification(response.message);
        }
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
        this.ui_controls.is_loading = false;
      },
    });
  }

  create_vendor_labels() {
    if (this.vendor_label_create.label.length === 0) {
      this.error_notification('Label name is required');
      return;
    }
    this.ui_controls.is_creating_label = true;
    this.adapter.post_v3('POST /vendor/labels', this.vendor_label_create).subscribe({
      next: (response: any) => {
        this.ui_controls.is_creating_label = false;
        if (response) {
          this.vendor_label_create.label = '';
          this.success_notification(response.message);
          this.get_vendor_labels();
        } else if (false) {
          this.error_notification(response.message);
        }
      },
      error: (e: any) => {
        console.error(e);
        this.ui_controls.is_creating_label = false;
        this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
      },
    });
  }

  select_image_1(event: any) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onloadend = () => {
      this.base64String = reader.result as string;
      this.update.image_1 = this.base64String;
    };
    reader.readAsDataURL(file);
  }

  get_category() {
    this.ui_controls.page_loading = true;
    this.adapter.get_v3('GET /utility/categories').subscribe({
      next: (response: any) => {
        if (response) {
          this.category = response.data;
          this.get_vendor_labels();
        }
      },
    });
  }

  get_vendor_labels() {
    this.adapter.get_v3('GET /vendor/labels').subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.labels = Array.isArray(response.data) ? response.data : [];
        }
      },
    });
  }

  get_collections() {
    this.adapter.get_v3('GET /admin/collections', { query: { limit: 100, offset: 0 } }).subscribe({
      next: (res: any) => {
        this.dropdownList = (Array.isArray(res?.data) ? res.data : res?.data?.items ?? []).map((col: any) => ({ id: col.id, collection: col.collection ?? col.name }));
      },
    });
    this.ui_controls.page_loading = false;
  }

  get_product_by_id() {
    const avp2GId = this.single_product.product ?? this.single_product.id;
    this.adapter.get_v3('GET /products/by-legacy-id/:id', { params: { id: String(avp2GId) } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.update = response.data;

          // The storefront detail shape exposes the category as `category_id`
          // (number); the radio binds [value]="c.id" / [(ngModel)]="update.category",
          // so map it back onto `update.category` (Number-coerced) to pre-select.
          this.update.category = Number(response.data.category_id ?? response.data.category?.id ?? response.data.category ?? 0) || 0;

          // Seed multiselect from server shape [{id, collection}]
          const serverCollection = response.data.collection ?? [];
          this.selectedCollectionIds = Array.isArray(serverCollection)
            ? serverCollection.map((c: any) => c.id)
            : [];

          // Restore colours CSV into Set
          for (const item of (this.update.colors || '').split(',').map((s: string) => s.trim()).filter(Boolean)) {
            this.selected.add(item);
          }
          this.ui_controls.page_loading = false;

          // The route param is a LEGACY id (this page loads via by-legacy-id),
          // but the audit trail is keyed by the v3 product id, which the
          // detail shape exposes as `id`. Load the history with that.
          const v3Id = Number(response.data?.id) || 0;
          if (v3Id > 0) {
            this.get_product_history(v3Id);
          }
        }
      },
    });
  }

  /**
   * Load the product's change history (audit timeline) from the append-only
   * audit_log. Newest first; actor names are denormalised server-side.
   */
  get_product_history(productId: number) {
    this.history_loading = true;
    this.history_error = '';
    this.adapter.get_v3('GET /admin/products/:id/history', { params: { id: String(productId) } }).subscribe({
      next: (res: any) => {
        // Envelope shape is defensive: `logs` may sit at the top level or
        // under `data`, matching the audit-log console's own access.
        const body = res?.logs ? res : (res?.data ?? res);
        this.history = Array.isArray(body?.logs) ? body.logs : [];
        this.history_loading = false;
      },
      error: (e: any) => {
        this.history_loading = false;
        this.history_error = apiErrorMessage(e, 'Unable to load product history.');
      },
    });
  }

  start_update() {
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
