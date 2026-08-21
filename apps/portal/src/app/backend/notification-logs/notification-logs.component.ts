import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { HotToastService } from '../../shared/toast/toast.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';
import { apiErrorMessage } from '../../shared/http/api-error';

interface NotificationLog extends Record<string, unknown> {
  id: number;
  order_id: number | null;
  template: string;
  recipient: string;
  status: 'sent' | 'failed';
  sent_at: string;
  error_kind: string | null;
  error_message: string | null;
  created_at: string;
}

@Component({
  selector: 'app-notification-logs',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './notification-logs.component.html',
  styleUrl: './notification-logs.component.css',
})
export class NotificationLogsComponent implements OnInit {
  config!: AxDataTableConfig<NotificationLog>;
  dataSource!: AxServerDataSource<NotificationLog>;

  constructor(private adapter: PortalCrudAdapter, private toast: HotToastService) {}

  ngOnInit(): void {
    this.buildTable();
  }

  private buildTable(): void {
    this.dataSource = new AxServerDataSource<NotificationLog>((q) => this.fetchLogs(q));
    this.config = {
      tableId: 'admin-notification-logs',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search by recipient or template…',
      stickyHeader: true,
      hover: true,
      compact: true,
      emptyTitle: 'No notification logs',
      emptyDescription: 'No notification delivery records match your filters.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'notification-logs' },
      filters: [
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Sent', value: 'sent' },
            { label: 'Failed', value: 'failed' },
          ],
        },
      ],
      columns: [
        { key: 'id', label: 'ID', sortable: true, sticky: 'left', width: '6rem' },
        { key: 'template', label: 'Template' },
        { key: 'recipient', label: 'Recipient', hideOnMobile: true },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'order_id', label: 'Order ID', align: 'center', hideOnMobile: true,
          format: (v) => (v != null ? String(v) : '—') },
        { key: 'sent_at', label: 'Sent at', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'error_message', label: 'Error', hideOnMobile: true,
          format: (v) => (v ? String(v) : '—') },
      ],
    };
  }

  private fetchLogs(query: AxQueryState) {
    // Routed through the adapter (not a raw fetch) so it shares the
    // transparent token-refresh — an expired access token refreshes and
    // retries instead of failing / signing the user out.
    const q: any = { limit: query.pageSize, offset: query.pageIndex * query.pageSize };
    if (query.filters['status']) q.status = query.filters['status'];
    if (query.search) q.search = query.search;

    return this.adapter.get_v3('GET /admin/notification-logs', { query: q }).pipe(
      map((res: any): AxServerFetchResult<NotificationLog> => ({
        rows: res?.data ?? [],
        total: res?.meta?.total ?? (res?.data?.length ?? 0),
      })),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Failed to load logs'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<NotificationLog>);
      }),
    );
  }
}
