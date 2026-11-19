import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { PermissionService } from '../../services/permission.service';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';
import {
  type CashbackRule,
  type CashbackSettings,
  budgetBarClass,
  budgetPercent,
  formatMoney,
  parseRule,
  parseSettings,
  rewardLabel,
  ruleStatusBadge,
  serverFieldErrors,
  unwrap,
  validateSettings,
} from './cashback-ui';

type RuleRow = CashbackRule & Record<string, unknown>;

interface SummaryTotals {
  pending: { count: number; amount: string };
  issued: { count: number; amount: string };
  reversed: { count: number; amount: string };
  cancelled: { count: number; amount: string };
  redeemed: string;
  outstanding: string;
}

/**
 * Admin "Cashback": the programme master switch + settings, headline totals
 * and the rules table (inline activate/deactivate, budget-used bars).
 *
 * Backed by GET/PUT /admin/cashback/settings, GET /admin/cashback/summary
 * and GET /admin/cashback/rules (+ activate / deactivate / DELETE).
 * Gated by cashback.view; edits need cashback.edit / create / delete.
 */
@Component({
  selector: 'app-cashback-rules',
  standalone: true,
  imports: [
    AdminShellComponent, CommonModule, FormsModule, RouterLink,
    AxDataTableComponent, AxCellDirective, IconComponent, AxCanDirective, TranslatePipe,
  ],
  templateUrl: './cashback-rules.component.html',
  styleUrl: './cashback-rules.component.css',
})
export class CashbackRulesComponent implements OnInit {
  private readonly router = inject(Router);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly confirm = inject(AxConfirmService);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly i18n = inject(I18nService);
  readonly perms = inject(PermissionService);

  config?: AxDataTableConfig<RuleRow>;
  dataSource!: AxServerDataSource<RuleRow>;

  settings: CashbackSettings | null = null;
  settingsForm = { min_award: '1.00', max_award_per_order: '', default_hold_days: 14 as number | null, default_validity_days: 365 as number | null };
  settingsErrors: Record<string, string> = {};
  settingsLoading = true;
  settingsSaving = false;
  toggling = false;

  summary: SummaryTotals | null = null;

  /** Rule ids with an in-flight activate/deactivate (disables that switch). */
  readonly busyRules = new Set<number>();

  readonly rewardLabel = rewardLabel;
  readonly budgetPercent = budgetPercent;
  readonly budgetBarClass = budgetBarClass;
  readonly ruleStatusBadge = ruleStatusBadge;
  readonly formatMoney = formatMoney;

  ngOnInit(): void {
    this.loadSettings();
    this.loadSummary();
    this.buildTable();
  }

  t(key: string, params?: Record<string, unknown>): string {
    return this.i18n.t(key, params);
  }

  // ── Programme settings ────────────────────────────────────────────────

  private loadSettings(): void {
    this.settingsLoading = true;
    this.adapter.get_v3('GET /admin/cashback/settings').subscribe({
      next: (res: any) => {
        this.applySettings(parseSettings(unwrap(res)));
        this.settingsLoading = false;
      },
      error: (err: any) => {
        this.settingsLoading = false;
        this.toast.error(apiErrorMessage(err, this.t('cashback.settings.load_error')));
      },
    });
  }

  private applySettings(s: CashbackSettings): void {
    this.settings = s;
    this.settingsForm = {
      min_award: s.min_award,
      max_award_per_order: s.max_award_per_order ?? '',
      default_hold_days: s.default_hold_days,
      default_validity_days: s.default_validity_days,
    };
    this.settingsErrors = {};
  }

  toggleProgramme(): void {
    if (!this.settings || this.toggling || !this.perms.can('cashback.edit')) return;
    const enable = !this.settings.enabled;
    this.confirm
      .confirm({
        title: this.t(enable ? 'cashback.program.enable_title' : 'cashback.program.disable_title'),
        message: this.t(enable ? 'cashback.program.enable_msg' : 'cashback.program.disable_msg'),
        confirmLabel: this.t(enable ? 'cashback.program.enable' : 'cashback.program.disable'),
        cancelLabel: this.t('cashback.cancel'),
        variant: enable ? 'default' : 'danger',
      })
      .then((ok) => {
        if (!ok) return;
        this.toggling = true;
        this.adapter.put_v3('PUT /admin/cashback/settings', { enabled: enable }).subscribe({
          next: (res: any) => {
            this.toggling = false;
            const s = parseSettings(unwrap(res));
            this.settings = { ...(this.settings as CashbackSettings), enabled: s.enabled };
            this.toast.success(this.t(s.enabled ? 'cashback.program.enabled_toast' : 'cashback.program.disabled_toast'));
          },
          error: (err: any) => {
            this.toggling = false;
            this.toast.error(apiErrorMessage(err, this.t('cashback.settings.save_error')));
          },
        });
      });
  }

  saveSettings(): void {
    if (this.settingsSaving || !this.perms.can('cashback.edit')) return;
    this.settingsErrors = validateSettings(this.settingsForm);
    if (Object.keys(this.settingsErrors).length) return;

    const f = this.settingsForm;
    const max = String(f.max_award_per_order ?? '').trim();
    const body = {
      min_award: Number(f.min_award).toFixed(2),
      max_award_per_order: max === '' ? null : Number(max).toFixed(2),
      default_hold_days: Number(f.default_hold_days),
      default_validity_days: Number(f.default_validity_days),
    };
    this.settingsSaving = true;
    this.adapter.put_v3('PUT /admin/cashback/settings', body).subscribe({
      next: (res: any) => {
        this.settingsSaving = false;
        this.applySettings(parseSettings(unwrap(res)));
        this.toast.success(this.t('cashback.settings.saved'));
      },
      error: (err: any) => {
        this.settingsSaving = false;
        const fields = serverFieldErrors(err);
        if (Object.keys(fields).length) this.settingsErrors = fields;
        this.toast.error(apiErrorMessage(err, this.t('cashback.settings.save_error')));
      },
    });
  }

  /** A settings field error is either an i18n key (client) or a server string. */
  errText(msg: string | undefined): string {
    if (!msg) return '';
    return msg.startsWith('cashback.') ? this.t(msg) : msg;
  }

  // ── Summary ───────────────────────────────────────────────────────────

  private loadSummary(): void {
    this.adapter.get_v3('GET /admin/cashback/summary').subscribe({
      next: (res: any) => {
        const d = unwrap<any>(res) ?? {};
        const tot = (k: string) => ({
          count: Number(d?.totals?.[k]?.count ?? 0),
          amount: String(d?.totals?.[k]?.amount ?? '0.00'),
        });
        this.summary = {
          pending: tot('pending'),
          issued: tot('issued'),
          reversed: tot('reversed'),
          cancelled: tot('cancelled'),
          redeemed: String(d?.gift_cards?.redeemed ?? '0.00'),
          outstanding: String(d?.gift_cards?.outstanding ?? '0.00'),
        };
      },
      // The summary is decorative; the rules table still works without it.
      error: () => { this.summary = null; },
    });
  }

  // ── Rules table ───────────────────────────────────────────────────────

  private buildTable(): void {
    this.dataSource = new AxServerDataSource<RuleRow>((q) => this.fetch(q));
    this.config = {
      tableId: 'admin-cashback-rules',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: this.t('cashback.rules.search'),
      stickyHeader: true,
      hover: true,
      emptyTitle: this.t('cashback.rules.empty_title'),
      emptyDescription: this.t('cashback.rules.empty_desc'),
      filters: [
        {
          key: 'status',
          label: this.t('cashback.rules.col_status'),
          type: 'select',
          options: (['active', 'inactive', 'scheduled', 'expired'] as const).map((s) => ({
            label: this.t(`cashback.rule_status.${s}`), value: s,
          })),
        },
        {
          key: 'scope',
          label: this.t('cashback.rules.col_scope'),
          type: 'select',
          options: (['all', 'stores', 'products', 'categories'] as const).map((s) => ({
            label: this.t(`cashback.scope.${s}`), value: s,
          })),
        },
      ],
      columns: [
        { key: 'name', label: this.t('cashback.rules.col_name'), sticky: 'left', width: '15rem' },
        { key: 'reward', label: this.t('cashback.rules.col_reward'), value: (r) => rewardLabel(r) },
        { key: 'scope', label: this.t('cashback.rules.col_scope'), value: (r) => this.t(`cashback.scope.${r.scope}`) },
        { key: 'status', label: this.t('cashback.rules.col_status'), align: 'center' },
        { key: 'is_active', label: this.t('cashback.rules.col_active'), align: 'center' },
        { key: 'budget', label: this.t('cashback.rules.col_budget'), width: '12rem',
          value: (r) => (r.budget_total ? `${r.budget_spent} / ${r.budget_total}` : r.budget_spent) },
        { key: 'awards_count', label: this.t('cashback.rules.col_awards'), align: 'center', hideOnMobile: true },
        { key: 'priority', label: this.t('cashback.rules.col_priority'), align: 'center', hideOnMobile: true },
        { key: 'valid_until', label: this.t('cashback.rules.col_ends'), hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : this.t('cashback.never')) },
      ],
      rowActions: [
        { id: 'edit', label: this.t('cashback.rules.action_edit'), icon: 'edit', can: () => this.perms.can('cashback.edit') },
        { id: 'awards', label: this.t('cashback.rules.action_awards'), icon: 'receipt_long' },
        { id: 'delete', label: this.t('cashback.rules.action_delete'), icon: 'delete', variant: 'danger',
          can: () => this.perms.can('cashback.delete') },
      ],
    };
  }

  private fetch(query: AxQueryState) {
    const q: Record<string, string | number> = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q['search'] = query.search;
    if (query.filters['status']) q['status'] = String(query.filters['status']);
    if (query.filters['scope']) q['scope'] = String(query.filters['scope']);

    return this.adapter.get_v3('GET /admin/cashback/rules', { query: q }).pipe(
      map((response: any): AxServerFetchResult<RuleRow> => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : [];
        const rows = raw.map((r) => parseRule(r) as RuleRow);
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, this.t('cashback.rules.load_error')));
        return of({ rows: [], total: 0 } as AxServerFetchResult<RuleRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: RuleRow }): void {
    const { action, row } = e;
    if (action.id === 'edit') {
      this.router.navigate(['/admin/cashback/rules/form'], { queryParams: { id: row.id } });
    } else if (action.id === 'awards') {
      this.router.navigate(['/admin/cashback/awards'], { queryParams: { rule_id: row.id } });
    } else if (action.id === 'delete') {
      this.remove(row);
    }
  }

  /** Inline switch: POST /activate or /deactivate, then patch the row. */
  toggleRule(row: RuleRow, ev?: Event): void {
    ev?.stopPropagation();
    if (!this.perms.can('cashback.edit') || this.busyRules.has(row.id)) {
      if (ev?.target instanceof HTMLInputElement) ev.target.checked = row.is_active;
      return;
    }
    const activate = !row.is_active;
    this.busyRules.add(row.id);
    const key = activate ? 'POST /admin/cashback/rules/:id/activate' : 'POST /admin/cashback/rules/:id/deactivate';
    this.adapter.post_v3(key, {}, { params: { id: String(row.id) } }).subscribe({
      next: (res: any) => {
        this.busyRules.delete(row.id);
        const updated = parseRule(unwrap(res));
        row.is_active = updated.is_active;
        row.status = updated.status;
        this.toast.success(this.t(activate ? 'cashback.rules.activated' : 'cashback.rules.deactivated', { name: row.name }));
      },
      error: (err: any) => {
        this.busyRules.delete(row.id);
        if (ev?.target instanceof HTMLInputElement) ev.target.checked = row.is_active;
        this.toast.error(apiErrorMessage(err, this.t('cashback.rules.toggle_error')));
      },
    });
  }

  private remove(row: RuleRow): void {
    this.confirm
      .confirm({
        title: this.t('cashback.rules.delete_title'),
        message: this.t('cashback.rules.delete_msg', { name: row.name }),
        confirmLabel: this.t('cashback.rules.action_delete'),
        cancelLabel: this.t('cashback.cancel'),
        variant: 'danger',
      })
      .then((ok) => {
        if (!ok) return;
        this.adapter.delete_v3('DELETE /admin/cashback/rules/:id', { params: { id: String(row.id) } }).subscribe({
          next: () => {
            this.toast.success(this.t('cashback.rules.deleted'));
            this.dataSource?.retry();
            this.loadSummary();
          },
          error: (err: any) => this.toast.error(apiErrorMessage(err, this.t('cashback.rules.delete_error'))),
        });
      });
  }

  createRule(): void {
    this.router.navigate(['/admin/cashback/rules/form']);
  }

  goBack(): void {
    this.navHistory.back('/backend');
  }
}
