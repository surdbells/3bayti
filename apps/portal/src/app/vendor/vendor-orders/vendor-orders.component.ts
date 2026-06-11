import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  AxTableComponent,
  AxColumnComponent,
  AxEmptyStateComponent,
} from '../../shared/data';

/** Flat display row — kept for template compatibility. */
interface OrderRow {
  id: number;
  order_ref: string;
  product: string;
  image: string;
  quantity: number;
  email: string;
  total_price: string;
  name: string;
  created: string;
  status: string;
  /** v3 order_reference, used when navigating to view-order. */
  order_reference: string;
}

/** v3 status → label for the filter dropdown. */
const V3_STATUS_OPTIONS = [
  { value: '',             label: 'All orders' },
  { value: 'paid',         label: 'Paid' },
  { value: 'fulfilling',   label: 'Fulfilling' },
  { value: 'shipped',      label: 'Shipped' },
  { value: 'delivered',    label: 'Delivered' },
  { value: 'cancelled',    label: 'Cancelled' },
  { value: 'refunded',     label: 'Refunded' },
  { value: 'failed',       label: 'Failed' },
];

@Component({
  selector: 'app-vendor-orders',
  imports: [
    VendorShellComponent,
    CommonModule,
    FormsModule,
    AxTableComponent,
    AxColumnComponent,
    AxEmptyStateComponent,
  ],
  standalone: true,
  templateUrl: './vendor-orders.component.html',
  styleUrl: './vendor-orders.component.css',
})
export class VendorOrdersComponent implements OnInit {
  orders: OrderRow[] = [];
  selectedStatus = '';

  ui_controls = {
    is_loading: false,
    no_orders: false,
  };

  readonly statusOptions = V3_STATUS_OPTIONS;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.loadOrders();
  }

  goBack(): void {
    this.router.navigate(['/account']);
  }

  onStatusChange(event: Event): void {
    this.selectedStatus = (event.target as HTMLSelectElement).value;
    this.loadOrders();
  }

  loadOrders(): void {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_orders = false;
    const query: Record<string, string | number | boolean | undefined | null> = {
      limit: 50,
      offset: 0,
    };
    if (this.selectedStatus) {
      query['status'] = this.selectedStatus;
    }
    this.adapter.get_v3('GET /vendor/orders', { query }).subscribe({
      next: (res: any) => {
        this.ui_controls.is_loading = false;
        const raw: any[] = res?.data ?? [];
        this.orders = raw.map((o) => this.mapOrder(o));
        this.ui_controls.no_orders = this.orders.length === 0;
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.ui_controls.no_orders = true;
        this.toast.error('Unable to load orders right now.');
      },
    });
  }

  open_order(id: number, _name: string): void {
    this.router.navigate(['/', 'order'], { queryParams: { id } });
  }

  statusBadgeClass(status: string): string {
    switch (status) {
      case 'paid':
      case 'delivered': return 'ax-badge-success';
      case 'fulfilling':
      case 'shipped':   return 'ax-badge-primary';
      case 'failed':
      case 'cancelled': return 'ax-badge-danger';
      case 'refunded':  return 'ax-badge-warning';
      default:          return 'ax-badge-neutral';
    }
  }

  statusLabel(status: string): string {
    const found = V3_STATUS_OPTIONS.find((s) => s.value === status);
    return found?.label ?? status;
  }

  // ── private ─────────────────────────────────────────────────────────

  /** Map a v3 order envelope to the flat OrderRow the template uses. */
  private mapOrder(o: any): OrderRow {
    const firstItem = (o.items ?? [])[0] ?? {};
    const customer  = o.customer ?? {};
    return {
      id:               o.id,
      order_ref:        o.order_reference ?? '',
      order_reference:  o.order_reference ?? '',
      product:          firstItem.product_name ?? `Order ${o.order_reference}`,
      image:            firstItem.product_image?.url ?? '',
      quantity:         (o.items ?? []).reduce((s: number, i: any) => s + (i.quantity ?? 1), 0),
      email:            customer.email ?? '',
      total_price:      `AED ${parseFloat(o.subtotal ?? '0').toFixed(2)}`,
      name:             `${customer.first_name ?? ''} ${customer.last_name ?? ''}`.trim() || '—',
      created:          o.created_at ? new Date(o.created_at).toLocaleDateString('en-AE') : '',
      status:           o.status ?? '',
    };
  }
}
