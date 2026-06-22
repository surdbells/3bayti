import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
  type AxDateRange,
} from '../../shared/data/enterprise';
import {
  LOGISTICS_STATUS_OPTIONS,
  FULFILMENT_STATUSES,
  loadAdminVendorOptions,
  prettyOrderStatus,
} from '../shared/order-filters';

interface DeliveryRow extends Record<string, unknown> {
  id: number;
  order_ref: string;
  customer: string;
  destination: string;
  phone: string;
  items_count: number;
  status: string;
  created: string;
}

@Component({
  selector: 'app-logistics',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './logistics.component.html',
  styleUrl: './logistics.component.css',
})
export class LogisticsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<DeliveryRow>;
  dataSource!: AxServerDataSource<DeliveryRow>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<DeliveryRow>((q) => this.fetch(q));
    this.config = {
      tableId: 'admin-logistics',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search deliveries…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No deliveries',
      emptyDescription: 'No orders match the selected delivery filters.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'deliveries' },
      filters: [
        { key: 'status', label: 'Status', type: 'select', options: LOGISTICS_STATUS_OPTIONS },
        { key: 'vendor', label: 'Store', type: 'select', optionsLoader: () => loadAdminVendorOptions(this.adapter) },
        { key: 'date', label: 'Date', type: 'date-range' },
      ],
      columns: [
        { key: 'order_ref', label: 'Order', sortable: true, sticky: 'left', width: '13rem' },
        { key: 'customer', label: 'Customer' },
        { key: 'destination', label: 'Destination' },
        { key: 'items_count', label: 'Items', align: 'center', hideOnMobile: true },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
      ],
      rowActions: [{ id: 'manage', label: 'Manage delivery', icon: 'local_shipping' }],
    };
  }

  private fetch(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    // Logistics board is scoped to the fulfilment set (shipped + delivered).
    // When the operator picks a specific status, send just that one; with no
    // explicit pick, default to the whole fulfilment set as a CSV so the API
    // (which accepts a comma-separated status list) excludes pre-shipment and
    // terminal orders.
    q.status = query.filters['status']
      ? query.filters['status']
      : FULFILMENT_STATUSES.join(',');
    if (query.filters['vendor']) q.vendor_id = query.filters['vendor'];
    const range = query.filters['date'] as AxDateRange | undefined;
    if (range?.from) q.since = range.from;
    if (range?.to) q.until = range.to;

    return this.adapter.get_v3('GET /admin/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<DeliveryRow> => {
        const raw: any[] = response?.orders ?? response?.data ?? [];
        const rows = raw.map((o) => this.mapRow(o));
        return { rows, total: response?.pagination?.total ?? response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load deliveries at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<DeliveryRow>);
      }),
    );
  }

  private mapRow(o: any): DeliveryRow {
    const c = o.customer ?? {};
    const name = `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim();
    const d = o.delivery ?? {};
    const dest = [d.city, d.area].filter(Boolean).join(', ');
    const items: any[] = o.items ?? [];
    return {
      ...o,
      id: o.id,
      order_ref: o.order_reference ?? o.id,
      customer: name || c.email || '—',
      destination: dest || '—',
      phone: d.phone ?? '',
      items_count: items.reduce((s, i) => s + (Number(i.quantity) || 0), 0) || items.length,
      status: o.status ?? '',
      created: o.date ?? o.created_at ?? '',
    } as DeliveryRow;
  }

  prettyStatus(s: string): string {
    return prettyOrderStatus(s);
  }

  onRowAction(e: { action: { id: string }; row: DeliveryRow }) {
    if (e.action.id === 'manage') {
      this.router.navigate(['/single'], { queryParams: { order: e.row.id } });
    }
  }

  goBack() { this.router.navigate(['/backend']); }
}
