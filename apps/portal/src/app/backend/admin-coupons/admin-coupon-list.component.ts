import { Component, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of, firstValueFrom } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';
import { loadAdminVendorOptions } from '../shared/order-filters';

/** Flattened admin promo-code row for the coupons & discounts table. */
interface CouponRow extends Record<string, unknown> {
  id: number;
  code: string;
  description: string;
  scope: string;
  discount_type: string;
  discount_value: string;
  min_subtotal: string | null;
  usage_limit_global: number | null;
  usage_limit_per_user: number | null;
  times_used: number;
  valid_until: string | null;
  is_active: boolean;
  created_at: string;
}

/**
 * Admin "Coupons & discounts", oversight of EVERY promo code across the
 * platform (sitewide + vendor-owned) plus creation of platform-wide codes.
 *
 * Backed by the admin promo-code API (GET/POST/PUT/DELETE /admin/promo-codes,
 * gated by coupons.*). A code with vendor_id === null is a platform-wide code
 * (applies to the whole cart at checkout); a non-null vendor_id is a
 * vendor-owned coupon, shown here read-through so admins can moderate it.
 */
@Component({
  selector: 'app-admin-coupon-list',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent, AxCanDirective],
  templateUrl: './admin-coupon-list.component.html',
  styleUrl: './admin-coupon-list.component.css',
})
export class AdminCouponListComponent implements OnInit {
  private readonly router = inject(Router);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly confirm = inject(AxConfirmService);
  private readonly navHistory = inject(NavigationHistoryService);

  config?: AxDataTableConfig<CouponRow>;
  dataSource!: AxServerDataSource<CouponRow>;

  /** vendor id (string) → store name, for the Scope column. */
  private vendorNames = new Map<string, string>();

  /** Scope filter options: "Sitewide (admin)" + one per store. */
  private scopeOptions: { label: string; value: string }[] = [];

  async ngOnInit(): Promise<void> {
    // Resolve store names once so the Scope column can show "Acme Store"
    // instead of a bare id, and to populate the Scope filter. Best-effort -
    // the column falls back to "Store #id" and the filter still offers the
    // Sitewide option if the store list fails to load.
    try {
      const vendors = await loadAdminVendorOptions(this.adapter);
      for (const v of vendors) this.vendorNames.set(String(v.value), v.label);
      this.scopeOptions = [
        { label: 'Sitewide (admin)', value: 'platform' },
        ...vendors.map((v) => ({ label: v.label, value: String(v.value) })),
      ];
    } catch {
      this.scopeOptions = [{ label: 'Sitewide (admin)', value: 'platform' }];
    }
    this.buildTable();
  }

  private buildTable(): void {
    this.dataSource = new AxServerDataSource<CouponRow>((q) => this.fetch(q));
    this.config = {
      tableId: 'admin-coupons',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search by code…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No coupons found',
      emptyDescription: 'No promo codes match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'coupons-and-discounts' },
      filters: [
        {
          key: 'scope',
          label: 'Scope',
          type: 'select',
          options: this.scopeOptions,
        },
        {
          key: 'status',
          label: 'Status',
          type: 'select',
          options: [
            { label: 'Active', value: 'true' },
            { label: 'Inactive', value: 'false' },
          ],
        },
        {
          key: 'type',
          label: 'Discount',
          type: 'select',
          options: [
            { label: 'Percentage', value: 'percentage' },
            { label: 'Fixed amount', value: 'fixed_amount' },
          ],
        },
      ],
      columns: [
        { key: 'code', label: 'Code', sortable: true, sticky: 'left', width: '13rem' },
        { key: 'scope', label: 'Scope', value: (r) => r.scope },
        { key: 'discount_type', label: 'Type', align: 'center' },
        {
          key: 'discount_value', label: 'Value', align: 'right',
          value: (r) => (r.discount_type === 'percentage'
            ? `${Number(r.discount_value)}%`
            : `AED ${Number(r.discount_value).toLocaleString(undefined, { minimumFractionDigits: 2 })}`),
        },
        {
          key: 'min_subtotal', label: 'Min spend', align: 'right', hideOnMobile: true,
          value: (r) => (r.min_subtotal != null ? `AED ${Number(r.min_subtotal).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—'),
        },
        {
          key: 'times_used', label: 'Used', align: 'center',
          value: (r) => (r.usage_limit_global != null ? `${r.times_used} / ${r.usage_limit_global}` : `${r.times_used}`),
        },
        {
          key: 'valid_until', label: 'Expires', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : 'Never'),
        },
        { key: 'is_active', label: 'Status', align: 'center' },
        {
          key: 'created_at', label: 'Created', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—'),
        },
      ],
      rowActions: [
        { id: 'usage', label: 'Usage report', icon: 'bar_chart' },
        { id: 'edit', label: 'Edit', icon: 'edit' },
        { id: 'toggle', label: 'Activate / deactivate', icon: 'toggle_on' },
        { id: 'delete', label: 'Delete', icon: 'delete', variant: 'danger' },
      ],
    };
  }

  private fetch(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    // The admin list searches by code (not a generic `search` param).
    if (query.search) q.code = query.search;
    // Scope: 'platform' (sitewide/admin) or a vendor id string.
    if (query.filters['scope']) q.scope = query.filters['scope'];
    if (query.filters['status']) q.is_active = query.filters['status'];
    if (query.filters['type']) q.discount_type = query.filters['type'];

    return this.adapter.get_v3('GET /admin/promo-codes', { query: q }).pipe(
      map((response: any): AxServerFetchResult<CouponRow> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((c) => this.mapRow(c));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load coupons at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<CouponRow>);
      }),
    );
  }

  /** Flatten an admin promo-code shape into a table row. */
  private mapRow(c: any): CouponRow {
    return {
      ...c,
      id: c.id,
      code: c.code ?? '',
      description: c.description ?? '',
      scope: this.scopeLabel(c.vendor_id ?? null),
      discount_type: c.discount_type ?? '',
      discount_value: c.discount_value ?? '0',
      min_subtotal: c.min_subtotal ?? null,
      usage_limit_global: c.usage_limit_global ?? null,
      usage_limit_per_user: c.usage_limit_per_user ?? null,
      times_used: c.redemption_count ?? 0,
      valid_until: c.valid_until ?? null,
      is_active: !!c.is_active,
      created_at: c.created_at ?? '',
    } as CouponRow;
  }

  /** Platform-wide (null vendor) vs a specific store. */
  private scopeLabel(vendorId: number | null): string {
    if (vendorId == null) return 'Platform';
    return this.vendorNames.get(String(vendorId)) ?? `Store #${vendorId}`;
  }

  onRowAction(e: { action: { id: string }; row: CouponRow }): void {
    const { action, row } = e;
    if (action.id === 'usage') {
      this.router.navigate(['/admin/coupons/usage'], { queryParams: { id: row.id } });
    } else if (action.id === 'edit') {
      this.router.navigate(['/admin/coupons/form'], { queryParams: { id: row.id } });
    } else if (action.id === 'toggle') {
      this.toggle(row);
    } else if (action.id === 'delete') {
      this.remove(row);
    }
  }

  createCoupon(): void {
    this.router.navigate(['/admin/coupons/form']);
  }

  prettyType(type: string): string {
    if (type === 'percentage') return 'Percentage';
    if (type === 'fixed_amount') return 'Fixed';
    return type;
  }

  private toggle(row: CouponRow): void {
    const activate = !row.is_active;
    const label = activate ? 'activate' : 'deactivate';
    this.confirm
      .confirm({
        title: `Confirm ${label}`,
        message: `Are you sure you want to ${label} coupon "${row.code}"?`,
        confirmLabel: label.charAt(0).toUpperCase() + label.slice(1),
        cancelLabel: 'Cancel',
        variant: activate ? 'default' : 'danger',
      })
      .then((ok) => {
        if (!ok) return;
        this.adapter
          .put_v3('PUT /admin/promo-codes/:id', { is_active: activate }, { params: { id: String(row.id) } })
          .subscribe({
            next: () => {
              this.toast.success(activate ? 'Coupon activated.' : 'Coupon deactivated.');
              this.dataSource?.retry();
            },
            error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to update coupon.')),
          });
      });
  }

  private remove(row: CouponRow): void {
    this.confirm
      .confirm({
        title: 'Confirm delete',
        message: `Coupon "${row.code}" will be deleted. If it has redemption history it is deactivated instead, to preserve records.`,
        confirmLabel: 'Delete',
        cancelLabel: 'Cancel',
        variant: 'danger',
      })
      .then((ok) => {
        if (!ok) return;
        this.adapter
          .delete_v3('DELETE /admin/promo-codes/:id', { params: { id: String(row.id) } })
          .subscribe({
            next: () => {
              this.toast.success('Coupon deleted.');
              this.dataSource?.retry();
            },
            error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to delete coupon.')),
          });
      });
  }

  goBack(): void {
    this.navHistory.back('/backend');
  }
}
