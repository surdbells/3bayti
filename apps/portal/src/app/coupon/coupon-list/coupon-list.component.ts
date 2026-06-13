import { Component, inject, OnInit, OnDestroy } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { Subject } from 'rxjs';
import { debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';

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
import { IconComponent } from '../../shared/icon/icon.component';
interface CouponListItem extends Record<string, unknown> {
  coupon_id: number;
  code: string;
  name: string;
  discount_type: string;
  discount_value: number;
  max_discount_amount: number | null;
  scope: string;
  max_uses: number | null;
  max_uses_per_customer: number | null;
  times_used: number;
  min_order_amount: number;
  first_order_only: number;
  starts_at: string | null;
  expires_at: string | null;
  status: string;
  store_id: number | null;
  store_name: string;
  created_by_name: string;
  created_at: string;
}

interface Pagination {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
}

@Component({
  selector: 'app-coupon-list',
  standalone: true,
  imports: [
    VendorShellComponent,
    AdminShellComponent,
    CommonModule, FormsModule, RouterLink,
    AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './coupon-list.component.html',
  styleUrl: './coupon-list.component.css',
})
export class CouponListComponent implements OnInit, OnDestroy {
  coupons: CouponListItem[] = [];

  // ── Enterprise table ───────────────────────────────────────────
  tableDataSource!: AxServerDataSource<CouponListItem>;
  readonly tableConfig: AxDataTableConfig<CouponListItem> = {
    tableId: 'coupons',
    mode: 'server',
    rowId: 'coupon_id',
    pageSize: 20,
    pageSizeOptions: [20, 50, 100],
    globalSearch: true,
    searchPlaceholder: 'Search coupons by code or name…',
    stickyHeader: true,
    hover: true,
    emptyTitle: 'No coupons yet',
    emptyDescription: 'Create your first coupon to start driving sales.',
    export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'coupons' },
    filters: [
      {
        key: 'status', label: 'Status', type: 'select',
        options: [
          { label: 'Active', value: 'active' },
          { label: 'Inactive', value: 'inactive' },
          { label: 'Expired', value: 'expired' },
        ],
      },
    ],
    columns: [
      { key: 'code', label: 'Code', sortable: true, sticky: 'left', width: '12rem' },
      { key: 'name', label: 'Name', hideOnMobile: true },
      { key: 'discount_type', label: 'Discount', align: 'center' },
      { key: 'discount_value', label: 'Value', align: 'right',
        value: (c) => (c.discount_type === 'percentage' ? `${c.discount_value}%` : `AED ${Number(c.discount_value).toFixed(2)}`) },
      { key: 'times_used', label: 'Used', align: 'center', hideOnMobile: true,
        value: (c) => (c.max_uses ? `${c.times_used} / ${c.max_uses}` : `${c.times_used} / ∞`) },
      { key: 'expires_at', label: 'Expires', hideOnMobile: true,
        format: (v) => (v ? new Date(String(v)).toLocaleDateString() : 'Never') },
      { key: 'status', label: 'Status', align: 'center' },
    ],
    rowActions: [
      { id: 'edit', label: 'Edit', icon: 'edit' },
      { id: 'analytics', label: 'Analytics', icon: 'bar_chart' },
      { id: 'toggle', label: 'Toggle status', icon: 'toggle_on' },
      { id: 'delete', label: 'Delete', icon: 'delete', variant: 'danger' },
    ],
  };

  onTableRowAction(e: { action: { id: string }; row: CouponListItem }): void {
    const { action, row } = e;
    switch (action.id) {
      case 'edit': return this.editCoupon(row.coupon_id);
      case 'analytics': return this.viewAnalytics(row.coupon_id);
      case 'toggle': return this.toggleStatus(row);
      case 'delete': return this.startDelete(row);
    }
  }

  private buildTableSource(): void {
    this.tableDataSource = new AxServerDataSource<CouponListItem>((q: AxQueryState) => {
      const query: any = { limit: q.pageSize, offset: q.pageIndex * q.pageSize };
      if (q.search) query.search = q.search;
      if (q.filters['status']) query.status = q.filters['status'];
      return this.adapter.get_v3('GET /vendor/coupons', { query }).pipe(
        map((response: any): AxServerFetchResult<CouponListItem> => {
          const raw: any[] = response?.data ?? [];
          const rows = raw.map((c) => this.mapCoupon(c));
          return { rows, total: response?.meta?.total ?? rows.length };
        }),
        catchError(() => {
          this.toast.error('Unable to load coupons at this time.');
          return of({ rows: [], total: 0 } as AxServerFetchResult<CouponListItem>);
        }),
      );
    });
  }

  /** Map the promo-code shape into the coupon list row. */
  private mapCoupon(c: any): CouponListItem {
    return {
      ...c,
      coupon_id: c.id,
      code: c.code ?? '',
      name: c.description ?? c.code ?? '',
      discount_type: c.discount_type ?? '',
      discount_value: c.discount_value ?? 0,
      max_discount_amount: c.max_discount_amount ?? null,
      max_uses: c.usage_limit_global ?? null,
      max_uses_per_customer: c.usage_limit_per_user ?? null,
      times_used: c.redemption_count ?? 0,
      min_order_amount: c.min_subtotal ?? 0,
      starts_at: c.valid_from ?? null,
      expires_at: c.valid_until ?? null,
      status: c.is_active ? 'active' : 'inactive',
      store_id: c.vendor_id ?? null,
      created_at: c.created_at ?? '',
    } as CouponListItem;
  }
  pagination: Pagination = { page: 1, per_page: 10, total: 0, total_pages: 0 };

  private readonly confirm = inject(AxConfirmService);

  ui = {
    loading: false,
    no_coupons: false,
    deleting: false,
    toggling: false,
    filters_open: false,
    nav_open: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  filters = {
    search: '',
    status: '',
    discount_type: '',
  };

  per_page_options = [10, 25, 50];

  private searchSubject = new Subject<string>();
  private destroy$ = new Subject<void>();

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    this.searchSubject.pipe(
      debounceTime(400),
      distinctUntilChanged(),
      takeUntil(this.destroy$),
    ).subscribe(() => {
      this.pagination.page = 1;
      this.fetchCoupons();
    });

    this.buildTableSource();
    this.fetchCoupons();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  fetchCoupons(): void {
    this.ui.loading = true;

    const payload: any = {
      token: this.user_session.token,
      id: this.user_session.id,
      page: this.pagination.page,
      per_page: this.pagination.per_page,
    };

    if (this.filters.search.trim()) payload.search = this.filters.search.trim();
    if (this.filters.status) payload.status = this.filters.status;
    if (this.filters.discount_type) payload.discount_type = this.filters.discount_type;

    this.adapter.get_v3('GET /vendor/coupons', { query: { limit: 50, offset: 0 } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.coupons = response.data ?? [];
          // v3 returns { data, meta:{total,limit,offset} } not pagination
          if (response.meta) {
            this.pagination = {
              ...this.pagination,
              total: response.meta.total ?? 0,
              total_pages: Math.ceil((response.meta.total ?? 0) / (response.meta.limit || this.pagination.per_page)),
            };
          }
          this.ui.no_coupons = this.coupons.length === 0;
        } else {
          this.coupons = [];
          this.ui.no_coupons = true;
        }
        this.ui.loading = false;
      },
      error: () => {
        this.toast.error('Unable to fetch coupons.');
        this.ui.loading = false;
      },
    });
  }

  onSearchInput(): void {
    this.searchSubject.next(this.filters.search);
  }

  applyFilters(): void {
    this.pagination.page = 1;
    this.fetchCoupons();
  }

  clearFilters(): void {
    this.filters = { search: '', status: '', discount_type: '' };
    this.pagination.page = 1;
    this.fetchCoupons();
  }

  get hasActiveFilters(): boolean {
    return !!(this.filters.search || this.filters.status || this.filters.discount_type);
  }

  get activeFilterCount(): number {
    let c = 0;
    if (this.filters.status) c++;
    if (this.filters.discount_type) c++;
    return c;
  }

  toggleFilters(): void {
    this.ui.filters_open = !this.ui.filters_open;
  }

  /** AxPagination is 0-indexed. Map to/from legacy 1-indexed pagination. */
  get pageIndex(): number {
    return Math.max(0, (this.pagination.page ?? 1) - 1);
  }

  onPageIndexChange(newIndex: number): void {
    this.pagination.page = newIndex + 1;
    this.fetchCoupons();
  }

  onPerPageChange(): void {
    this.pagination.page = 1;
    this.fetchCoupons();
  }

  editCoupon(id: number): void {
    this.router.navigate(['/edit-coupon'], { queryParams: { id } });
  }

  viewAnalytics(id: number): void {
    this.router.navigate(['/coupon-analytics'], { queryParams: { id } });
  }

  toggleStatus(coupon: CouponListItem): void {
    const newStatus = coupon.status === 'active' ? 'inactive' : 'active';
    const label = newStatus === 'active' ? 'activate' : 'deactivate';

    this.confirm
      .confirm({
        title: `Confirm ${label}`,
        message: `Are you sure you want to ${label} coupon "${coupon.code}"?`,
        confirmLabel: label.charAt(0).toUpperCase() + label.slice(1),
        cancelLabel: 'Cancel',
        variant: newStatus === 'inactive' ? 'danger' : 'default'
      })
      .then((ok) => {
        if (ok) this.executeToggle(coupon.coupon_id, newStatus);
      });
  }

  private executeToggle(couponId: number, status: string): void {
    this.ui.toggling = true;
    const payload = {
      token: this.user_session.token,
      id: this.user_session.id,
      coupon_id: couponId,
      status,
    };

    const toggleId = payload.coupon_id ?? payload.id;
    const existing = (this.coupons as any[]).find((c: any) => (c.id || c.coupon_id) === toggleId);
    const toggleBody = { is_active: !existing?.is_active };
    this.adapter.put_v3('PUT /vendor/coupons/:id', toggleBody, { params: { id: String(toggleId) } }).subscribe({
      next: (response: any) => {
        this.ui.toggling = false;
        if (response) {
          this.toast.success(response.message ?? 'Coupon updated.');
          this.fetchCoupons();
          this.tableDataSource?.retry();
        }
      },
      error: () => {
        this.ui.toggling = false;
        this.toast.error('Unable to update coupon status.');
      },
    });
  }

  startDelete(coupon: CouponListItem): void {
    this.confirm
      .confirm({
        title: 'Confirm delete',
        message: `Coupon "${coupon.code}" will be deleted permanently. Usage history will be preserved.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((ok) => {
        if (ok) this.executeDelete(coupon.coupon_id);
      });
  }

  private executeDelete(couponId: number): void {
    this.ui.deleting = true;
    const payload = {
      token: this.user_session.token,
      id: this.user_session.id,
      coupon_id: couponId,
    };

    const deleteId = payload.coupon_id ?? payload.id;
    this.adapter.delete_v3('DELETE /vendor/coupons/:id', { params: { id: String(deleteId) } }).subscribe({
      next: (response: any) => {
        this.ui.deleting = false;
        if (response) {
          this.toast.success('Coupon deleted successfully.');
          this.fetchCoupons();
          this.tableDataSource?.retry();
        }
      },
      error: () => {
        this.ui.deleting = false;
        this.toast.error('Unable to delete coupon.');
      },
    });
  }

  trackById = (_: number, item: CouponListItem) => item.coupon_id;

  getDiscountLabel(c: CouponListItem): string {
    switch (c.discount_type) {
      case 'percentage': {
        const cap = c.max_discount_amount ? ` (max AED ${c.max_discount_amount})` : '';
        return `${c.discount_value}% off${cap}`;
      }
      case 'fixed_amount':  return `AED ${c.discount_value} off`;
      case 'free_shipping': return 'Free shipping';
      default:              return '';
    }
  }

  getDiscountTypeBadge(type: string): string {
    switch (type) {
      case 'percentage':    return 'ax-badge ax-badge-brand';
      case 'fixed_amount':  return 'ax-badge ax-badge-info';
      case 'free_shipping': return 'ax-badge ax-badge-success';
      default:              return 'ax-badge ax-badge-neutral';
    }
  }

  getDiscountTypeLabel(type: string): string {
    switch (type) {
      case 'percentage':    return 'Percentage';
      case 'fixed_amount':  return 'Fixed';
      case 'free_shipping': return 'Free shipping';
      default:              return type;
    }
  }

  getStatusBadge(status: string): string {
    switch (status) {
      case 'active':   return 'ax-badge ax-badge-success';
      case 'inactive': return 'ax-badge ax-badge-neutral';
      case 'expired':  return 'ax-badge ax-badge-danger';
      default:         return 'ax-badge ax-badge-neutral';
    }
  }

  getUsageText(c: CouponListItem): string {
    if (c.max_uses) return `${c.times_used} / ${c.max_uses}`;
    return `${c.times_used} / ∞`;
  }

  getUsagePercent(c: CouponListItem): number {
    if (!c.max_uses) return 0;
    return Math.min(100, Math.round((c.times_used / c.max_uses) * 100));
  }

  usageToneClass(c: CouponListItem): string {
    const p = this.getUsagePercent(c);
    if (p >= 90) return 'ax-progress-bar-danger';
    if (p >= 70) return 'ax-progress-bar-warning';
    return 'ax-progress-bar-success';
  }

  isExpired(c: CouponListItem): boolean {
    if (!c.expires_at) return false;
    return new Date(c.expires_at) < new Date();
  }

  isScheduled(c: CouponListItem): boolean {
    if (!c.starts_at) return false;
    return new Date(c.starts_at) > new Date();
  }

  goBack(): void {
    if (this.user_session.is_vendor) this.router.navigate(['/account']);
    if (this.user_session.is_admin) this.router.navigate(['/backend']);
  }
}
