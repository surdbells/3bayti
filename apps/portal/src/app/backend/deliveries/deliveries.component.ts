import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { apiErrorMessage } from '../../shared/http/api-error';

/**
 * Admin "Deliveries" — the one place to push orders to the courier (OTO).
 * Lists (order, store) groups ready to book (accepted/preparing items, no
 * shipment yet) with a carrier picker + Book delivery, plus the booked
 * shipments with their live status + tracking. GET /admin/shipments; booking
 * reuses POST /admin/orders/:orderId/vendors/:vendorId/ship.
 */
@Component({
  selector: 'app-admin-deliveries',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdminShellComponent],
  templateUrl: './deliveries.component.html',
})
export class AdminDeliveriesComponent implements OnInit {
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);

  loading = false;
  enabled = false;
  pending: any[] = [];
  booked: any[] = [];
  /** "orderId:vendorId" currently being booked, or ''. */
  booking = '';
  /** Carrier-picker state keyed by "orderId:vendorId". */
  carrier: Record<string, { loading: boolean; loaded: boolean; options: any[]; chosen: string }> = {};

  // ── Filters (client-side over the loaded data) ──────────────────────────
  /** Free-text filter matched against order reference + store, both tables. */
  search = '';
  /** Ready-to-book urgency filter. */
  urgency: 'all' | 'overdue' | 'today' | 'upcoming' = 'all';
  readonly urgencyFilters: { id: 'all' | 'overdue' | 'today' | 'upcoming'; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'overdue', label: 'Overdue' },
    { id: 'today', label: 'Due today' },
    { id: 'upcoming', label: 'Upcoming' },
  ];
  /** Booked-shipments status filter ('' = all). */
  bookedStatus = '';

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading = true;
    this.adapter.get_v3('GET /admin/shipments').subscribe({
      next: (res: any) => {
        const d = res?.data ?? res ?? {};
        this.enabled = !!d.enabled;
        this.pending = Array.isArray(d.pending) ? d.pending : [];
        this.booked = Array.isArray(d.booked) ? d.booked : [];
        this.loading = false;
      },
      error: (err: any) => {
        this.loading = false;
        this.toast.error(apiErrorMessage(err, 'Unable to load deliveries.'));
      },
    });
  }

  key(row: any): string {
    return `${row.order_id}:${row.vendor_id}`;
  }

  carrierFor(row: any): { loading: boolean; loaded: boolean; options: any[]; chosen: string } {
    const k = this.key(row);
    if (!this.carrier[k]) {
      this.carrier[k] = { loading: false, loaded: false, options: [], chosen: '' };
    }
    return this.carrier[k];
  }

  /** Fetch the carriers (name + price) for a pending booking. */
  loadCarriers(row: any): void {
    const c = this.carrierFor(row);
    if (c.loaded || c.loading) return;
    c.loading = true;
    this.adapter
      .get_v3('GET /admin/orders/:orderId/vendors/:vendorId/delivery-options', {
        params: { orderId: String(row.order_id), vendorId: String(row.vendor_id) },
      })
      .subscribe({
        next: (res: any) => {
          const data = res?.data ?? res ?? {};
          c.options = Array.isArray(data.options) ? data.options : [];
          c.loaded = true;
          c.loading = false;
        },
        error: () => { c.loading = false; c.loaded = true; },
      });
  }

  book(row: any): void {
    if (this.booking) return;
    const chosen = this.carrierFor(row).chosen;
    const body = chosen ? { delivery_option_id: chosen } : {};
    this.booking = this.key(row);
    this.adapter
      .post_v3('POST /admin/orders/:orderId/vendors/:vendorId/ship', body, {
        params: { orderId: String(row.order_id), vendorId: String(row.vendor_id) },
      })
      .subscribe({
        next: () => {
          this.booking = '';
          this.toast.success('Delivery booked.');
          this.load();
        },
        error: (err: any) => {
          this.booking = '';
          this.toast.error(apiErrorMessage(err, 'Could not book delivery.'));
        },
      });
  }

  prettyStatus(s: string): string {
    return String(s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  private matchesSearch(row: any): boolean {
    const q = this.search.trim().toLowerCase();
    if (!q) return true;
    return String(row?.order_reference ?? '').toLowerCase().includes(q)
        || String(row?.vendor_name ?? '').toLowerCase().includes(q);
  }

  private matchesUrgency(row: any, urgency = this.urgency): boolean {
    switch (urgency) {
      case 'overdue': return !!row?.is_overdue;
      case 'today': return !!row?.due_today;
      case 'upcoming': return !row?.is_overdue && !row?.due_today;
      default: return true;
    }
  }

  /** Ready-to-book rows after search + urgency filters. */
  get filteredPending(): any[] {
    return this.pending.filter((r) => this.matchesSearch(r) && this.matchesUrgency(r));
  }

  /** Count of search-matching pending rows in an urgency bucket (for the chips). */
  urgencyCount(id: 'all' | 'overdue' | 'today' | 'upcoming'): number {
    return this.pending.filter((r) => this.matchesSearch(r) && this.matchesUrgency(r, id)).length;
  }

  /** Booked rows after search + status filters. */
  get filteredBooked(): any[] {
    return this.booked.filter((s) =>
      this.matchesSearch(s) && (!this.bookedStatus || s?.status === this.bookedStatus));
  }

  /** Distinct statuses present in the booked list, for the status dropdown. */
  get bookedStatuses(): string[] {
    return Array.from(new Set(this.booked.map((s) => String(s?.status ?? '')).filter(Boolean))).sort();
  }

  /** Carrier option label: "Aramex — 22.00 AED · 1-2 days". */
  carrierLabel(o: any): string {
    const price = Number(o?.price);
    const parts = [o?.name || 'Carrier'];
    if (Number.isFinite(price)) parts.push(`— ${price.toFixed(2)} ${o?.currency || ''}`.trim());
    if (o?.eta) parts.push(`· ${o.eta}`);
    return parts.join(' ');
  }
}
