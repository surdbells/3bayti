import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { HotToastService } from '../../shared/toast/toast.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { GlobalComponent } from '../../global-component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

const V3 = 'https://api-v3.3bayti.ae';

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
  private token = '';

  config!: AxDataTableConfig<NotificationLog>;
  dataSource!: AxServerDataSource<NotificationLog>;

  constructor(private http: HttpClient, private toast: HotToastService) {}

  ngOnInit(): void {
    const raw = sessionStorage.getItem('SESSION');
    if (raw) this.token = GlobalComponent.decodeBase64(raw)?.token ?? '';
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
    const params = new URLSearchParams({
      limit: String(query.pageSize),
      offset: String(query.pageIndex * query.pageSize),
    });
    if (query.filters['status']) params.set('status', String(query.filters['status']));
    if (query.search) params.set('search', query.search);
    const headers = new HttpHeaders({ Authorization: `Bearer ${this.token}` });

    return this.http.get<{ data: NotificationLog[]; meta: { total: number } }>(
      `${V3}/v3/admin/notification-logs?${params}`, { headers },
    ).pipe(
      map((res): AxServerFetchResult<NotificationLog> => ({
        rows: res.data ?? [],
        total: res.meta?.total ?? (res.data?.length ?? 0),
      })),
      catchError(() => {
        this.toast.error('Failed to load logs');
        return of({ rows: [], total: 0 } as AxServerFetchResult<NotificationLog>);
      }),
    );
  }
}
