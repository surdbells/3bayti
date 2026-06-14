import { Component, inject, OnInit, signal, OnDestroy } from '@angular/core';
import { Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { FormsModule } from '@angular/forms';
import { CommonModule, formatCurrency } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { Products } from '../../class/products';
import { Subject } from 'rxjs';
import { debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import { AxPaginationComponent } from '../../shared/data';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { AxConfirmService } from '../../shared/overlays';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { CfImagePipe } from '../../shared/cf-image.pipe';
import { IconComponent } from '../../shared/icon/icon.component';
// ── Color map for preview drawer ─────────────────────────────────
const COLOR_HEX_MAP: Record<string, string> = {
  'black': '#000000', 'white': '#FFFFFF', 'off-white': '#FAF9F6',
  'charcoal': '#333333', 'gray': '#808080', 'light-gray': '#D3D3D3',
  'beige': '#F5F5DC', 'tan': '#D2B48C', 'camel': '#C19A6B',
  'brown': '#8B4513', 'chocolate': '#5D3A00', 'navy': '#001F3F',
  'blue': '#1F75FE', 'light-blue': '#87CEEB', 'sky-blue': '#00BFFF',
  'denim': '#274472', 'teal': '#008080', 'aqua': '#00FFFF',
  'mint': '#98FF98', 'green': '#2E8B57', 'lime': '#32CD32',
  'olive': '#808000', 'forest': '#228B22', 'red': '#C0392B',
  'crimson': '#DC143C', 'burgundy': '#800020', 'pink': '#FFC0CB',
  'hot-pink': '#FF69B4', 'rose': '#FF007F', 'purple': '#800080',
  'lavender': '#E6E6FA', 'violet': '#8A2BE2', 'orange': '#FF8C00',
  'peach': '#FFDAB9', 'coral': '#FF7F50', 'yellow': '#FFD200',
  'mustard': '#FFDB58', 'gold': '#D4AF37', 'silver': '#C0C0C0',
  'bronze': '#CD7F32', 'champagne': '#F7E7CE', 'ivory': '#FFFFF0',
};

interface Pagination {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

interface ProductListItem extends Record<string, unknown> {
  id: number;
  store: number;
  category: string;
  category_id: number;
  image: string;
  name: string;
  price: number;
  price_formatted: string;
  quantity: number;
  stock_status: string;
  label: string;
  status: string;
  created: string;
  updated: string;
  store_name: string;
}

@Component({
  selector: 'app-vendor-products',
  standalone: true,
  imports: [CfImagePipe, 
    VendorShellComponent,
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AxPaginationComponent,
    AxDataTableComponent,
    AxCellDirective, IconComponent],
  templateUrl: './vendor-products.component.html',
  styleUrl: './vendor-products.component.css',
})
export class VendorProductsComponent implements OnInit, OnDestroy {
  // ── Product data ───────────────────────────────────────────────
  products: ProductListItem[] = [];

  // ── Enterprise table (list view) ───────────────────────────────
  tableDataSource!: AxServerDataSource<ProductListItem>;
  readonly tableConfig: AxDataTableConfig<ProductListItem> = {
    tableId: 'vendor-products',
    mode: 'server',
    rowId: 'id',
    pageSize: 20,
    pageSizeOptions: [20, 50, 100],
    globalSearch: false,
    searchPlaceholder: 'Search products by name…',
    stickyHeader: true,
    hover: true,
    emptyTitle: 'No products',
    emptyDescription: 'You have not listed any products yet.',
    export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'my-products' },
    columns: [
      { key: 'name', label: 'Product', sortable: true, sticky: 'left', width: '18rem' },
      { key: 'category', label: 'Category', hideOnMobile: true },
      { key: 'status', label: 'Status', align: 'center' },
      { key: 'price_formatted', label: 'Price', align: 'right' },
      { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
      { key: 'stock_status', label: 'Stock', align: 'center' },
    ],
    rowActions: [
      { id: 'preview', label: 'Preview', icon: 'visibility' },
      { id: 'edit', label: 'Edit', icon: 'edit' },
      { id: 'sales', label: 'Sales', icon: 'bar_chart' },
      { id: 'delete', label: 'Delete', icon: 'delete', variant: 'danger' },
    ],
  };

  onTableRowAction(e: { action: { id: string }; row: ProductListItem }): void {
    const { action, row } = e;
    switch (action.id) {
      case 'preview': return this.preview(row.id);
      case 'edit': return this.editProduct(row.id);
      case 'sales': return this.productSales(row.id, row.name);
      case 'delete': return this.startDelete(row.id, row.name);
    }
  }

  private buildTableSource(): void {
    this.tableDataSource = new AxServerDataSource<ProductListItem>((q: AxQueryState) => {
      const query: any = { limit: q.pageSize, offset: q.pageIndex * q.pageSize };
      this.applyFilterQuery(query);
      return this.adapter.get_v3('GET /vendor/products', { query }).pipe(
        map((response: any): AxServerFetchResult<ProductListItem> => {
          const rows = (response?.data ?? []).map((p: any) => this.mapProduct(p));
          return { rows, total: response?.meta?.total ?? rows.length };
        }),
        catchError(() => {
          this.toast.error('Unable to load products at this time.');
          return of({ rows: [], total: 0 } as AxServerFetchResult<ProductListItem>);
        }),
      );
    });
  }

  /** Merge the active filter panel + search into an API query object. */
  private applyFilterQuery(query: Record<string, any>): void {
    const f = this.filters;
    if (f.search && f.search.trim() !== '') query['search'] = f.search.trim();
    if (f.status) query['status'] = f.status;
    if (f.stock_status) query['stock_status'] = f.stock_status;
    if (f.category_id != null) query['category_id'] = f.category_id;
    if (f.price_min != null) query['price_min'] = f.price_min;
    if (f.price_max != null) query['price_max'] = f.price_max;
  }

  /**
   * Re-fetch both views (list = AxDataTable, grid = manual) with the
   * current filters. Rebuilding the data source instance makes the table
   * re-connect and re-query from page 1.
   */
  private reloadProducts(): void {
    this.pagination.page = 1;
    this.buildTableSource();
    this.fetchProducts();
  }
  pagination: Pagination = { page: 1, per_page: 10, total: 0, total_pages: 0 };

  // ── Preview drawer ─────────────────────────────────────────────
  protected readonly open = signal(false);
  private readonly confirm = inject(AxConfirmService);

  // ── Mobile preview drawer state lives below; product create/edit
  //    navigate to the standalone full-page form. ──────────────────
  /** Category options for the list filter dropdown. */
  categories: { id: number; name: string }[] = [];

  image_url = 'https://api.3bayti.ae/vendors/products/';
  single_product: any = {
    id: 0, token: '', product: 0, store: 0, category: 0, category_name: '',
    name: '', description: '',
    image_1: 'assets/img/placeholder-1.png', images: [] as string[],
    quantity: 0, allow_checkout_when_out_of_stock: false,
    with_storehouse_management: false, stock_status: 'in_stock',
    price: 0, minimum_order_quantity: 1, maximum_order_quantity: 1,
    cost_per_item: 0, delivery_time: '', custom_delivery_time: '',
    size_xs: false, size_s: false, size_m: false, size_l: false,
    size_xl: false, size_xxl: false, size_50: false, size_52: false,
    size_54: false, size_56: false, size_58: false, size_60: false,
    size_62: false, size_custom: false, require_extra_msmt: false,
    extra_msmt: '', is_hot: false, is_new: false, is_sale: false,
    delivery_note: '', colors: '', label: 0,
  };
  previewImageIndex = 0;

  // ── UI controls ────────────────────────────────────────────────
  ui = {
    loading: false,
    no_products: false,
    loaded_preview: false,
    list_view: true,
    grid_view: false,
    deleting: false,
    filters_open: false,
  };

  // ── Session ────────────────────────────────────────────────────
  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  // ── Filters ────────────────────────────────────────────────────
  filters = {
    search: '',
    status: '',
    stock_status: '',
    category_id: null as number | null,
    price_min: null as number | null,
    price_max: null as number | null,
  };
  price_preset = '';

  // ── Per-page options ───────────────────────────────────────────
  per_page_options = [10, 25, 50, 100];

  // ── Debounced search ───────────────────────────────────────────
  private searchSubject = new Subject<string>();
  private destroy$ = new Subject<void>();

  // ── Delete payload ─────────────────────────────────────────────
  private delete_payload = { id: 0, product: 0, token: '', name: '' };

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    this.delete_payload.token = this.user_session.token;
    this.delete_payload.id = this.user_session.id;

    this.searchSubject.pipe(
      debounceTime(400),
      distinctUntilChanged(),
      takeUntil(this.destroy$),
    ).subscribe(() => {
      this.reloadProducts();
    });

    this.buildTableSource();
    this.fetchProducts();
    this.fetchCategories();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  fetchProducts(): void {
    this.ui.loading = true;
    const page = this.pagination.page;
    const perPage = this.pagination.per_page;
    const vendorId = this.user_session.id;
    const query: Record<string, string | number | boolean | undefined | null> = {
      limit: perPage,
      offset: (page - 1) * perPage,
    };
    this.applyFilterQuery(query);
    this.adapter.get_v3('GET /vendor/products', { query }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.products = (response.data ?? []).map((p: any) => this.mapProduct(p));
          const meta = response.meta ?? {};
          if (meta.total !== undefined) {
            this.pagination.total = meta.total;
          }
          this.ui.no_products = this.products.length === 0;
        } else {
          this.products = [];
          this.ui.no_products = true;
        }
        this.ui.loading = false;
      },
      error: () => {
        this.toast.error('Unable to load products at this time.');
        this.ui.loading = false;
      },
    });
  }

  /** Map a v3 product list shape to the flat shape the template uses. */
  private mapProduct(p: any): any {
    return {
      ...p,
      product_id: p.id,
      product_name: p.name,
      product_price: p.price?.amount ?? p.price,
      product_image: p.primary_image?.url ?? '',
      category_name: p.category?.name ?? '',
      product_status: p.status,
    };
  }

  /** Loads category options used by the list filter. */
  fetchCategories(): void {
    this.adapter.get_v3('GET /utility/categories').subscribe({
      next: (response: any) => {
        const items = Array.isArray(response?.data) ? response.data
          : Array.isArray(response) ? response : [];
        this.categories = items.map((c: any) => ({
          id: c.id ?? c.category_id,
          name: c.name ?? c.category_name,
        }));
      },
    });
  }

  onSearchInput(): void {
    this.searchSubject.next(this.filters.search);
  }

  applyFilters(): void {
    this.reloadProducts();
  }

  clearFilters(): void {
    this.filters = {
      search: '', status: '', stock_status: '',
      category_id: null, price_min: null, price_max: null,
    };
    this.price_preset = '';
    this.reloadProducts();
  }

  onPricePresetChange(): void {
    switch (this.price_preset) {
      case 'under_100': this.filters.price_min = null; this.filters.price_max = 100; break;
      case '100_300':   this.filters.price_min = 100;  this.filters.price_max = 300; break;
      case '300_500':   this.filters.price_min = 300;  this.filters.price_max = 500; break;
      case '500_plus':  this.filters.price_min = 500;  this.filters.price_max = null; break;
      case 'custom':    this.filters.price_min = null; this.filters.price_max = null; break;
      default:          this.filters.price_min = null; this.filters.price_max = null;
    }
    if (this.price_preset !== 'custom') this.applyFilters();
  }

  get hasActiveFilters(): boolean {
    return !!(
      this.filters.search || this.filters.status || this.filters.stock_status ||
      this.filters.category_id || this.filters.price_min !== null || this.filters.price_max !== null
    );
  }

  get activeFilterCount(): number {
    let count = 0;
    if (this.filters.status) count++;
    if (this.filters.stock_status) count++;
    if (this.filters.category_id) count++;
    if (this.filters.price_min !== null || this.filters.price_max !== null) count++;
    return count;
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.pagination.total_pages) return;
    this.pagination.page = page;
    this.fetchProducts();
  }

  onPageSizeChange(size: number): void {
    this.pagination.per_page = size;
    this.pagination.page = 1;
    this.fetchProducts();
  }

  switchGrid(): void {
    this.ui.list_view = false;
    this.ui.grid_view = true;
  }

  switchList(): void {
    this.ui.list_view = true;
    this.ui.grid_view = false;
  }

  toggleFilters(): void {
    this.ui.filters_open = !this.ui.filters_open;
  }

  // ── Create / edit → standalone full-page editor ────────────────
  // The table routes to the full product form pages (the same shared
  // <app-product-form> used everywhere) rather than a partial drawer,
  // so vendors get the complete field set in one consistent place.
  openCreate(): void {
    this.router.navigate(['/create-product']);
  }

  editProduct(id: number): void {
    this.router.navigate(['/edit-product'], { queryParams: { id } });
  }

  private refreshLists(): void {
    this.fetchProducts();
    this.tableDataSource?.retry();
  }

  productSales(id: number, name: string): void {
    this.router.navigate(['/vendor_product_sales'], { queryParams: { id, name } });
  }

  preview(id: number): void {
    this.open.set(true);
    this.ui.loaded_preview = false;
    this.previewImageIndex = 0;

    this.adapter.get_v3('GET /vendor/products/:id', { params: { id: String(id) } }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          const p = response.data;
          this.single_product = {
            ...p,
            product_id: p.id,
            product_name: p.name,
            product_price: typeof p.price === 'number' ? p.price : (p.price?.amount ?? 0),
            price_formatted: p.price_formatted,
            image_1: p.image ?? p.primary_image?.url ?? '',
            // v3 detail arrays: images [{url}], sizes [{label}], colors [{label, hex_code}]
            images: (p.images ?? []).map((i: any) => i?.url ?? i),
            sizes: (p.sizes ?? []).map((s: any) => (typeof s === 'string' ? s : s?.label)).filter(Boolean),
            colors: (p.colors ?? []).map((c: any) => (typeof c === 'string' ? c : c?.label)).filter(Boolean),
            category_name: p.category ?? '',
            product_status: p.status,
          };
          this.ui.loaded_preview = true;
        }
      },
      error: () => {
        this.toast.error('Unable to load the product preview.');
        this.ui.loaded_preview = true;
      },
    });
  }

  closePreview(): void {
    this.open.set(false);
  }

  get previewImages(): string[] {
    const imgs: string[] = [];
    if (this.single_product.image_1) imgs.push(this.single_product.image_1);
    if (this.single_product.images && Array.isArray(this.single_product.images)) {
      for (const src of this.single_product.images) {
        if (src && !src.includes('placeholder')) imgs.push(src);
      }
    }
    return imgs;
  }

  getImageUrl(src: string): string {
    if (!src) return 'assets/img/placeholder-1.png';
    // Absolute URLs (incl. migrated api-v3 uploads), local assets, and
    // browser previews are used as-is; only bare legacy filenames get the
    // legacy host prepended. (Was a fragile length>100 heuristic that
    // mangled short absolute URLs.)
    if (/^(https?:|blob:|data:)/.test(src) || src.startsWith('assets/')) return src;
    return this.image_url + src;
  }

  prevImage(): void {
    if (this.previewImageIndex > 0) this.previewImageIndex--;
  }

  nextImage(): void {
    if (this.previewImageIndex < this.previewImages.length - 1) this.previewImageIndex++;
  }

  selectImage(index: number): void {
    this.previewImageIndex = index;
  }

  get previewColors(): { id: string; hex: string }[] {
    const colors = this.single_product.colors;
    if (!Array.isArray(colors)) return [];
    return colors.filter(Boolean).map((id: string) => ({ id, hex: COLOR_HEX_MAP[id] || '#CCCCCC' }));
  }

  get previewSizes(): string[] {
    const sizes = this.single_product.sizes;
    return Array.isArray(sizes) ? sizes.filter(Boolean) : [];
  }

  get previewDeliveryTime(): string {
    const dt = this.single_product.delivery_time;
    const cdt = this.single_product.custom_delivery_time;
    if (dt === 'custom' && cdt) return `${cdt} days`;
    if (dt) return `${dt} days`;
    return '';
  }

  startDelete(id: number, name: string): void {
    this.confirm
      .confirm({
        title: 'Confirm delete',
        message: `Your product "${name}" will be deleted permanently.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((confirmed) => {
        if (confirmed) this.deleteProduct(id, name);
      });
  }

  private deleteProduct(id: number, name: string): void {
    this.ui.deleting = true;
    this.delete_payload.product = id;
    this.delete_payload.name = name;

    this.adapter.delete_v3('DELETE /vendor/products/:id', { params: { id: String(id) } }).subscribe({
      next: () => {
        this.toast.success('Product deleted successfully');
        this.ui.deleting = false;
        this.fetchProducts();
        this.tableDataSource?.retry();
      },
      error: () => {
        this.toast.error('Unable to delete product');
        this.ui.deleting = false;
      },
    });
  }

  goBack(): void {
    if (this.user_session.is_vendor) {
      this.router.navigate(['/account']);
    }
    if (this.user_session.is_admin) {
      this.router.navigate(['/backend']);
    }
  }

  protected readonly formatCurrency = formatCurrency;

  trackById = (_: number, item: ProductListItem) => item.id;

  getStockBadgeClass(status: string): string {
    switch (status) {
      case 'in_stock':     return 'ax-badge ax-badge-success';
      case 'out_of_stock': return 'ax-badge ax-badge-danger';
      case 'on_backorder': return 'ax-badge ax-badge-warning';
      default:             return 'ax-badge ax-badge-neutral';
    }
  }

  getStockLabel(status: string): string {
    switch (status) {
      case 'in_stock':     return 'In Stock';
      case 'out_of_stock': return 'Out of Stock';
      case 'on_backorder': return 'On Backorder';
      default:             return status;
    }
  }

  getStatusBadgeClass(status: string): string {
    return status === 'published'
      ? 'ax-badge ax-badge-info'
      : 'ax-badge ax-badge-neutral';
  }

  needsBorder(hex: string): boolean {
    const n = hex.replace('#', '');
    const bigint = parseInt(n.length === 3 ? n.split('').map(c => c + c).join('') : n, 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return (0.299 * r + 0.587 * g + 0.114 * b) > 220;
  }
}
