import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { AxConfirmService } from '../../shared/overlays';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';

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

interface OrderCustomer {
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  phone: string | null;
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
  customer: OrderCustomer | null;
  items: OrderItem[];
}

/** An event from GET /v3/vendor/orders/{id}/timeline (OrderTimelineSerializer). */
interface TimelineEntry {
  id?: string;
  type?: string;
  summary?: string;
  occurred_at?: string;
  actor?: { type?: string; name?: string } | null;
}

/**
 * Client-side mirror of the server's OrderItem transition state machine
 * (src/Domain/Order/OrderItem.php). Lets the page show only valid next
 * statuses per item; the server re-validates, so this is UX-only.
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

/**
 * Dedicated vendor order-detail page (replaces the old right-side drawer on
 * /orders). Reached at /orders/:id. Shows the customer, line items with a
 * per-item status control, totals, and the order timeline.
 */
@Component({
  selector: 'app-vendor-order-detail',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, FormsModule, IconComponent],
  templateUrl: './vendor-order-detail.component.html',
  styleUrl: './vendor-order-detail.component.css',
})
export class VendorOrderDetailComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  readonly loadingDetail = signal(true);
  readonly busy = signal(false);
  readonly order = signal<OrderDetail | null>(null);
  readonly timeline = signal<TimelineEntry[]>([]);

  private orderId = 0;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.orderId = Number(this.route.snapshot.paramMap.get('id') || 0);
    if (!this.orderId) {
      this.router.navigate(['/orders']);
      return;
    }
    this.load();
  }

  private load() {
    this.loadingDetail.set(true);

    this.adapter.get_v3('GET /vendor/orders/:id', { params: { id: String(this.orderId) } }).subscribe({
      next: (res: any) => {
        this.order.set(res?.order ?? res?.data ?? null);
        this.loadingDetail.set(false);
      },
      error: () => {
        this.toast.error('Unable to load order.');
        this.loadingDetail.set(false);
      },
    });

    // Timeline is non-critical — a failure just hides the section. Response is
    // { data: [events], meta }; each event has { type, summary, occurred_at }.
    this.adapter.get_v3('GET /vendor/orders/:id/timeline', { params: { id: String(this.orderId) } }).subscribe({
      next: (res: any) => this.timeline.set(res?.data ?? res?.timeline ?? []),
      error: () => { /* non-critical */ },
    });
  }

  /** Full customer name, or "—" when missing. */
  customerName(): string {
    const c = this.order()?.customer;
    if (!c) return '—';
    return `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim() || '—';
  }

  /** Valid next statuses for an item per the server state machine. */
  nextStatuses(item: OrderItem): string[] {
    return ITEM_TRANSITIONS[item.item_status] ?? [];
  }

  changeItemStatus(item: OrderItem, status: string) {
    const ord = this.order();
    if (!ord || !status) return;
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
          if (r) { this.toast.success('Item status updated.'); this.load(); }
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

  goBack() { this.router.navigate(['/orders']); }
}
