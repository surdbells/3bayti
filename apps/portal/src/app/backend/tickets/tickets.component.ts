import { Component, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
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

export interface Tickets extends Record<string, unknown> {
  ticket_id: number;
  ticket_ref: string;
  subject: string;
  message: string;
  priority: string;
  store_name: string;
  messages: number;
  status: string;
  created: string;
  updated: string;
}

@Component({
  selector: 'app-tickets',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './tickets.component.html',
  styleUrl: './tickets.component.css',
})
export class TicketsComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<Tickets>;
  dataSource!: AxServerDataSource<Tickets>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<Tickets>((q) => this.fetchTickets(q));
    this.config = {
      tableId: 'admin-tickets',
      mode: 'server',
      rowId: 'ticket_id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search tickets by ref or subject…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No tickets found',
      emptyDescription: 'No support tickets match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'support-tickets' },
      filters: [
        {
          key: 'status', label: 'Status', type: 'select',
          options: [
            { label: 'Open', value: 'open' },
            { label: 'Pending', value: 'pending' },
            { label: 'Resolved', value: 'resolved' },
            { label: 'Closed', value: 'closed' },
          ],
        },
        {
          key: 'priority', label: 'Priority', type: 'select',
          options: [
            { label: 'Low', value: 'low' },
            { label: 'Medium', value: 'medium' },
            { label: 'High', value: 'high' },
            { label: 'Urgent', value: 'urgent' },
          ],
        },
      ],
      columns: [
        { key: 'ticket_ref', label: 'Ref', sortable: true, sticky: 'left', width: '10rem' },
        { key: 'store_name', label: 'Store', hideOnMobile: true },
        { key: 'subject', label: 'Subject' },
        { key: 'priority', label: 'Priority', align: 'center' },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'messages', label: 'Msgs', align: 'center', hideOnMobile: true },
        { key: 'created', label: 'Created', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
      ],
      rowActions: [
        { id: 'view', label: 'Open ticket', icon: 'forum' },
        { id: 'priority', label: 'Set priority', icon: 'flag' },
        { id: 'status', label: 'Set status', icon: 'task_alt' },
      ],
    };
  }

  private fetchTickets(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    if (query.filters['status']) q.status = query.filters['status'];
    if (query.filters['priority']) q.priority = query.filters['priority'];

    return this.adapter.get_v3('GET /admin/tickets', { query: q }).pipe(
      map((response: any): AxServerFetchResult<Tickets> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((t) => this.mapRow(t));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load tickets at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<Tickets>);
      }),
    );
  }

  /** Map the /admin/tickets shape into the row. */
  private mapRow(t: any): Tickets {
    return {
      ...t,
      ticket_id: t.id,
      ticket_ref: t.reference ?? `#${t.id}`,
      store_name: t.vendor_name ?? (t.vendor_id ? `Store #${t.vendor_id}` : '—'),
      subject: t.subject ?? '',
      priority: t.priority ?? '',
      status: t.status ?? '',
      messages: t.message_count ?? 0,
      created: t.created_at ?? '',
      updated: t.updated_at ?? '',
    } as Tickets;
  }

  onRowAction(e: { action: { id: string }; row: Tickets }) {
    const { action, row } = e;
    if (action.id === 'view') {
      const urlTree = this.router.createUrlTree(['/ticket_messages'], {
        queryParams: { id: row.ticket_id, name: row.subject },
      });
      window.open(this.router.serializeUrl(urlTree), '_blank');
    } else if (action.id === 'priority') {
      this.cyclePriority(row);
    } else if (action.id === 'status') {
      this.cycleStatus(row);
    }
  }

  private cyclePriority(row: Tickets) {
    const order = ['low', 'medium', 'high', 'urgent'];
    const next = order[(order.indexOf(row.priority) + 1) % order.length];
    this.confirm.confirm({
      title: 'Change priority',
      message: `Set "${row.subject}" priority to ${next}?`,
      confirmLabel: 'Update', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (ok) this.adapter.patch_v3('PATCH /admin/tickets/:id/priority', { priority: next }, { params: { id: String(row.ticket_id) } })
        .subscribe({ next: (r: any) => { if (r) { this.toast.success('Priority updated.'); this.refresh(); } } });
    });
  }

  private cycleStatus(row: Tickets) {
    const order = ['open', 'pending', 'resolved', 'closed'];
    const next = order[(order.indexOf(row.status) + 1) % order.length];
    this.confirm.confirm({
      title: 'Change status',
      message: `Set "${row.subject}" status to ${next}?`,
      confirmLabel: 'Update', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (ok) this.adapter.patch_v3('PATCH /admin/tickets/:id/status', { status: next }, { params: { id: String(row.ticket_id) } })
        .subscribe({ next: (r: any) => { if (r) { this.toast.success('Status updated.'); this.refresh(); } } });
    });
  }

  private refresh() { this.dataSource.retry(); }

  goBack() { this.router.navigate(['/backend']); }
}
