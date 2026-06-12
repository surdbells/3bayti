import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface OrderRow extends Record<string, unknown> {
  id: number;
  order_ref: string;
  order_reference: string;
  product: string;
  image: string;
  quantity: number;
  email: string;
  total_price: string;
  name: string;
  created: string;
  status: string;
}

@Component({
  selector: 'app-vendor-orders',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './vendor-orders.component.html',
  styleUrl: './vendor-orders.component.css',
})
export class VendorOrdersComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<OrderRow>;
  dataSource!: AxServerDataSource<OrderRow>;

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
    this.dataSource = new AxServerDataSource<OrderRow>((q) => this.fetchOrders(q));
    this.config = {
      tableId: 'vendor-orders',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search orders by product or customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No orders',
      emptyDescription: 'You have no orders matching these filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'orders' },
      filters: [
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Paid', value: 'paid' },
            { label: 'Fulfilling', value: 'fulfilling' },
            { label: 'Shipped', value: 'shipped' },
            { label: 'Delivered', value: 'delivered' },
            { label: 'Cancelled', value: 'cancelled' },
            { label: 'Refunded', value: 'refunded' },
            { label: 'Failed', value: 'failed' },
          ],
        },
      ],
      columns: [
        { key: 'created', label: 'Date', sortable: true, sticky: 'left', width: '11rem' },
        { key: 'product', label: 'Product' },
        { key: 'name', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'total_price', label: 'Total', align: 'right' },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [{ id: 'view', label: 'View order', icon: 'visibility' }],
    };
  }

  private fetchOrders(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    if (query.filters['status']) q.status = query.filters['status'];

    return this.adapter.get_v3('GET /vendor/orders', { query: q }).pipe(
      map((res: any): AxServerFetchResult<OrderRow> => {
        const raw: any[] = res?.data ?? [];
        const rows = raw.map((o) => this.mapOrder(o));
        return { rows, total: res?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load orders right now.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<OrderRow>);
      }),
    );
  }

  private mapOrder(o: any): OrderRow {
    const firstItem = (o.items ?? [])[0] ?? {};
    const customer = o.customer ?? {};
    return {
      id: o.id,
      order_ref: o.order_reference ?? '',
      order_reference: o.order_reference ?? '',
      product: firstItem.product_name ?? `Order ${o.order_reference}`,
      image: firstItem.product_image?.url ?? '',
      quantity: (o.items ?? []).reduce((s: number, i: any) => s + (i.quantity ?? 1), 0),
      email: customer.email ?? '',
      total_price: `AED ${parseFloat(o.subtotal ?? '0').toFixed(2)}`,
      name: `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim() || '—',
      created: o.created_at ? new Date(o.created_at).toLocaleDateString('en-AE') : '',
      status: o.status ?? '',
    };
  }

  onRowAction(e: { action: { id: string }; row: OrderRow }) {
    if (e.action.id === 'view') {
      this.router.navigate(['/', 'order'], { queryParams: { id: e.row.id } });
    }
  }

  statusBadgeClass(status: string): string {
    switch (status) {
      case 'paid':
      case 'delivered': return 'ax-badge ax-badge-success';
      case 'fulfilling':
      case 'shipped': return 'ax-badge ax-badge-info';
      case 'failed':
      case 'cancelled': return 'ax-badge ax-badge-danger';
      case 'refunded': return 'ax-badge ax-badge-warning';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  goBack() { this.router.navigate(['/account']); }
}
