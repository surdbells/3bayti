import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { IconComponent } from '../../../shared/icon/icon.component';

interface OrderItemRow {
  id: number;
  product_name: string;
  quantity: number;
  unit_price: string;
  subtotal: string;
  item_status: string;
  vendor_id: number;
  size?: string | null;
  color?: string | null;
  note?: string | null;
  // local edit state
  _statusDraft: string;
  _reason: string;
  _saving: boolean;
}

interface TimelineEvent {
  id: string;
  type: string;
  occurred_at: string;
  actor?: { type?: string; id?: number };
  summary?: string;
}

/** Canonical lifecycle states (mirror Order::STATUS_* / OrderItem::ITEM_STATUS_*). */
const ORDER_STATUSES = [
  'pending_payment', 'paid', 'fulfilling', 'shipped', 'delivered', 'cancelled', 'refunded', 'failed',
] as const;
const ITEM_STATUSES = [
  'pending', 'accepted', 'rejected', 'preparing', 'shipped', 'delivered', 'cancelled', 'returned', 'refunded',
] as const;

@Component({
  selector: 'app-single',
  standalone: true,
  imports: [CommonModule, FormsModule, IconComponent],
  templateUrl: './single.component.html',
  styleUrl: './single.component.css',
})
export class SingleComponent implements OnInit {
  readonly orderStatuses = ORDER_STATUSES;
  readonly itemStatuses = ITEM_STATUSES;

  ui = {
    loading: false,
    not_found: false,
    saving_status: false,
    cancelling: false,
    refunding: false,
    timeline_loading: false,
  };

  user_session: any = { id: 0, token: '' };

  orderId = '';
  order: any = null; // adminDetailShape
  items: OrderItemRow[] = [];
  timeline: TimelineEvent[] = [];

  // Order-level action drafts
  statusDraft = '';
  statusReason = '';
  showCancel = false;
  cancelReason = '';
  showRefund = false;
  refundAmount = '';
  refundReason = '';

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private route: ActivatedRoute,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.orderId = this.route.snapshot.queryParamMap.get('order') ?? '';
    if (!this.orderId) {
      this.ui.not_found = true;
      return;
    }
    this.loadOrder();
  }

  // ───────────────────────── Load ─────────────────────────

  loadOrder() {
    this.ui.loading = true;
    this.ui.not_found = false;
    this.adapter.get_v3('GET /admin/orders/:id', { params: { id: this.orderId } }).subscribe({
      next: (res: any) => {
        const order = res?.order ?? res?.data ?? null;
        if (!order) {
          this.ui.not_found = true;
          this.ui.loading = false;
          return;
        }
        this.order = order;
        this.statusDraft = order.status ?? '';
        this.items = (order.items ?? []).map((it: any): OrderItemRow => ({
          id: it.id,
          product_name: it.product_name,
          quantity: it.quantity,
          unit_price: it.unit_price,
          subtotal: it.subtotal,
          item_status: it.item_status,
          vendor_id: it.vendor_id,
          size: it.size,
          color: it.color,
          note: it.note,
          _statusDraft: it.item_status,
          _reason: '',
          _saving: false,
        }));
        this.ui.loading = false;
        this.loadTimeline();
      },
      error: () => {
        this.ui.not_found = true;
        this.ui.loading = false;
      },
    });
  }

  loadTimeline() {
    this.ui.timeline_loading = true;
    this.adapter
      .get_v3('GET /admin/orders/:id/timeline', { params: { id: this.orderId }, query: { order: 'desc', limit: 100 } })
      .subscribe({
        next: (res: any) => {
          this.timeline = (res?.data ?? []) as TimelineEvent[];
          this.ui.timeline_loading = false;
        },
        error: () => {
          this.ui.timeline_loading = false;
        },
      });
  }

  // ─────────────────────── Derived ───────────────────────

  get customerName(): string {
    const c = this.order?.customer ?? {};
    return `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim() || c.email || '—';
  }

  get shipping(): any {
    return this.order?.shipping_address ?? {};
  }

  get shippingName(): string {
    const s = this.shipping;
    return `${s.first_name ?? ''} ${s.last_name ?? ''}`.trim() || '—';
  }

  get isTerminal(): boolean {
    return ['cancelled', 'refunded', 'failed'].includes(this.order?.status);
  }

  money(v: any): string {
    if (v == null || v === '') {
      return '—';
    }
    const cur = this.order?.currency ?? 'AED';
    return `${cur} ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
  }

  prettyStatus(s: string): string {
    return (s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  orderBadgeClass(status: string): string {
    if (status === 'delivered') return 'ax-badge ax-badge-success';
    if (status === 'paid' || status === 'fulfilling' || status === 'shipped') return 'ax-badge ax-badge-info';
    if (status === 'pending_payment') return 'ax-badge ax-badge-warning';
    if (status === 'cancelled' || status === 'refunded' || status === 'failed') return 'ax-badge ax-badge-danger';
    return 'ax-badge ax-badge-neutral';
  }

  itemBadgeClass(status: string): string {
    if (status === 'delivered') return 'ax-badge ax-badge-success';
    if (status === 'accepted' || status === 'preparing' || status === 'shipped') return 'ax-badge ax-badge-info';
    if (status === 'pending') return 'ax-badge ax-badge-warning';
    if (['rejected', 'cancelled', 'returned', 'refunded'].includes(status)) return 'ax-badge ax-badge-danger';
    return 'ax-badge ax-badge-neutral';
  }

  eventIcon(type: string): string {
    if (type.startsWith('order.created')) return 'add_shopping_cart';
    if (type.startsWith('order.paid')) return 'payments';
    if (type.includes('status')) return 'sync_alt';
    if (type.startsWith('notification')) return 'notifications';
    if (type.startsWith('return')) return 'undo';
    if (type.includes('refund')) return 'currency_exchange';
    if (type.includes('cancel')) return 'cancel';
    return 'history';
  }

  timeAgo(iso: string): string {
    const then = new Date(iso).getTime();
    if (isNaN(then)) return '';
    const secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
    if (secs < 60) return 'just now';
    const mins = Math.floor(secs / 60);
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    const days = Math.floor(hrs / 24);
    if (days < 7) return `${days}d ago`;
    return new Date(iso).toLocaleDateString();
  }

  private errMsg(e: any, fallback: string): string {
    return e?.error?.error?.message ?? e?.error?.message ?? fallback;
  }

  // ─────────────────── Order status override ───────────────────

  get orderStatusDirty(): boolean {
    return !!this.statusDraft && this.statusDraft !== this.order?.status;
  }

  updateOrderStatus() {
    if (!this.orderStatusDirty) {
      this.toast.error('Choose a different status.');
      return;
    }
    if (!this.statusReason.trim()) {
      this.toast.error('A reason is required for an admin status change.');
      return;
    }
    this.ui.saving_status = true;
    this.adapter
      .patch_v3(
        'PATCH /admin/orders/:id/status',
        { status: this.statusDraft, reason: this.statusReason.trim() },
        { params: { id: this.orderId } },
      )
      .subscribe({
        next: () => {
          this.toast.success('Order status updated.');
          this.statusReason = '';
          this.ui.saving_status = false;
          this.loadOrder();
        },
        error: (e: any) => {
          this.toast.error(this.errMsg(e, 'Unable to update order status.'));
          this.ui.saving_status = false;
        },
      });
  }

  // ─────────────────── Per-item status override ───────────────────

  itemDirty(item: OrderItemRow): boolean {
    return !!item._statusDraft && item._statusDraft !== item.item_status;
  }

  saveItemStatus(item: OrderItemRow) {
    if (!this.itemDirty(item)) {
      return;
    }
    if (!item._reason.trim()) {
      this.toast.error('A reason is required to change an item status.');
      return;
    }
    item._saving = true;
    this.adapter
      .patch_v3(
        'PATCH /admin/orders/:orderId/items/:itemId/status',
        { status: item._statusDraft, reason: item._reason.trim() },
        { params: { orderId: this.orderId, itemId: String(item.id) } },
      )
      .subscribe({
        next: () => {
          this.toast.success('Item status updated.');
          item.item_status = item._statusDraft;
          item._reason = '';
          item._saving = false;
          this.loadTimeline();
        },
        error: (e: any) => {
          this.toast.error(this.errMsg(e, 'Unable to update item status.'));
          item._saving = false;
        },
      });
  }

  resetItem(item: OrderItemRow) {
    item._statusDraft = item.item_status;
    item._reason = '';
  }

  // ─────────────────── Cancel ───────────────────

  confirmCancel() {
    if (!this.cancelReason.trim()) {
      this.toast.error('A cancellation reason is required.');
      return;
    }
    this.ui.cancelling = true;
    this.adapter
      .post_v3('POST /admin/orders/:id/cancel', { reason: this.cancelReason.trim() }, { params: { id: this.orderId } })
      .subscribe({
        next: () => {
          this.toast.success('Order cancelled.');
          this.showCancel = false;
          this.cancelReason = '';
          this.ui.cancelling = false;
          this.loadOrder();
        },
        error: (e: any) => {
          this.toast.error(this.errMsg(e, 'Unable to cancel this order.'));
          this.ui.cancelling = false;
        },
      });
  }

  // ─────────────────── Refund ───────────────────

  confirmRefund() {
    if (!this.refundReason.trim()) {
      this.toast.error('A refund reason is required.');
      return;
    }
    const body: any = { reason: this.refundReason.trim() };
    const amt = this.refundAmount.trim();
    if (amt) {
      const n = Number(amt);
      if (isNaN(n) || n <= 0) {
        this.toast.error('Enter a valid refund amount, or leave it blank for a full refund.');
        return;
      }
      body.amount = n.toFixed(2);
    }
    this.ui.refunding = true;
    this.adapter
      .post_v3('POST /admin/orders/:id/refund', body, { params: { id: this.orderId } })
      .subscribe({
        next: () => {
          this.toast.success('Refund processed.');
          this.showRefund = false;
          this.refundAmount = '';
          this.refundReason = '';
          this.ui.refunding = false;
          this.loadOrder();
        },
        error: (e: any) => {
          this.toast.error(this.errMsg(e, 'Unable to process the refund.'));
          this.ui.refunding = false;
        },
      });
  }

  close() {
    window.close();
  }
}
