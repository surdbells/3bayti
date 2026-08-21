import { Component, OnInit, inject } from '@angular/core';
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
import { apiErrorMessage } from '../../shared/http/api-error';

interface TemplateRow extends Record<string, unknown> {
  id: number;
  name: string;
  title: string;
  body: string;
  image_url: string | null;
  deep_link: string | null;
  status: string;
  created_by_name: string | null;
  updated_at: string;
}

interface VariableDef { key: string; label: string; sample: string; }

/** Notification → Templates. Reusable message definitions with {{variables}};
 *  CRUD + duplicate + activate/deactivate. */
@Component({
  selector: 'app-notification-templates',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './notification-templates.component.html',
  styleUrl: './notification-templates.component.css',
})
export class NotificationTemplatesComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  config!: AxDataTableConfig<TemplateRow>;
  dataSource!: AxServerDataSource<TemplateRow>;

  variables: VariableDef[] = [];

  // Inline editor state
  editing = false;
  saving = false;
  editId: number | null = null;
  form = { name: '', title: '', body: '', image_url: '', deep_link: '' };
  private lastField: 'title' | 'body' = 'body';

  constructor(
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.dataSource = new AxServerDataSource<TemplateRow>((q) => this.fetch(q), 120);
    this.config = {
      tableId: 'admin-notification-templates',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search templates…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No templates',
      emptyDescription: 'Create a reusable message template to compose from.',
      filters: [
        { key: 'status', label: 'Status', type: 'select', options: [
          { label: 'Active', value: 'active' }, { label: 'Inactive', value: 'inactive' },
        ] },
      ],
      columns: [
        { key: 'name', label: 'Name', sticky: 'left', width: '14rem' },
        { key: 'title', label: 'Title', hideOnMobile: true },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'created_by_name', label: 'Created by', hideOnMobile: true, value: (r) => r.created_by_name || '—' },
        { key: 'updated_at', label: 'Updated', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
      ],
      rowActions: [
        { id: 'edit', label: 'Edit', icon: 'edit' },
        { id: 'duplicate', label: 'Duplicate', icon: 'copy' },
        { id: 'activate', label: 'Activate', icon: 'check', hidden: (r) => r.status === 'active' },
        { id: 'deactivate', label: 'Deactivate', icon: 'block', hidden: (r) => r.status !== 'active' },
        { id: 'delete', label: 'Delete', icon: 'delete', variant: 'danger' },
      ],
    };
    this.loadVariables();
  }

  private loadVariables() {
    this.adapter.get_v3('GET /admin/notification-templates/variables').subscribe({
      next: (res: any) => { this.variables = Array.isArray(res?.data) ? res.data : []; },
      error: () => { this.variables = []; },
    });
  }

  private fetch(query: AxQueryState) {
    const q: any = { limit: query.pageSize, offset: query.pageIndex * query.pageSize };
    if (query.search) q.search = query.search;
    if (query.filters?.['status']) q.status = query.filters['status'];
    return this.adapter.get_v3('GET /admin/notification-templates', { query: q }).pipe(
      map((res: any): AxServerFetchResult<TemplateRow> => {
        const rows: TemplateRow[] = Array.isArray(res?.data) ? res.data : [];
        return { rows, total: res?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to load templates.')); return of({ rows: [], total: 0 } as AxServerFetchResult<TemplateRow>); }),
    );
  }

  // ── Editor ──────────────────────────────────────────────────────────
  newTemplate() {
    this.editId = null;
    this.form = { name: '', title: '', body: '', image_url: '', deep_link: '' };
    this.editing = true;
  }

  edit(row: TemplateRow) {
    this.editId = row.id;
    this.form = {
      name: row.name, title: row.title, body: row.body,
      image_url: row.image_url ?? '', deep_link: row.deep_link ?? '',
    };
    this.editing = true;
  }

  cancelEdit() { this.editing = false; }

  rememberField(f: 'title' | 'body') { this.lastField = f; }

  insertVariable(key: string) {
    const token = `{{${key}}}`;
    const el = document.getElementById(this.lastField === 'title' ? 'tpl-title' : 'tpl-body') as HTMLInputElement | HTMLTextAreaElement | null;
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

  /** Preview with sample values substituted. */
  resolvePreview(text: string): string {
    return (text || '').replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, (_m, k) => {
      const v = this.variables.find((x) => x.key === String(k).toLowerCase());
      return v ? v.sample : '';
    });
  }

  save() {
    if (!this.form.name.trim() || !this.form.title.trim() || !this.form.body.trim()) {
      this.toast.error('Name, title and message are all required.');
      return;
    }
    this.saving = true;
    const body = {
      name: this.form.name.trim(), title: this.form.title.trim(), body: this.form.body.trim(),
      image_url: this.form.image_url.trim() || null, deep_link: this.form.deep_link.trim() || null,
    };
    const req = this.editId
      ? this.adapter.put_v3('PUT /admin/notification-templates/:id', body, { params: { id: String(this.editId) } })
      : this.adapter.post_v3('POST /admin/notification-templates', body);
    req.subscribe({
      next: () => { this.saving = false; this.editing = false; this.toast.success('Template saved.'); this.dataSource.retry(); },
      error: (err) => { this.saving = false; this.toast.error(err?.error?.error?.message ?? 'Unable to save template.'); },
    });
  }

  // ── Row actions ─────────────────────────────────────────────────────
  onRowAction(e: { action: { id: string }; row: TemplateRow }) {
    switch (e.action.id) {
      case 'edit': return this.edit(e.row);
      case 'duplicate': return this.duplicate(e.row);
      case 'activate': return this.setStatus(e.row, 'active');
      case 'deactivate': return this.setStatus(e.row, 'inactive');
      case 'delete': return this.remove(e.row);
    }
  }

  private duplicate(row: TemplateRow) {
    this.adapter.post_v3('POST /admin/notification-templates/:id/duplicate', {}, { params: { id: String(row.id) } })
      .subscribe({ next: () => { this.toast.success('Template duplicated.'); this.dataSource.retry(); },
                   error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to duplicate.')) });
  }

  private setStatus(row: TemplateRow, status: 'active' | 'inactive') {
    this.adapter.patch_v3('PATCH /admin/notification-templates/:id/status', { status }, { params: { id: String(row.id) } })
      .subscribe({ next: () => { this.toast.success(`Template ${status === 'active' ? 'activated' : 'deactivated'}.`); this.dataSource.retry(); },
                   error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to update status.')) });
  }

  private remove(row: TemplateRow) {
    this.confirm.confirm({
      title: 'Delete template', message: `Delete "${row.name}"? Past broadcasts keep their message.`,
      confirmLabel: 'Delete', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.adapter.delete_v3('DELETE /admin/notification-templates/:id', { params: { id: String(row.id) } })
        .subscribe({ next: () => { this.toast.success('Template deleted.'); this.dataSource.retry(); },
                     error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to delete.')) });
    });
  }

  statusBadge(status: string): string {
    return status === 'active' ? 'ax-badge ax-badge-success' : 'ax-badge ax-badge-neutral';
  }
}
