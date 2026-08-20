import { Component, HostListener, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
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

interface AuditRow extends Record<string, unknown> {
  id: number;
  created: string;
  actor: string;
  action: string;
  subject: string;
  ip: string;
  /** Full serialized log, kept for the detail modal. */
  raw: any;
}

/**
 * Admin audit-log viewer — a paginated, filterable window onto the append-only
 * audit_log table (who did what to which entity, when, from where). Read-only:
 * the rows are forensic records. A row's full diff (`changes`) + request
 * metadata open in a detail modal.
 */
@Component({
  selector: 'app-audit-logs',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './audit-logs.component.html',
  styleUrl: './audit-logs.component.css',
})
export class AuditLogsComponent implements OnInit {
  config!: AxDataTableConfig<AuditRow>;
  dataSource!: AxServerDataSource<AuditRow>;
  readonly selected = signal<any | null>(null);

  constructor(private adapter: PortalCrudAdapter) {}

  ngOnInit() {
    this.dataSource = new AxServerDataSource<AuditRow>((q) => this.fetch(q));
    this.config = {
      tableId: 'audit-logs',
      mode: 'server',
      rowId: 'id',
      pageSize: 25,
      pageSizeOptions: [25, 50, 100],
      globalSearch: false,
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No audit entries',
      emptyDescription: 'No recorded actions match these filters.',
      filters: [
        {
          key: 'action', label: 'Action', type: 'select',
          options: [
            { label: 'Created', value: 'created' },
            { label: 'Updated', value: 'updated' },
            { label: 'Deleted', value: 'deleted' },
            { label: 'Viewed', value: 'viewed' },
            { label: 'Overridden', value: 'overridden' },
            { label: 'Default', value: 'default' },
          ],
        },
        { key: 'date', label: 'Date', type: 'date-range' },
      ],
      columns: [
        { key: 'created', label: 'When', sticky: 'left', width: '13rem' },
        { key: 'actor', label: 'Actor' },
        { key: 'action', label: 'Action', align: 'center', width: '8rem' },
        { key: 'subject', label: 'Subject' },
        { key: 'ip', label: 'IP', hideOnMobile: true, width: '10rem' },
      ],
      rowActions: [{ id: 'view', label: 'View details', icon: 'visibility' }],
    };
  }

  private fetch(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.filters['action']) q.action = query.filters['action'];
    const dateRange: any = query.filters['date'];
    if (dateRange?.from) q.date_from = dateRange.from;
    if (dateRange?.to) q.date_to = dateRange.to;

    return this.adapter.get_v3('GET /admin/audit-logs', { query: q }).pipe(
      map((res: any): AxServerFetchResult<AuditRow> => {
        const raw: any[] = Array.isArray(res?.logs)
          ? res.logs
          : Array.isArray(res?.data?.logs)
            ? res.data.logs
            : [];
        const rows = raw.map((l) => this.mapRow(l));
        const total =
          res?.pagination?.total ??
          res?.data?.pagination?.total ??
          rows.length;
        return { rows, total };
      }),
      catchError(() => of({ rows: [], total: 0 } as AxServerFetchResult<AuditRow>)),
    );
  }

  private mapRow(l: any): AuditRow {
    const actor = l.actor
      ? l.actor.name || l.actor.email || `User #${l.actor.id}`
      : 'System';
    return {
      id: l.id,
      created: l.created_at ? new Date(l.created_at).toLocaleString('en-AE') : '',
      actor,
      action: l.action,
      subject: `${l.subject_type} #${l.subject_id}`,
      ip: l.ip_address || '—',
      raw: l,
    };
  }

  onRowAction(e: { action: { id: string }; row: AuditRow }) {
    if (e.action.id === 'view') {
      this.selected.set(e.row['raw']);
    }
  }

  @HostListener('document:keydown.escape')
  closeDetail() {
    this.selected.set(null);
  }

  /** Full name/email of the selected row's actor, or "System". */
  selectedActor(): string {
    const a = this.selected()?.actor;
    if (!a) return 'System';
    return [a.name, a.email].filter(Boolean).join(' · ') || `User #${a.id}`;
  }

  hasChanges(): boolean {
    const c = this.selected()?.changes;
    return c != null && typeof c === 'object' && Object.keys(c).length > 0;
  }

  prettyChanges(): string {
    try {
      return JSON.stringify(this.selected()?.changes ?? {}, null, 2);
    } catch {
      return String(this.selected()?.changes ?? '');
    }
  }

  actionBadgeClass(action: string): string {
    switch (action) {
      case 'created': return 'ax-badge ax-badge-success';
      case 'updated': return 'ax-badge ax-badge-info';
      case 'deleted': return 'ax-badge ax-badge-danger';
      case 'overridden': return 'ax-badge ax-badge-warning';
      case 'viewed': return 'ax-badge ax-badge-neutral';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  actionLabel(action: string): string {
    return (action || '').replace(/\b\w/g, (c) => c.toUpperCase());
  }
}
