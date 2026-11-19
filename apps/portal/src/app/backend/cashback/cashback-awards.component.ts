import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom, of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { PermissionService } from '../../services/permission.service';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxDateRange,
  type AxFilterOption,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';
import {
  type CashbackAward,
  type CashbackAwardStatus,
  CASHBACK_AWARD_STATUSES,
  awardRecoveries,
  awardStatusBadge,
  fmtDate,
  formatMoney,
  parseAward,
  unwrap,
} from './cashback-ui';

type AwardRow = CashbackAward & Record<string, unknown>;
type AwardAction = 'release' | 'cancel' | 'reverse';

/**
 * Admin cashback award ledger: one row per order that accrued cashback.
 * Filters (status, rule, customer, order ref, created date), a detail
 * dialog with the per-line breakdown, and the manual actions:
 *   release  (pending → issue the gift card now)
 *   cancel   (pending → cancelled)
 *   reverse  (issued  → claw back, full or partial)
 * each with a required reason (cashback.manage).
 *
 * Deep links: ?status=, ?rule_id= pre-filter; ?award=<id> opens that
 * award's detail (used by the gift-card admin "Cashback" tag).
 */
@Component({
  selector: 'app-cashback-awards',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, RouterLink, AxDataTableComponent, AxCellDirective, IconComponent, TranslatePipe],
  templateUrl: './cashback-awards.component.html',
  styleUrl: './cashback-awards.component.css',
})
export class CashbackAwardsComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly i18n = inject(I18nService);
  readonly perms = inject(PermissionService);

  config?: AxDataTableConfig<AwardRow>;
  dataSource!: AxServerDataSource<AwardRow>;

  /** Detail dialog. */
  detail: CashbackAward | null = null;
  detailLoading = false;

  /** Action dialog (reason required; reverse may take a partial amount). */
  action: { type: AwardAction; award: CashbackAward } | null = null;
  actionForm = { reason: '', amount: '' };
  actionError = '';
  actionSaving = false;

  readonly fmtDate = fmtDate;
  readonly formatMoney = formatMoney;
  readonly awardStatusBadge = awardStatusBadge;
  readonly awardRecoveries = awardRecoveries;

  t(key: string, params?: Record<string, unknown>): string {
    return this.i18n.t(key, params);
  }

  ngOnInit(): void {
    const qp = this.route.snapshot.queryParamMap;
    const status = qp.get('status');
    const ruleId = qp.get('rule_id');
    this.buildTable(
      status && (CASHBACK_AWARD_STATUSES as readonly string[]).includes(status) ? status : null,
      ruleId && /^\d+$/.test(ruleId) ? ruleId : null,
    );
    const award = Number(qp.get('award'));
    if (Number.isInteger(award) && award > 0) this.openDetail(award);
  }

  // ── Table ─────────────────────────────────────────────────────────────

  private buildTable(status: string | null, ruleId: string | null): void {
    this.dataSource = new AxServerDataSource<AwardRow>((q) => this.fetch(q));
    this.config = {
      tableId: 'admin-cashback-awards',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: this.t('cashback.awards.search'),
      stickyHeader: true,
      hover: true,
      emptyTitle: this.t('cashback.awards.empty_title'),
      emptyDescription: this.t('cashback.awards.empty_desc'),
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'cashback-awards' },
      filters: [
        {
          key: 'status',
          label: this.t('cashback.awards.col_status'),
          type: 'select',
          options: CASHBACK_AWARD_STATUSES.map((s) => ({ label: this.t(`cashback.award_status.${s}`), value: s })),
          ...(status ? { defaultValue: status } : {}),
        },
        {
          key: 'rule_id',
          label: this.t('cashback.awards.col_rules'),
          type: 'select',
          optionsLoader: () => this.loadRuleOptions(),
          ...(ruleId ? { defaultValue: ruleId } : {}),
        },
        { key: 'customer', label: this.t('cashback.awards.col_customer'), type: 'text', placeholder: this.t('cashback.awards.customer_ph') },
        { key: 'date', label: this.t('cashback.awards.col_created'), type: 'date-range' },
      ],
      columns: [
        { key: 'order', label: this.t('cashback.awards.col_order'), sticky: 'left', width: '12rem',
          value: (r) => r.order?.order_reference ?? '' },
        { key: 'customer', label: this.t('cashback.awards.col_customer'),
          value: (r) => r.customer?.email ?? r.customer?.name ?? '' },
        { key: 'amount', label: this.t('cashback.awards.col_amount'), align: 'right', value: (r) => r.amount },
        { key: 'status', label: this.t('cashback.awards.col_status'), align: 'center' },
        { key: 'release_at', label: this.t('cashback.awards.col_release'), hideOnMobile: true,
          value: (r) => r.released_at ?? r.release_at ?? '' },
        { key: 'gift_card', label: this.t('cashback.awards.col_gift_card'), hideOnMobile: true,
          value: (r) => r.gift_card?.code ?? '' },
        { key: 'rules', label: this.t('cashback.awards.col_rules'), hideOnMobile: true,
          value: (r) => r.rules.map((x) => x.name).join(', ') },
        { key: 'created_at', label: this.t('cashback.awards.col_created'), hideOnMobile: true,
          format: (v) => fmtDate(v as string | null) },
      ],
      rowActions: [
        { id: 'view', label: this.t('cashback.awards.action_view'), icon: 'visibility' },
        { id: 'release', label: this.t('cashback.awards.action_release'), icon: 'card_giftcard',
          hidden: (r) => r.status !== 'pending', can: () => this.perms.can('cashback.manage') },
        { id: 'cancel', label: this.t('cashback.awards.action_cancel'), icon: 'cancel', variant: 'danger',
          hidden: (r) => r.status !== 'pending', can: () => this.perms.can('cashback.manage') },
        { id: 'reverse', label: this.t('cashback.awards.action_reverse'), icon: 'undo', variant: 'danger',
          hidden: (r) => r.status !== 'issued', can: () => this.perms.can('cashback.manage') },
      ],
    };
  }

  private loadRuleOptions(): Promise<readonly AxFilterOption[]> {
    return firstValueFrom(this.adapter.get_v3('GET /admin/cashback/rules', { query: { limit: 100 } }))
      .then((res: any) => (Array.isArray(res?.data) ? res.data : [])
        .filter((r: any) => Number(r?.id) > 0)
        .map((r: any) => ({ label: String(r.name ?? `#${r.id}`), value: String(r.id) })))
      .catch(() => []);
  }

  private fetch(query: AxQueryState) {
    const q: Record<string, string | number> = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q['order_reference'] = query.search;
    const f = query.filters;
    if (f['status']) q['status'] = String(f['status']);
    if (f['rule_id']) q['rule_id'] = String(f['rule_id']);
    if (typeof f['customer'] === 'string' && f['customer'].trim()) q['customer'] = f['customer'].trim();
    const range = f['date'] as AxDateRange | undefined;
    if (range?.from) q['date_from'] = range.from;
    if (range?.to) q['date_to'] = range.to;

    return this.adapter.get_v3('GET /admin/cashback/awards', { query: q }).pipe(
      map((response: any): AxServerFetchResult<AwardRow> => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : [];
        const rows = raw.map((a) => parseAward(a) as AwardRow);
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, this.t('cashback.awards.load_error')));
        return of({ rows: [], total: 0 } as AxServerFetchResult<AwardRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: AwardRow }): void {
    const { action, row } = e;
    if (action.id === 'view') this.openDetail(row.id);
    else if (action.id === 'release' || action.id === 'cancel' || action.id === 'reverse') this.openAction(action.id, row);
  }

  onRowClick(row: AwardRow): void {
    this.openDetail(row.id);
  }

  // ── Detail ────────────────────────────────────────────────────────────

  openDetail(id: number): void {
    this.detailLoading = true;
    this.detail = null;
    this.adapter.get_v3('GET /admin/cashback/awards/:id', { params: { id: String(id) } }).subscribe({
      next: (res: any) => {
        this.detail = parseAward(unwrap(res));
        this.detailLoading = false;
      },
      error: (err: any) => {
        this.detailLoading = false;
        this.toast.error(apiErrorMessage(err, this.t('cashback.awards.detail_error')));
      },
    });
  }

  closeDetail(): void {
    this.detail = null;
    this.detailLoading = false;
    if (this.route.snapshot.queryParamMap.has('award')) {
      this.router.navigate([], { relativeTo: this.route, queryParams: { award: null }, queryParamsHandling: 'merge', replaceUrl: true });
    }
  }

  /** Line value changed since accrual (rewritten at release). */
  lineChanged(l: { amount: string; accrued_amount: string }): boolean {
    return Number(l.amount).toFixed(2) !== Number(l.accrued_amount).toFixed(2);
  }

  hasMoney(v: string | null | undefined): boolean {
    return Number(v ?? 0) > 0;
  }

  // ── Actions ───────────────────────────────────────────────────────────

  openAction(type: AwardAction, award: CashbackAward): void {
    if (!this.perms.can('cashback.manage')) return;
    this.action = { type, award };
    this.actionForm = { reason: '', amount: '' };
    this.actionError = '';
  }

  closeAction(): void {
    if (this.actionSaving) return;
    this.action = null;
  }

  /** Remaining (not yet clawed back) value of an issued award. */
  reversible(a: CashbackAward): string {
    return Math.max(0, Number(a.amount) - Number(a.reversed_amount)).toFixed(2);
  }

  submitAction(): void {
    if (!this.action || this.actionSaving) return;
    const reason = this.actionForm.reason.trim();
    if (!reason || reason.length > 255) {
      this.actionError = this.t('cashback.awards.reason_required');
      return;
    }
    const { type, award } = this.action;
    const body: Record<string, string> = { reason };
    if (type === 'reverse') {
      const amt = String(this.actionForm.amount ?? '').trim();
      if (amt !== '') {
        const n = Number(amt);
        if (!/^\d{1,8}(\.\d{1,2})?$/.test(amt) || !(n > 0)) {
          this.actionError = this.t('cashback.awards.amount_invalid');
          return;
        }
        if (n > Number(this.reversible(award))) {
          this.actionError = this.t('cashback.awards.amount_too_high', { max: this.reversible(award) });
          return;
        }
        body['amount'] = n.toFixed(2);
      }
    }

    const key = type === 'release'
      ? 'POST /admin/cashback/awards/:id/release'
      : type === 'cancel'
        ? 'POST /admin/cashback/awards/:id/cancel'
        : 'POST /admin/cashback/awards/:id/reverse';

    this.actionSaving = true;
    this.actionError = '';
    this.adapter.post_v3(key, body, { params: { id: String(award.id) } }).subscribe({
      next: (res: any) => {
        this.actionSaving = false;
        this.action = null;
        const updated = parseAward(res?.data ?? res);
        if (type === 'release') {
          this.toast.success(this.t(res?.outcome === 'cancelled' ? 'cashback.awards.release_cancelled' : 'cashback.awards.released',
            { amount: formatMoney(updated.amount) }));
        } else if (type === 'cancel') {
          this.toast.success(this.t('cashback.awards.cancelled'));
        } else {
          const r = res?.reversal ?? {};
          this.toast.success(this.t('cashback.awards.reversed', {
            collected: formatMoney(r.collected ?? '0'),
            written_off: formatMoney(r.written_off ?? '0'),
          }));
        }
        if (this.detail && this.detail.id === updated.id) this.detail = updated;
        this.dataSource?.retry();
      },
      error: (err: any) => {
        this.actionSaving = false;
        this.actionError = apiErrorMessage(err, this.t('cashback.awards.action_error'));
        // A 409 means the award moved on under us; refresh what we show.
        if (Number(err?.status) === 409) {
          this.dataSource?.retry();
          if (this.detail?.id === award.id) this.openDetail(award.id);
        }
      },
    });
  }

  goBack(): void {
    this.navHistory.back('/admin/cashback');
  }

  statusLabel(s: CashbackAwardStatus | string): string {
    return this.t(`cashback.award_status.${s}`);
  }
}
