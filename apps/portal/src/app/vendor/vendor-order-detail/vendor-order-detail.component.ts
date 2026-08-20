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
  is_custom?: boolean;
  measurement?: string | null;
  extra_measurement?: string | null;
  note?: string | null;
  item_status: string;
}

interface OrderCustomer {
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  phone: string | null;
}

interface OrderAddress {
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  email: string | null;
  street: string | null;
  city: string | null;
  state_province: string | null;
  country_code: string | null;
  postal_code: string | null;
}

/** The customer's saved measurement profile (one row per category). */
interface CustomerMeasurement {
  id: number;
  category_id: number | null;
  values: Record<string, number>;
  notes: string | null;
  updated_at?: string;
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
  shipping_address: OrderAddress | null;
  items: OrderItem[];
  customer_measurements?: CustomerMeasurement[];
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

  /** Recipient name from the shipping address, or "—" when missing. */
  addressName(a: OrderAddress | null | undefined): string {
    if (!a) return '—';
    return `${a.first_name ?? ''} ${a.last_name ?? ''}`.trim() || '—';
  }

  /**
   * Flatten an item's `measurement` snapshot into label/value pairs. The column
   * is text that may hold a JSON object ({bust:'36', length:'58'}) or free text;
   * JSON becomes labelled rows, free text a single "Details" row. The separate
   * `extra_measurement` is rendered on its own (see extraMeasurementText).
   * Mirrors the server's OrderEmailTemplateRenderer::measurementPairs.
   */
  measurementPairs(item: OrderItem): { label: string; value: string }[] {
    return this.parseMeasurement(item.measurement);
  }

  private parseMeasurement(raw: string | null | undefined): { label: string; value: string }[] {
    const out: { label: string; value: string }[] = [];
    const trimmed = (raw ?? '').toString().trim();
    if (trimmed === '') return out;
    let decoded: any = null;
    try { decoded = JSON.parse(trimmed); } catch { decoded = null; }
    if (decoded && typeof decoded === 'object' && !Array.isArray(decoded)) {
      for (const [k, v] of Object.entries(decoded)) {
        if (v === null || v === '' || typeof v === 'object') continue;
        out.push({ label: this.humanizeKey(k), value: String(v) });
      }
    } else {
      out.push({ label: 'Details', value: trimmed });
    }
    return out;
  }

  /**
   * The item's `extra_measurement` (a vendor-specific measurement beyond the
   * profile) as display text — a JSON object becomes "Label: value, …"; free
   * text is shown as-is. Empty string when not provided.
   */
  extraMeasurementText(item: OrderItem): string {
    const pairs = this.parseMeasurement(item.extra_measurement);
    if (pairs.length === 1 && pairs[0].label === 'Details') {
      return pairs[0].value;
    }
    return pairs.map((p) => `${p.label}: ${p.value}`).join(', ');
  }

  private humanizeKey(k: string): string {
    return k.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  /**
   * The customer's saved measurement profile(s), filtered to rows that
   * actually carry values. This is the authoritative set the customer keeps on
   * their account — shown so the vendor can fulfil made-to-measure orders even
   * when the per-item snapshot wasn't captured (non-custom lines, or legacy
   * orders migrated without one).
   */
  customerMeasurements(): CustomerMeasurement[] {
    const rows = this.order()?.customer_measurements ?? [];
    return rows.filter((m) => this.measurementValueRows(m).length > 0);
  }

  /** Flatten a profile row's values map into humanised label/value (cm) pairs. */
  measurementValueRows(m: CustomerMeasurement): { label: string; value: string }[] {
    const out: { label: string; value: string }[] = [];
    for (const [k, v] of Object.entries(m?.values ?? {})) {
      if (v === null || v === undefined || Number.isNaN(Number(v))) continue;
      out.push({ label: this.humanizeKey(k), value: `${Number(v)} cm` });
    }
    return out;
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
