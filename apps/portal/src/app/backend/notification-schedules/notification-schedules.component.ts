import { Component, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
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

interface ScheduleRow extends Record<string, unknown> {
  id: number;
  name: string | null;
  title: string;
  audience_label: string;
  frequency: string;
  next_run_at: string | null;
  last_run_at: string | null;
  status: string;
  editable: boolean;
}

interface VariableDef { key: string; label: string; sample: string; }
interface TemplateOpt { id: number; name: string; title: string; body: string; image_url: string | null; deep_link: string | null; }

/** Notification → Scheduled. Create scheduled/recurring notifications; the
 *  `notifications:dispatch-scheduled` cron emits each due occurrence. */
@Component({
  selector: 'app-notification-schedules',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './notification-schedules.component.html',
  styleUrl: './notification-schedules.component.css',
})
export class NotificationSchedulesComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  config!: AxDataTableConfig<ScheduleRow>;
  dataSource!: AxServerDataSource<ScheduleRow>;

  variables: VariableDef[] = [];
  templates: TemplateOpt[] = [];

  readonly frequencies = [
    { value: 'once', label: 'Once' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
  ];
  readonly timezones = ['Asia/Dubai', 'Asia/Riyadh', 'Asia/Kuwait', 'Asia/Qatar', 'Asia/Bahrain', 'Asia/Muscat', 'UTC'];
  readonly audiences = [
    { value: 'all', label: 'Everyone' },
    { value: 'customers', label: 'Customers' },
    { value: 'vendors', label: 'Vendors' },
    { value: 'admins', label: 'Admins' },
  ];

  // Editor state
  editing = false;
  saving = false;
  editId: number | null = null;
  form = {
    name: '', template_id: '' as string | number, title: '', body: '',
    image_url: '', deep_link: '', audience: 'all', frequency: 'once',
    start_at: '', end_at: '', timezone: 'Asia/Dubai',
  };
  audienceCount: { total: number; android: number; ios: number } | null = null;
  private lastField: 'title' | 'body' = 'body';

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.dataSource = new AxServerDataSource<ScheduleRow>((q) => this.fetch(q), 120);
    this.config = {
      tableId: 'admin-notification-schedules',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search schedules…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No scheduled notifications',
      emptyDescription: 'Schedule a one-off or recurring notification.',
      filters: [
        { key: 'status', label: 'Status', type: 'select', options: [
          { label: 'Scheduled', value: 'scheduled' }, { label: 'Draft', value: 'draft' },
          { label: 'Completed', value: 'completed' }, { label: 'Cancelled', value: 'cancelled' },
        ] },
      ],
      columns: [
        { key: 'name', label: 'Notification', sticky: 'left', width: '15rem', value: (r) => r.name || r.title },
        { key: 'audience_label', label: 'Audience', align: 'center', hideOnMobile: true },
        { key: 'frequency', label: 'Frequency', align: 'center', value: (r) => this.freqLabel(r.frequency) },
        { key: 'next_run_at', label: 'Next run',
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'last_run_at', label: 'Last run', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [
        { id: 'edit', label: 'Edit', icon: 'edit', hidden: (r) => !r.editable },
        { id: 'occurrences', label: 'View occurrences', icon: 'list' },
        { id: 'run-now', label: 'Send now', icon: 'send', hidden: (r) => r.status === 'cancelled' },
        { id: 'cancel', label: 'Cancel', icon: 'block', variant: 'danger',
          hidden: (r) => r.status === 'completed' || r.status === 'cancelled' },
      ],
    };
    this.loadVariables();
    this.loadTemplates();
  }

  private loadVariables() {
    this.adapter.get_v3('GET /admin/notification-templates/variables').subscribe({
      next: (res: any) => { this.variables = Array.isArray(res?.data) ? res.data : []; },
      error: () => { this.variables = []; },
    });
  }

  private loadTemplates() {
    this.adapter.get_v3('GET /admin/notification-templates', { query: { status: 'active', limit: 200 } }).subscribe({
      next: (res: any) => { this.templates = Array.isArray(res?.data) ? res.data : []; },
      error: () => { this.templates = []; },
    });
  }

  private fetch(query: AxQueryState) {
    const q: any = { limit: query.pageSize, offset: query.pageIndex * query.pageSize };
    if (query.search) q.search = query.search;
    if (query.filters?.['status']) q.status = query.filters['status'];
    return this.adapter.get_v3('GET /admin/notification-schedules', { query: q }).pipe(
      map((res: any): AxServerFetchResult<ScheduleRow> => {
        const rows: ScheduleRow[] = Array.isArray(res?.data) ? res.data : [];
        return { rows, total: res?.meta?.total ?? rows.length };
      }),
      catchError(() => { this.toast.error('Unable to load schedules.'); return of({ rows: [], total: 0 } as AxServerFetchResult<ScheduleRow>); }),
    );
  }

  // ── Editor ──────────────────────────────────────────────────────────
  newSchedule() {
    this.editId = null;
    this.form = {
      name: '', template_id: '', title: '', body: '', image_url: '', deep_link: '',
      audience: 'all', frequency: 'once', start_at: this.defaultStart(), end_at: '', timezone: 'Asia/Dubai',
    };
    this.editing = true;
    this.refreshAudience();
  }

  edit(row: ScheduleRow) {
    this.adapter.get_v3('GET /admin/notification-schedules/:id', { params: { id: String(row.id) } }).subscribe({
      next: (res: any) => {
        const s = res?.data ?? {};
        this.editId = row.id;
        this.form = {
          name: s.name ?? '', template_id: s.template_id ?? '', title: s.title ?? '', body: s.body ?? '',
          image_url: s.image_url ?? '', deep_link: s.deep_link ?? '', audience: s.audience?.type ?? 'all',
          frequency: s.frequency ?? 'once', start_at: this.toLocalInput(s.start_at), end_at: this.toLocalInput(s.end_at),
          timezone: s.timezone ?? 'Asia/Dubai',
        };
        this.editing = true;
        this.refreshAudience();
      },
      error: () => this.toast.error('Unable to load schedule.'),
    });
  }

  cancelEdit() { this.editing = false; }

  onTemplateChange() {
    const t = this.templates.find((x) => String(x.id) === String(this.form.template_id));
    if (t) {
      this.form.title = t.title;
      this.form.body = t.body;
      this.form.image_url = t.image_url ?? '';
      this.form.deep_link = t.deep_link ?? '';
    }
  }

  refreshAudience() {
    this.audienceCount = null;
    this.adapter.get_v3('GET /admin/notifications/audience-preview', { query: { audience: this.form.audience } }).subscribe({
      next: (res: any) => { this.audienceCount = res?.data ?? null; },
      error: () => { this.audienceCount = null; },
    });
  }

  rememberField(f: 'title' | 'body') { this.lastField = f; }

  insertVariable(key: string) {
    const token = `{{${key}}}`;
    const el = document.getElementById(this.lastField === 'title' ? 'sch-title' : 'sch-body') as HTMLInputElement | HTMLTextAreaElement | null;
    if (el && typeof el.selectionStart === 'number') {
      const start = el.selectionStart ?? 0;
      const end = el.selectionEnd ?? start;
      const cur = this.form[this.lastField];
      this.form[this.lastField] = cur.slice(0, start) + token + cur.slice(end);
      setTimeout(() => { el.focus(); const pos = start + token.length; el.setSelectionRange(pos, pos); }, 0);
    } else {
      this.form[this.lastField] = (this.form[this.lastField] || '') + token;
    }
  }

  resolvePreview(text: string): string {
    return (text || '').replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, (_m, k) => {
      const v = this.variables.find((x) => x.key === String(k).toLowerCase());
      return v ? v.sample : '';
    });
  }

  freqLabel(f: string): string { return this.frequencies.find((x) => x.value === f)?.label ?? f; }

  save() {
    if (!this.form.title.trim() || !this.form.body.trim()) {
      this.toast.error('Title and message are required.');
      return;
    }
    if (!this.form.start_at) {
      this.toast.error('Pick a start date and time.');
      return;
    }
    if (this.form.end_at && this.form.end_at <= this.form.start_at) {
      this.toast.error('End must be after the start.');
      return;
    }
    this.saving = true;
    const body = {
      name: this.form.name.trim() || null,
      template_id: this.form.template_id ? Number(this.form.template_id) : null,
      title: this.form.title.trim(),
      body: this.form.body.trim(),
      image_url: this.form.image_url.trim() || null,
      deep_link: this.form.deep_link.trim() || null,
      audience: this.form.audience,
      frequency: this.form.frequency,
      timezone: this.form.timezone,
      start_at: this.form.start_at,
      end_at: this.form.end_at || null,
      status: 'scheduled',
    };
    const req = this.editId
      ? this.adapter.put_v3('PUT /admin/notification-schedules/:id', body, { params: { id: String(this.editId) } })
      : this.adapter.post_v3('POST /admin/notification-schedules', body);
    req.subscribe({
      next: () => { this.saving = false; this.editing = false; this.toast.success('Schedule saved.'); this.dataSource.retry(); },
      error: (err) => { this.saving = false; this.toast.error(err?.error?.error?.message ?? 'Unable to save schedule.'); },
    });
  }

  // ── Row actions ─────────────────────────────────────────────────────
  onRowAction(e: { action: { id: string }; row: ScheduleRow }) {
    switch (e.action.id) {
      case 'edit': return this.edit(e.row);
      case 'occurrences': return this.viewOccurrences(e.row);
      case 'run-now': return this.runNow(e.row);
      case 'cancel': return this.cancel(e.row);
    }
  }

  private viewOccurrences(row: ScheduleRow) {
    this.router.navigate(['/admin/notification-broadcasts'], { queryParams: { schedule_id: row.id } });
  }

  private runNow(row: ScheduleRow) {
    this.confirm.confirm({
      title: 'Send now', message: `Queue an occurrence of "${row.name || row.title}" to send now?`,
      confirmLabel: 'Send now', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (!ok) return;
      this.adapter.post_v3('POST /admin/notification-schedules/:id/run-now', {}, { params: { id: String(row.id) } })
        .subscribe({ next: () => { this.toast.success('Occurrence queued — it will send shortly.'); this.dataSource.retry(); },
                     error: (err) => this.toast.error(err?.error?.error?.message ?? 'Unable to send.') });
    });
  }

  private cancel(row: ScheduleRow) {
    this.confirm.confirm({
      title: 'Cancel schedule', message: `Stop future runs of "${row.name || row.title}"? Past occurrences are kept.`,
      confirmLabel: 'Cancel schedule', cancelLabel: 'Keep', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.adapter.post_v3('POST /admin/notification-schedules/:id/cancel', {}, { params: { id: String(row.id) } })
        .subscribe({ next: () => { this.toast.success('Schedule cancelled.'); this.dataSource.retry(); },
                     error: () => this.toast.error('Unable to cancel.') });
    });
  }

  statusBadge(status: string): string {
    switch (status) {
      case 'scheduled': return 'ax-badge ax-badge-info';
      case 'completed': return 'ax-badge ax-badge-success';
      case 'processing': return 'ax-badge ax-badge-warning';
      case 'cancelled': return 'ax-badge ax-badge-danger';
      default: return 'ax-badge ax-badge-neutral';
    }
  }

  // ── datetime-local helpers ──────────────────────────────────────────
  /** Default start = now + 1 hour, as a datetime-local string. */
  private defaultStart(): string {
    const d = new Date(Date.now() + 60 * 60 * 1000);
    return this.toLocalInput(d.toISOString());
  }

  /** ISO/date string → 'YYYY-MM-DDTHH:mm' for <input type="datetime-local">. */
  private toLocalInput(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }
}
