import { Component, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

/** Row shape surfaced to the enterprise table (mapped from v3 vendor). */
interface VendorRow extends Record<string, unknown> {
  id: number;
  store_name: string;
  store_email: string;
  store_phone: string;
  country: string;
  last_login: string;
  status: boolean;
  approved: boolean;
}

@Component({
  selector: 'app-stores',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    AxDataTableComponent,
    AxCellDirective,
  ],
  templateUrl: './stores.component.html',
  styleUrl: './stores.component.css',
})
export class StoresComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  ui_controls = { is_loading: false, no_data: false, nav_open: false };

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_store: false,
  };

  /** Enterprise-table config + data source. */
  config!: AxDataTableConfig<VendorRow>;
  dataSource!: AxServerDataSource<VendorRow>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<VendorRow>((query: AxQueryState) =>
      this.fetchVendors(query),
    );

    this.config = {
      tableId: 'admin-stores',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search stores by name, email…',
      selectable: true,
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No stores found',
      emptyDescription: 'No vendor stores match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'stores' },
      columns: [
        { key: 'store_name', label: 'Store', sortable: true, sticky: 'left', width: '16rem' },
        { key: 'store_email', label: 'Email', hideOnMobile: true },
        { key: 'store_phone', label: 'Phone', hideOnMobile: true },
        { key: 'country', label: 'Country', align: 'center', hideOnMobile: true },
        {
          key: 'last_login', label: 'Updated', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—'),
        },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'approved', label: 'Approval', align: 'center' },
      ],
      rowActions: [
        { id: 'orders', label: 'Orders', icon: 'receipt_long' },
        { id: 'products', label: 'Products', icon: 'inventory_2' },
        { id: 'sales', label: 'Sales', icon: 'payments' },
        { id: 'manage', label: 'Manage', icon: 'settings' },
        { id: 'approve', label: 'Approve', icon: 'check_circle', hidden: (r) => r.approved },
        { id: 'suspend', label: 'Suspend', icon: 'block', variant: 'danger', hidden: (r) => !r.status },
        { id: 'delete', label: 'Delete', icon: 'delete', variant: 'danger' },
      ],
      bulkActions: [
        { id: 'bulk-approve', label: 'Approve', icon: 'check_circle' },
        { id: 'bulk-suspend', label: 'Suspend', icon: 'block', variant: 'danger' },
      ],
    };
  }

  /** v3 fetch → AxServerFetchResult. Maps vendor fields to VendorRow. */
  private fetchVendors(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    // Single-column server sort (the API currently orders by name).
    if (query.sort.length > 0) {
      q.sort = query.sort[0].key;
      q.dir = query.sort[0].direction;
    }

    return this.adapter.get_v3('GET /admin/vendors', { query: q }).pipe(
      map((response: any): AxServerFetchResult<VendorRow> => {
        const raw: any[] = response?.vendors ?? response?.data ?? [];
        const rows: VendorRow[] = raw.map((v: any) => ({
          id: v.id,
          store_name: v.name,
          store_email: v.contact_email ?? '',
          store_phone: v.contact_phone ?? '',
          country: v.country_code ?? '',
          last_login: v.updated_at ?? '',
          status: v.is_active === true,
          approved: v.status === 'approved',
        }));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load stores at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<VendorRow>);
      }),
    );
  }

  // ── Table event handlers ─────────────────────────────────────────────
  onRowAction(e: { action: { id: string }; row: VendorRow }) {
    const { action, row } = e;
    switch (action.id) {
      case 'orders': return this.openTab('/store_orders', row.id, row.store_name);
      case 'products': return this.openTab('/store_products', row.id, row.store_name);
      case 'sales': return this.openTab('/store_sales', row.id, row.store_name);
      case 'manage': return this.openTab('/manage_store', row.id, row.store_name);
      case 'approve': return this.confirmApprove(row);
      case 'suspend': return this.confirmSuspend(row);
      case 'delete': return this.confirmDelete(row);
    }
  }

  onBulkAction(e: { action: { id: string }; selection: VendorRow[] }) {
    const ids = e.selection.map((r) => r.id);
    if (e.action.id === 'bulk-approve') {
      this.confirm.confirm({
        title: 'Approve stores',
        message: `Approve ${ids.length} selected store(s)?`,
        confirmLabel: 'Approve', cancelLabel: 'Cancel',
      }).then((ok) => { if (ok) ids.forEach((id) => this.approve(id)); });
    } else if (e.action.id === 'bulk-suspend') {
      this.confirm.confirm({
        title: 'Suspend stores',
        message: `Suspend ${ids.length} selected store(s)?`,
        confirmLabel: 'Suspend', cancelLabel: 'Cancel', variant: 'danger',
      }).then((ok) => { if (ok) ids.forEach((id) => this.suspend(id)); });
    }
  }

  private confirmApprove(row: VendorRow) {
    this.confirm.confirm({
      title: 'Approve store', message: `${row.store_name} will be approved.`,
      confirmLabel: 'Approve', cancelLabel: 'Cancel',
    }).then((ok) => { if (ok) this.approve(row.id); });
  }

  private confirmSuspend(row: VendorRow) {
    this.confirm.confirm({
      title: 'Suspend store', message: `${row.store_name} will be suspended.`,
      confirmLabel: 'Suspend', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => { if (ok) this.suspend(row.id); });
  }

  private confirmDelete(row: VendorRow) {
    this.confirm.confirm({
      title: 'Delete store', message: `${row.store_name} will be deleted.`,
      confirmLabel: 'Delete', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => { if (ok) this.remove(row.id); });
  }

  private approve(id: number) {
    this.adapter.post_v3('POST /admin/vendors/:id/approve', {}, { params: { id: String(id) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('Store approved.'); this.refresh(); } } });
  }

  private suspend(id: number) {
    this.adapter.post_v3('POST /admin/vendors/:id/suspend', {}, { params: { id: String(id) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('Store suspended.'); this.refresh(); } } });
  }

  private remove(id: number) {
    this.adapter.delete_v3('DELETE /admin/vendors/:id', { params: { id: String(id) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('Store deleted.'); this.refresh(); } } });
  }

  private refresh() { this.dataSource.retry(); }

  private openTab(path: string, id: number, name: string) {
    this.router.navigate([path], { queryParams: { id, name } });
  }

  goBack() { this.router.navigate(['/backend']); }
}
