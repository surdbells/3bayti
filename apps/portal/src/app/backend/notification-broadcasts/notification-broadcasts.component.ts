import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface BroadcastRow extends Record<string, unknown> {
  id: number;
  title: string;
  message_preview: string;
  audience_label: string;
  status: string;
  recipients_total: number;
  sent_count: number;
  failed_count: number;
  android_total: number;
  ios_total: number;
  sent_by_name: string;
  created_at: string;
}

/**
 * Notification → History. Every push broadcast (immediate send, resend, or —
 * later — a scheduled occurrence) with its delivery summary. Row click / View
 * opens the detail + recipient drill-down; Resend spins off a new broadcast.
 */
@Component({
  selector: 'app-notification-broadcasts',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './notification-broadcasts.component.html',
  styleUrl: './notification-broadcasts.component.css',
})
export class NotificationBroadcastsComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  config!: AxDataTableConfig<BroadcastRow>;
  dataSource!: AxServerDataSource<BroadcastRow>;

  /** When set (via ?schedule_id=), the list shows one schedule's occurrences. */
  scheduleId: string | null = null;

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.scheduleId = this.route.snapshot.queryParamMap.get('schedule_id');
    this.dataSource = new AxServerDataSource<BroadcastRow>((q) => this.fetch(q), 120);
    this.config = {
      tableId: 'admin-notification-broadcasts',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search broadcasts by title…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No broadcasts yet',
      emptyDescription: 'Push broadcasts you send will appear here with delivery stats.',
      filters: [
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Sent', value: 'sent' },
            { label: 'Partially delivered', value: 'partially_delivered' },
            { label: 'Failed', value: 'failed' },
            { label: 'Queued', value: 'queued' },
            { label: 'Processing', value: 'processing' },
            { label: 'Cancelled', value: 'cancelled' },
          ],
        },
      ],
      columns: [
        { key: 'title', label: 'Notification', sortable: false, sticky: 'left', width: '14rem' },
        { key: 'message_preview', label: 'Message', hideOnMobile: true },
        { key: 'created_at', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'audience_label', label: 'Audience', align: 'center' },
        { key: 'recipients_total', label: 'Recipients', align: 'center' },
        { key: 'sent_count', label: 'Sent', align: 'center' },
        { key: 'failed_count', label: 'Failed', align: 'center' },
        { key: 'android_total', label: 'Android', align: 'center', hideOnMobile: true },
        { key: 'ios_total', label: 'iOS', align: 'center', hideOnMobile: true },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'sent_by_name', label: 'Sent by', hideOnMobile: true, value: (r) => r.sent_by_name || '—' },
      ],
      rowActions: [
        { id: 'view', label: 'View details', icon: 'eye' },
        { id: 'resend-failed', label: 'Resend failed', icon: 'refresh',
          hidden: (r) => r.failed_count < 1 },
        { id: 'resend-all', label: 'Resend to all', icon: 'send' },
      ],
    };
  }

  private fetch(query: AxQueryState) {
    const q: any = { limit: query.pageSize, offset: query.pageIndex * query.pageSize };
    if (query.search) q.search = query.search;
    if (query.filters?.['status']) q.status = query.filters['status'];
    if (this.scheduleId) q.schedule_id = this.scheduleId;
    return this.adapter.get_v3('GET /admin/notification-broadcasts', { query: q }).pipe(
      map((res: any): AxServerFetchResult<BroadcastRow> => {
        const rows: BroadcastRow[] = Array.isArray(res?.data) ? res.data : [];
        return { rows, total: res?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load broadcast history.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<BroadcastRow>);
      }),
    );
  }

  goCompose() {
    this.router.navigate(['/admin/notifications']);
  }

  onRowClick(row: BroadcastRow) {
    this.router.navigate(['/admin/notification-broadcasts', row.id]);
  }

  onRowAction(e: { action: { id: string }; row: BroadcastRow }) {
    switch (e.action.id) {
      case 'view': return this.onRowClick(e.row);
      case 'resend-failed': return this.resend(e.row, 'failed');
      case 'resend-all': return this.resend(e.row, 'all');
    }
  }

  private resend(row: BroadcastRow, mode: 'all' | 'failed') {
    const who = mode === 'failed' ? `${row.failed_count} failed device(s)` : 'all original recipients';
    this.confirm.confirm({
      title: mode === 'failed' ? 'Resend to failed' : 'Resend to all',
      message: `Resend "${row.title}" to ${who}? This creates a new broadcast; the original is kept.`,
      confirmLabel: 'Resend', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (!ok) return;
      this.adapter.post_v3('POST /admin/notification-broadcasts/:id/resend', { mode }, { params: { id: String(row.id) } })
        .subscribe({
          next: (r: any) => {
            const nid = r?.data?.id;
            this.toast.success(r?.data?.queued ? 'Resend queued.' : 'Resend sent.');
            if (nid) this.router.navigate(['/admin/notification-broadcasts', nid]);
            else this.dataSource.retry();
          },
          error: (err) => this.toast.error(err?.error?.error?.message ?? 'Unable to resend.'),
        });
    });
  }

  statusBadge(status: string): string {
    switch (status) {
      case 'sent': return 'ax-badge ax-badge-success';
      case 'partially_delivered': return 'ax-badge ax-badge-warning';
      case 'failed': return 'ax-badge ax-badge-danger';
      case 'queued': case 'processing': return 'ax-badge ax-badge-info';
      case 'cancelled': return 'ax-badge ax-badge-neutral';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  statusLabel(status: string): string {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—';
  }
}
