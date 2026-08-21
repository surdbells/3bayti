import { Component, HostListener, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { firstValueFrom, of } from 'rxjs';
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
  type AxFilterOption,
} from '../../shared/data/enterprise';

interface AuditRow extends Record<string, unknown> {
  id: number;
  whenRel: string;
  whenAbs: string;
  actorName: string;
  actorEmail: string;
  actorInitials: string;
  isSystem: boolean;
  action: string;
  subjectType: string;
  subjectId: number;
  ip: string;
  raw: any;
}

interface AuditSummary {
  total: number;
  byAction: { action: string; count: number }[];
}

const ACTION_META: Record<string, { label: string; badge: string; dot: string }> = {
  created:    { label: 'Created',    badge: 'ax-badge ax-badge-success', dot: 'al-dot-created' },
  updated:    { label: 'Updated',    badge: 'ax-badge ax-badge-info',    dot: 'al-dot-updated' },
  deleted:    { label: 'Deleted',    badge: 'ax-badge ax-badge-danger',  dot: 'al-dot-deleted' },
  overridden: { label: 'Overridden', badge: 'ax-badge ax-badge-warning', dot: 'al-dot-overridden' },
  viewed:     { label: 'Viewed',     badge: 'ax-badge ax-badge-neutral', dot: 'al-dot-viewed' },
  default:    { label: 'Changed',    badge: 'ax-badge ax-badge-neutral', dot: 'al-dot-default' },
};

/**
 * Admin audit-log console — a filterable, paginated view of the append-only
 * audit_log table (who did what to which entity, when, from where). A summary
 * bar shows the action breakdown for the current filter; each row opens a
 * detail modal with a field-level before/after diff.
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

  readonly summary = signal<AuditSummary | null>(null);
  readonly loadingSummary = signal(true);
  readonly selected = signal<any | null>(null);
  readonly showRaw = signal(false);

  constructor(private adapter: PortalCrudAdapter) {}

  ngOnInit() {
    this.dataSource = new AxServerDataSource<AuditRow>((q) => this.fetch(q));
    this.config = {
      tableId: 'audit-logs',
      mode: 'server',
      rowId: 'id',
      pageSize: 25,
      pageSizeOptions: [25, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search by subject type, ID, IP or request…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No audit entries',
      emptyDescription: 'No recorded actions match these filters.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'audit-log' },
      filters: [
        {
          key: 'action', label: 'Action', type: 'select',
          placeholder: 'Any action',
          options: Object.keys(ACTION_META).map((a) => ({ label: ACTION_META[a].label, value: a })),
        },
        {
          key: 'subject_type', label: 'Subject', type: 'multiselect',
          placeholder: 'Any type',
          optionsLoader: () => this.loadSubjectTypeOptions(),
        },
        { key: 'actor', label: 'Actor', type: 'text', placeholder: 'Name or email' },
        { key: 'date', label: 'Date', type: 'date-range' },
      ],
      columns: [
        { key: 'whenRel', label: 'When', sticky: 'left', width: '11rem' },
        { key: 'actorName', label: 'Actor', width: '16rem' },
        { key: 'action', label: 'Action', align: 'center', width: '9rem' },
        { key: 'subjectType', label: 'Subject' },
        { key: 'ip', label: 'IP address', hideOnMobile: true, width: '11rem' },
      ],
      rowActions: [{ id: 'view', label: 'View details', icon: 'visibility' }],
    };
  }

  private async loadSubjectTypeOptions(): Promise<AxFilterOption[]> {
    try {
      const res: any = await firstValueFrom(
        this.adapter.get_v3('GET /admin/audit-logs', { query: { limit: 1 } }),
      );
      const types: string[] = res?.facets?.subject_types ?? res?.data?.facets?.subject_types ?? [];
      return types.map((t) => ({ label: t, value: t }));
    } catch {
      return [];
    }
  }

  private fetch(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    if (query.filters['action']) q.action = query.filters['action'];
    if (query.filters['actor']) q.actor = query.filters['actor'];
    const types = query.filters['subject_type'];
    if (Array.isArray(types) && types.length) q.subject_type = types.join(',');
    const dateRange: any = query.filters['date'];
    if (dateRange?.from) q.date_from = dateRange.from;
    if (dateRange?.to) q.date_to = dateRange.to;

    this.loadingSummary.set(true);
    return this.adapter.get_v3('GET /admin/audit-logs', { query: q }).pipe(
      map((res: any): AxServerFetchResult<AuditRow> => {
        const body = res?.logs ? res : res?.data ?? res;
        const raw: any[] = Array.isArray(body?.logs) ? body.logs : [];
        const rows = raw.map((l) => this.mapRow(l));
        const total = body?.pagination?.total ?? rows.length;
        this.applySummary(body?.summary);
        return { rows, total };
      }),
      catchError(() => {
        this.applySummary(null);
        return of({ rows: [], total: 0 } as AxServerFetchResult<AuditRow>);
      }),
    );
  }

  private applySummary(summary: any) {
    this.loadingSummary.set(false);
    if (!summary) { this.summary.set(null); return; }
    const byAction = Object.entries(summary.by_action ?? {})
      .map(([action, count]) => ({ action, count: Number(count) }))
      .sort((a, b) => b.count - a.count);
    this.summary.set({ total: Number(summary.total ?? 0), byAction });
  }

  private mapRow(l: any): AuditRow {
    const name = l.actor ? (l.actor.name || l.actor.email || `User #${l.actor.id}`) : 'System';
    return {
      id: l.id,
      whenRel: this.relativeTime(l.created_at),
      whenAbs: l.created_at ? new Date(l.created_at).toLocaleString('en-AE') : '',
      actorName: name,
      actorEmail: l.actor?.email ?? '',
      actorInitials: this.initials(name),
      isSystem: !l.actor,
      action: l.action,
      subjectType: l.subject_type,
      subjectId: l.subject_id,
      ip: l.ip_address || '—',
      raw: l,
    };
  }

  onRowAction(e: { action: { id: string }; row: AuditRow }) {
    if (e.action.id === 'view') {
      this.showRaw.set(false);
      this.selected.set(e.row['raw']);
    }
  }

  @HostListener('document:keydown.escape')
  closeDetail() {
    this.selected.set(null);
  }

  // ── Presentation helpers ───────────────────────────────────────────
  actionMeta(action: string) {
    return ACTION_META[action] ?? ACTION_META['default'];
  }

  initials(name: string): string {
    const parts = (name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    return (parts[0][0] + (parts[1]?.[0] ?? '')).toUpperCase();
  }

  relativeTime(iso: string): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const s = Math.max(0, Math.round((Date.now() - then) / 1000));
    if (s < 45) return 'just now';
    const m = Math.round(s / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h}h ago`;
    const d = Math.round(h / 24);
    if (d < 30) return `${d}d ago`;
    const mo = Math.round(d / 30);
    if (mo < 12) return `${mo}mo ago`;
    return `${Math.round(mo / 12)}y ago`;
  }

  // ── Detail modal: change rendering ─────────────────────────────────
  changeShape(): 'diff' | 'created' | 'deleted' | 'flat' | 'empty' {
    const c = this.selected()?.changes;
    if (!c || typeof c !== 'object') return 'empty';
    const hasBefore = c.before && typeof c.before === 'object' && Object.keys(c.before).length > 0;
    const hasAfter = c.after && typeof c.after === 'object' && Object.keys(c.after).length > 0;
    if (hasBefore && hasAfter) return 'diff';
    if (hasAfter) return 'created';
    if (hasBefore) return 'deleted';
    return Object.keys(c).length > 0 ? 'flat' : 'empty';
  }

  diffRows(): { field: string; before: string; after: string }[] {
    const c = this.selected()?.changes ?? {};
    const before = c.before ?? {};
    const after = c.after ?? {};
    const keys = Array.from(new Set([...Object.keys(before), ...Object.keys(after)])).sort();
    return keys.map((k) => ({
      field: this.humanizeKey(k),
      before: this.formatValue(before[k]),
      after: this.formatValue(after[k]),
    }));
  }

  kvRows(source: 'after' | 'before' | 'root'): { field: string; value: string }[] {
    const c = this.selected()?.changes ?? {};
    const obj = source === 'root' ? c : (c[source] ?? {});
    return Object.entries(obj)
      .filter(([k]) => k !== 'before' && k !== 'after')
      .map(([k, v]) => ({ field: this.humanizeKey(k), value: this.formatValue(v) }));
  }

  prettyRaw(): string {
    try {
      return JSON.stringify(this.selected()?.changes ?? {}, null, 2);
    } catch {
      return String(this.selected()?.changes ?? '');
    }
  }

  private humanizeKey(k: string): string {
    return k
      .replace(/([a-z0-9])([A-Z])/g, '$1 $2') // split camelCase (contactEmail → contact Email)
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/\b\w/g, (c) => c.toUpperCase());
  }

  private formatValue(v: any): string {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'boolean') return v ? 'true' : 'false';
    if (typeof v === 'object') { try { return JSON.stringify(v); } catch { return String(v); } }
    return String(v);
  }

  selectedActor(): string {
    const a = this.selected()?.actor;
    if (!a) return 'System';
    return [a.name, a.email].filter(Boolean).join(' · ') || `User #${a.id}`;
  }
}
