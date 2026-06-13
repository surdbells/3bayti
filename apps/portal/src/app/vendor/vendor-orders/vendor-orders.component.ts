import { Component, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AxConfirmService } from '../../shared/overlays';
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

interface OrderItem {
  id: number;
  product_id: number;
  product_name: string;
  product_image: string;
  quantity: number;
  unit_price: string;
  subtotal: string;
  size: string | null;
  color: string | null;
  item_status: string;
}

interface OrderDetail {
  id: number;
  order_reference: string;
  status: string;
  date: string;
  subtotal: string;
  delivery_fee: string;
  discount: string;
  total: string;
  currency: string;
  paid_at: string | null;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  items: OrderItem[];
}

interface TimelineEntry {
  status?: string;
  action?: string;
  label?: string;
  at?: string;
  created_at?: string;
  note?: string;
}

/**
 * Client-side mirror of the server's OrderItem transition state machine
 * (src/Domain/Order/OrderItem.php). Lets the drawer show only valid next
 * statuses per item; the server re-validates, so this is UX-only. Shared
 * shape with the admin store-orders screen.
 */
const ITEM_TRANSITIONS: Record<string, string[]> = {
  pending: ['accepted', 'rejected', 'cancelled'],
  accepted: ['preparing', 'cancelled'],
  preparing: ['shipped', 'cancelled'],
  shipped: ['delivered', 'returned'],
  delivered: ['returned'],
  rejected: [],
  cancelled: ['refunded'],
  returned: ['refunded'],
  refunded: [],
};

@Component({
  selector: 'app-vendor-orders',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './vendor-orders.component.html',
  styleUrl: './vendor-orders.component.css',
})
export class VendorOrdersComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<OrderRow>;
  dataSource!: AxServerDataSource<OrderRow>;

  // Drawer state
  readonly drawerOpen = signal(false);
  readonly loadingDetail = signal(false);
  readonly busy = signal(false);
  readonly order = signal<OrderDetail | null>(null);
  readonly timeline = signal<TimelineEntry[]>([]);

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
      rowActions: [{ id: 'manage', label: 'Manage order', icon: 'tune' }],
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
    if (e.action.id === 'manage') this.openOrder(e.row.id);
  }

  // ── Drawer / detail ────────────────────────────────────────────────
  openOrder(id: number) {
    this.drawerOpen.set(true);
    this.loadingDetail.set(true);
    this.order.set(null);
    this.timeline.set([]);

    this.adapter.get_v3('GET /vendor/orders/:id', { params: { id: String(id) } }).subscribe({
      next: (res: any) => {
        this.order.set(res?.order ?? res?.data ?? null);
        this.loadingDetail.set(false);
      },
      error: () => {
        this.toast.error('Unable to load order.');
        this.loadingDetail.set(false);
        this.drawerOpen.set(false);
      },
    });

    this.adapter.get_v3('GET /vendor/orders/:id/timeline', { params: { id: String(id) } }).subscribe({
      next: (res: any) => this.timeline.set(res?.data ?? res?.timeline ?? []),
      error: () => { /* timeline is non-critical */ },
    });
  }

  closeDrawer() { this.drawerOpen.set(false); }

  /** Valid next statuses for an item per the server state machine. */
  nextStatuses(item: OrderItem): string[] {
    return ITEM_TRANSITIONS[item.item_status] ?? [];
  }

  changeItemStatus(item: OrderItem, status: string) {
    const ord = this.order();
    if (!ord) return;
    this.confirm.confirm({
      title: 'Update item status',
      message: `Move "${item.product_name}" to ${this.statusLabel(status)}?`,
      confirmLabel: 'Update', cancelLabel: 'Cancel',
      variant: status === 'cancelled' || status === 'rejected' ? 'danger' : 'default',
    }).then((ok) => {
      if (!ok) return;
      this.busy.set(true);
      this.adapter.patch_v3('PATCH /vendor/orders/:orderId/items/:itemId/status',
        { status },
        { params: { orderId: String(ord.id), itemId: String(item.id) } },
      ).subscribe({
        next: (r: any) => {
          if (r) { this.toast.success('Item status updated.'); this.openOrder(ord.id); this.dataSource.retry(); }
          this.busy.set(false);
        },
        error: () => { this.toast.error('Unable to update item status.'); this.busy.set(false); },
      });
    });
  }

  // ── Display helpers ────────────────────────────────────────────────
  money(v: unknown): string {
    return v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—';
  }

  statusLabel(s: string): string {
    return (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  statusBadgeClass(status: string): string {
    switch (status) {
      case 'paid':
      case 'delivered': return 'ax-badge ax-badge-success';
      case 'fulfilling':
      case 'preparing':
      case 'accepted':
      case 'shipped': return 'ax-badge ax-badge-info';
      case 'pending': return 'ax-badge ax-badge-warning';
      case 'failed':
      case 'rejected':
      case 'cancelled':
      case 'returned': return 'ax-badge ax-badge-danger';
      case 'refunded': return 'ax-badge ax-badge-warning';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  goBack() { this.router.navigate(['/account']); }
}
