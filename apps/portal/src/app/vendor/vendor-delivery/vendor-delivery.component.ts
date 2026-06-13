import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface DeliveryRow extends Record<string, unknown> {
  id: number;
  name: string;
  product: string;
  quantity: number;
  total_price: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-vendor-delivery',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './vendor-delivery.component.html',
  styleUrl: './vendor-delivery.component.css',
})
export class VendorDeliveryComponent implements OnInit {
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
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<DeliveryRow>((q) => this.fetchDeliveries(q));
    this.config = {
      tableId: 'vendor-deliveries',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search deliveries by product or customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No deliveries',
      emptyDescription: 'You have no orders in transit.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'deliveries' },
      columns: [
        { key: 'created', label: 'Date', sortable: true, sticky: 'left', width: '11rem',
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
        { key: 'product', label: 'Product' },
        { key: 'name', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'total_price', label: 'Total', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [{ id: 'receipt', label: 'View receipt', icon: 'receipt' }],
    };
  }

  private fetchDeliveries(query: AxQueryState) {
    const q: any = {
      status: 'shipped',
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /vendor/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<DeliveryRow> => {
        const raw: any[] = response?.data ?? response?.orders ?? [];
        const rows = raw.map((o) => this.mapRow(o));
        return { rows, total: response?.meta?.total ?? response?.pagination?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load deliveries at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<DeliveryRow>);
      }),
    );
  }

  /** Flatten the vendor order shape into the delivery row. */
  private mapRow(o: any): DeliveryRow {
    const items: any[] = o.items ?? [];
    const first = items[0] ?? {};
    const qty = items.reduce((s, i) => s + (Number(i.quantity) || 0), 0);
    const productLabel = items.length > 1
      ? `${first.product_name ?? 'Item'} +${items.length - 1} more`
      : (first.product_name ?? `Order ${o.order_reference ?? o.id}`);
    const customer = o.customer ?? {};
    const name = `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim();
    return {
      ...o,
      id: o.id,
      name: name || customer.email || '—',
      product: productLabel,
      quantity: qty,
      total_price: o.subtotal ?? o.total ?? '0',
      status: o.status ?? '',
      created: o.created_at ?? o.date ?? '',
    } as DeliveryRow;
  }

  onRowAction(e: { action: { id: string }; row: DeliveryRow }) {
    if (e.action.id === 'receipt') {
      this.router.navigate(['/', 'receipt'], { queryParams: { id: e.row.id, name: e.row.product } });
    }
  }

  statusBadgeClass(status: string): string {
    const s = (status || '').toLowerCase();
    if (s.includes('deliver') || s.includes('complete')) return 'ax-badge ax-badge-success';
    if (s.includes('transit') || s.includes('shipped')) return 'ax-badge ax-badge-info';
    if (s.includes('pending') || s.includes('processing')) return 'ax-badge ax-badge-warning';
    if (s.includes('cancel') || s.includes('return')) return 'ax-badge ax-badge-danger';
    return 'ax-badge ax-badge-neutral';
  }

  goBack() { this.router.navigate(['/account']); }
}
