import { Component, OnInit, OnDestroy, inject } from '@angular/core';
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

interface RecipientRow extends Record<string, unknown> {
  id: number;
  user_id: number | null;
  platform: string;
  token_suffix: string;
  status: string;
  error_kind: string | null;
  error_message: string | null;
  sent_at: string | null;
}

/** Broadcast detail: summary, delivery stats, device breakdown, and the
 *  per-recipient drill-down. Polls while the broadcast is still processing. */
@Component({
  selector: 'app-notification-broadcast-detail',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './notification-broadcast-detail.component.html',
  styleUrl: './notification-broadcasts.component.css',
})
export class NotificationBroadcastDetailComponent implements OnInit, OnDestroy {
  private readonly confirm = inject(AxConfirmService);

  id = 0;
  loading = true;
  broadcast: any = null;
  private pollTimer: any = null;

  config!: AxDataTableConfig<RecipientRow>;
  dataSource!: AxServerDataSource<RecipientRow>;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.id = Number(this.route.snapshot.paramMap.get('id')) || 0;
    this.buildTable();
    this.loadDetail();
  }

  ngOnDestroy() {
    if (this.pollTimer) clearTimeout(this.pollTimer);
  }

  private loadDetail() {
    this.adapter.get_v3('GET /admin/notification-broadcasts/:id', { params: { id: String(this.id) } }).subscribe({
      next: (res: any) => {
        this.broadcast = res?.data ?? null;
        this.loading = false;
        // Keep refreshing while it's still sending so counters climb live.
        if (this.broadcast && (this.broadcast.status === 'queued' || this.broadcast.status === 'processing')) {
          this.pollTimer = setTimeout(() => { this.loadDetail(); this.dataSource?.retry(); }, 4000);
        }
      },
      error: () => { this.loading = false; this.toast.error('Unable to load broadcast.'); },
    });
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<RecipientRow>((q) => this.fetchRecipients(q), 120);
    this.config = {
      tableId: 'broadcast-recipients',
      mode: 'server',
      rowId: 'id',
      pageSize: 25,
      pageSizeOptions: [25, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search by device / user…',
      stickyHeader: true,
      hover: false,
      emptyTitle: 'No recipients',
      emptyDescription: 'Recipient results appear here as the broadcast sends.',
      filters: [
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Sent', value: 'sent' },
            { label: 'Failed', value: 'failed' },
            { label: 'Pending', value: 'pending' },
          ],
        },
        {
          key: 'platform', label: 'Device', type: 'select',
          options: [
            { label: 'Android', value: 'android' },
            { label: 'iOS', value: 'ios' },
          ],
        },
      ],
      columns: [
        { key: 'user_id', label: 'User', value: (r) => (r.user_id ? `#${r.user_id}` : '—') },
        { key: 'platform', label: 'Device', align: 'center' },
        { key: 'token_suffix', label: 'Token', value: (r) => r.token_suffix || '—' },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'error_kind', label: 'Reason', value: (r) => r.error_kind || '—' },
        { key: 'sent_at', label: 'Sent', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleTimeString() : '—') },
      ],
    };
  }

  private fetchRecipients(query: AxQueryState) {
    const q: any = { limit: query.pageSize, offset: query.pageIndex * query.pageSize };
    if (query.search) q.search = query.search;
    if (query.filters?.['status']) q.status = query.filters['status'];
    if (query.filters?.['platform']) q.platform = query.filters['platform'];
    return this.adapter.get_v3('GET /admin/notification-broadcasts/:id/recipients', {
      params: { id: String(this.id) }, query: q,
    }).pipe(
      map((res: any): AxServerFetchResult<RecipientRow> => {
        const rows: RecipientRow[] = Array.isArray(res?.data) ? res.data : [];
        return { rows, total: res?.meta?.total ?? rows.length };
      }),
      catchError(() => of({ rows: [], total: 0 } as AxServerFetchResult<RecipientRow>)),
    );
  }

  resend(mode: 'all' | 'failed') {
    this.confirm.confirm({
      title: mode === 'failed' ? 'Resend to failed' : 'Resend to all',
      message: `Resend "${this.broadcast?.title}" to ${mode === 'failed' ? 'the failed devices' : 'all original recipients'}? This creates a new broadcast.`,
      confirmLabel: 'Resend', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (!ok) return;
      this.adapter.post_v3('POST /admin/notification-broadcasts/:id/resend', { mode }, { params: { id: String(this.id) } })
        .subscribe({
          next: (r: any) => {
            const nid = r?.data?.id;
            this.toast.success(r?.data?.queued ? 'Resend queued.' : 'Resend sent.');
            if (nid) this.router.navigate(['/admin/notification-broadcasts', nid]);
          },
          error: (err) => this.toast.error(err?.error?.error?.message ?? 'Unable to resend.'),
        });
    });
  }

  back() { this.router.navigate(['/admin/notification-broadcasts']); }

  statusBadge(status: string): string {
    switch (status) {
      case 'sent': return 'ax-badge ax-badge-success';
      case 'partially_delivered': return 'ax-badge ax-badge-warning';
      case 'failed': return 'ax-badge ax-badge-danger';
      case 'queued': case 'processing': return 'ax-badge ax-badge-info';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  statusLabel(status: string): string {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—';
  }
}
