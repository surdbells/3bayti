import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { apiErrorMessage } from '../../shared/http/api-error';

type Bucket = 'overdue' | 'ready' | 'waiting' | 'shipped';

interface ReadinessItem {
  id: number;
  product_id: number;
  product_name: string;
  product_image: string | null;
  quantity: number;
  item_status: string;
  expected_ready_date: string;
  lead_days: number;
  lead_source: 'product' | 'vendor';
  state: 'ready' | 'upcoming' | 'pending' | 'shipped' | 'terminal';
  is_late: boolean;
}

interface ReadinessVendor {
  vendor_id: number;
  vendor_name: string;
  vendor_slug: string;
  pickup: {
    location_code: string | null;
    contact_name: string | null;
    phone: string | null;
    city: string | null;
    is_complete: boolean;
  };
  items: ReadinessItem[];
}

interface ReadinessOrder {
  order_id: number;
  order_reference: string;
  status: string;
  channel: string | null;
  created_at: string;
  readiness: 'ready_to_send' | 'waiting' | 'all_shipped';
  bucket: Bucket;
  is_overdue: boolean;
  due_today: boolean;
  awaiting_acceptance: boolean;
  bottleneck_date: string | null;
  ready_count: number;
  unshipped_count: number;
  active_count: number;
  customer: { id: number; name: string | null; phone: string | null; email: string | null };
  delivery: {
    name: string | null; phone: string | null; street: string | null;
    city: string | null; state: string | null; country: string | null; postcode: string | null;
  } | null;
  vendors: ReadinessVendor[];
}

/**
 * Admin "Delivery readiness" — the operations/logistics single view for the
 * Vendor → Pickup → Consolidated Order → Customer Delivery transition. One card
 * per active order, showing when each product is expected to be ready (from the
 * vendor's per-product lead time), which store ships it (+ pickup), and where
 * it's going, with orders bucketed so overdue / ready-to-send / waiting are
 * obvious at a glance. Read-only; booking still happens on the Deliveries page.
 * GET /admin/delivery-readiness.
 */
@Component({
  selector: 'app-delivery-readiness',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdminShellComponent, IconComponent],
  templateUrl: './delivery-readiness.component.html',
  styleUrls: ['./delivery-readiness.component.scss'],
})
export class DeliveryReadinessComponent implements OnInit {
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);

  loading = false;
  orders: ReadinessOrder[] = [];
  generatedAt = '';

  /** Active bucket filter, '' = all. */
  filter: Bucket | '' = '';
  search = '';

  /** Bucket display order (most urgent first) — also the sort priority. */
  private readonly bucketRank: Record<Bucket, number> = { overdue: 0, ready: 1, waiting: 2, shipped: 3 };

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.adapter.get_v3('GET /admin/delivery-readiness').subscribe({
      next: (res: any) => {
        const d = res?.data ?? res ?? {};
        this.generatedAt = d.generated_at ?? '';
        this.orders = (Array.isArray(d.orders) ? d.orders : []).sort(
          (a: ReadinessOrder, b: ReadinessOrder) => this.sortKey(a) - this.sortKey(b),
        );
        this.loading = false;
      },
      error: (err: any) => {
        this.loading = false;
        this.toast.error(apiErrorMessage(err, 'Unable to load delivery readiness.'));
      },
    });
  }

  /** Lower sorts first: bucket priority, then soonest bottleneck, then oldest order. */
  private sortKey(o: ReadinessOrder): number {
    const rank = this.bucketRank[o.bucket] ?? 9;
    // Days from epoch for the bottleneck date (waiting orders ordered soonest-first).
    const day = o.bottleneck_date ? Math.floor(Date.parse(o.bottleneck_date + 'T00:00:00Z') / 86_400_000) : 0;
    return rank * 1_000_000 + day;
  }

  get counts(): Record<Bucket, number> {
    const c: Record<Bucket, number> = { overdue: 0, ready: 0, waiting: 0, shipped: 0 };
    for (const o of this.orders) c[o.bucket] = (c[o.bucket] ?? 0) + 1;
    return c;
  }

  get filtered(): ReadinessOrder[] {
    const q = this.search.trim().toLowerCase();
    return this.orders.filter((o) => {
      if (this.filter && o.bucket !== this.filter) return false;
      if (!q) return true;
      return (
        o.order_reference.toLowerCase().includes(q) ||
        (o.customer?.name ?? '').toLowerCase().includes(q) ||
        o.vendors.some((v) => v.vendor_name.toLowerCase().includes(q)) ||
        o.vendors.some((v) => v.items.some((i) => i.product_name.toLowerCase().includes(q)))
      );
    });
  }

  setFilter(b: Bucket | ''): void {
    this.filter = this.filter === b ? '' : b;
  }

  // ----- presentation helpers -----

  bucketBadge(b: Bucket): string {
    return {
      overdue: 'ax-badge-danger',
      ready: 'ax-badge-success',
      waiting: 'ax-badge-info',
      shipped: 'ax-badge-muted',
    }[b];
  }

  bucketLabel(o: ReadinessOrder): string {
    switch (o.bucket) {
      case 'overdue': return 'Overdue';
      case 'ready': return 'Ready to send';
      case 'waiting': return 'Waiting';
      case 'shipped': return 'All shipped';
    }
  }

  itemChip(i: ReadinessItem): { cls: string; label: string } {
    switch (i.state) {
      case 'shipped': return { cls: 'ax-badge-muted', label: 'Shipped' };
      case 'pending': return { cls: 'ax-badge-warning', label: 'Awaiting store' };
      case 'ready': return i.is_late
        ? { cls: 'ax-badge-danger', label: 'Ready · overdue' }
        : { cls: 'ax-badge-success', label: 'Ready' };
      case 'upcoming': return { cls: 'ax-badge-info', label: 'Upcoming' };
      default: return { cls: 'ax-badge-muted', label: i.state };
    }
  }

  prettyStatus(s: string): string {
    return String(s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  destination(o: ReadinessOrder): string {
    const d = o.delivery;
    if (!d) return '—';
    return [d.city, d.state, d.country].filter(Boolean).join(', ') || '—';
  }
}
