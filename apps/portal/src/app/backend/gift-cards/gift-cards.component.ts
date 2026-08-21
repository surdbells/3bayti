import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { AxComboboxComponent, AxComboboxOption } from '../../shared/forms/ax-combobox.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';
import { apiErrorMessage } from '../../shared/http/api-error';

/** A row from GET /admin/gift-cards (GiftCardAdminListItem). */
interface GiftCardRow extends Record<string, unknown> {
  id: number | string;
  code: string;
  theme: string;
  denomination: string;
  balance: string;
  currency: string;
  status: string;
  is_spendable: boolean;
  purchaser_email: string;
  recipient_name: string;
  recipient_email: string;
  is_delivered: boolean;
  created_at: string;
}

/** A row from GET /admin/gift-cards/redemptions — one checkout debit. */
interface RedemptionRow extends Record<string, unknown> {
  id: number | string;
  amount: string;
  currency: string;
  balance_after: string;
  order_reference: string;
  created_at: string;
  code: string;
  card_status: string;
  purchaser_email: string;
  purchaser_name: string;
}

/** Filter-panel state, sent to GET /admin/gift-cards when present. */
interface GiftCardFilters {
  status: string;
  min_balance: string;
  max_balance: string;
  min_value: string;
  max_value: string;
  created_from: string;
  created_to: string;
  delivered: '' | 'true' | 'false';
}

@Component({
  selector: 'app-gift-cards-admin',
  standalone: true,
  imports: [
    AdminShellComponent, CommonModule, FormsModule,
    AxDataTableComponent, AxCellDirective, IconComponent, TranslatePipe, AxComboboxComponent,
  ],
  styles: [`
    .ax-filter-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
      gap: 0.75rem 1rem;
      align-items: end;
    }
    .ax-field {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
    }
    .ax-field-label {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--ax-text-secondary, #64748b);
    }
    .ax-filter-actions {
      justify-content: flex-end;
    }
  `],
  template: `
<app-admin-shell>
  <div class="ax-container">
    <header class="ax-page-header">
      <div class="ax-page-header-content">
        <button type="button" (click)="goBack()" class="ax-btn ax-btn-ghost ax-btn-sm ax-mb-2" style="align-self:flex-start">
          <app-icon name="arrow_back" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.back' | translate }}
        </button>
        <span class="ax-page-header-eyebrow">{{ 'gift_cards_admin.eyebrow' | translate }}</span>
        <h1 class="ax-page-title">{{ 'gift_cards_admin.title' | translate }}</h1>
        <p class="ax-page-subtitle">{{ 'gift_cards_admin.subtitle' | translate }}</p>
      </div>
      <div class="ax-flex ax-gap-2 ax-items-center">
        <button type="button" class="ax-btn ax-btn-primary ax-btn-sm" (click)="openIssue()">
          <app-icon name="add" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.issue_card' | translate }}
        </button>
      </div>
    </header>

    <!-- Tabs: issued cards (primary) + themes (secondary) -->
    <div class="ax-tabs ax-mb-4">
      <div class="ax-tabs-list" role="tablist">
        <button type="button" class="ax-tab" role="tab" [attr.aria-selected]="tab === 'issued'" [class.ax-tab-active]="tab === 'issued'" (click)="tab = 'issued'">
          {{ 'gift_cards_admin.tab_issued' | translate }}
        </button>
        <button type="button" class="ax-tab" role="tab" [attr.aria-selected]="tab === 'redemptions'" [class.ax-tab-active]="tab === 'redemptions'" (click)="setRedemptionsTab()">
          {{ 'gift_cards_admin.tab_redemptions' | translate }}
        </button>
        <button type="button" class="ax-tab" role="tab" [attr.aria-selected]="tab === 'themes'" [class.ax-tab-active]="tab === 'themes'" (click)="setThemesTab()">
          {{ 'gift_cards_admin.tab_themes' | translate }}
        </button>
      </div>
    </div>

    <ng-container *ngIf="tab === 'issued'">
      <section class="ax-card ax-mb-4">
        <div class="ax-filter-grid">
          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_status' | translate }}</span>
            <app-ax-combobox class="ax-input-sm" [(ngModel)]="filters.status" (selectionChange)="applyFilters()"
              [options]="statusFilterOptions" [searchable]="true" [allowClear]="false"
              [ariaLabel]="'gift_cards_admin.filter_status' | translate"></app-ax-combobox>
          </label>

          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_delivered' | translate }}</span>
            <app-ax-combobox class="ax-input-sm" [(ngModel)]="filters.delivered" (selectionChange)="applyFilters()"
              [options]="deliveredFilterOptions" [searchable]="false" [allowClear]="false"
              [ariaLabel]="'gift_cards_admin.filter_delivered' | translate"></app-ax-combobox>
          </label>

          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_min_balance' | translate }}</span>
            <input type="number" min="0" step="0.01" class="ax-input ax-input-sm" [(ngModel)]="filters.min_balance" (change)="applyFilters()" />
          </label>
          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_max_balance' | translate }}</span>
            <input type="number" min="0" step="0.01" class="ax-input ax-input-sm" [(ngModel)]="filters.max_balance" (change)="applyFilters()" />
          </label>

          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_min_value' | translate }}</span>
            <input type="number" min="0" step="0.01" class="ax-input ax-input-sm" [(ngModel)]="filters.min_value" (change)="applyFilters()" />
          </label>
          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_max_value' | translate }}</span>
            <input type="number" min="0" step="0.01" class="ax-input ax-input-sm" [(ngModel)]="filters.max_value" (change)="applyFilters()" />
          </label>

          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_created_from' | translate }}</span>
            <input type="date" class="ax-input ax-input-sm" [(ngModel)]="filters.created_from" (change)="applyFilters()" />
          </label>
          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.filter_created_to' | translate }}</span>
            <input type="date" class="ax-input ax-input-sm" [(ngModel)]="filters.created_to" (change)="applyFilters()" />
          </label>

          <div class="ax-field ax-filter-actions">
            <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm" (click)="clearFilters()">
              <app-icon name="close" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.filter_clear' | translate }}
            </button>
          </div>
        </div>
      </section>

      <section class="ax-card ax-p-0">
        <app-ax-data-table [config]="config" [dataSource]="dataSource" (rowClick)="openDetail($event)" style="cursor:pointer">
          <ng-template axCell="code" let-row>
            <span class="ax-font-medium ax-text-primary" style="font-variant-numeric:tabular-nums">{{ row.code }}</span>
          </ng-template>
          <ng-template axCell="status" let-row>
            <span class="ax-badge" [ngClass]="statusBadgeClass(row.status)">{{ statusLabel(row.status) }}</span>
          </ng-template>
          <ng-template axCell="balance" let-row>
            <span class="ax-font-medium">{{ row.balance }} {{ row.currency }}</span>
            <span class="ax-text-sm ax-text-secondary"> / {{ row.denomination }}</span>
          </ng-template>
          <ng-template axCell="recipient" let-row>
            <span class="ax-text-primary">{{ row.recipient_name || '—' }}</span>
            <span *ngIf="row.recipient_email" class="ax-block ax-text-sm ax-text-secondary">{{ row.recipient_email }}</span>
          </ng-template>
          <ng-template axCell="is_delivered" let-row>
            <span class="ax-badge" [class.ax-badge-success]="row.is_delivered" [class.ax-badge-neutral]="!row.is_delivered">
              {{ (row.is_delivered ? 'gift_cards_admin.delivered_yes' : 'gift_cards_admin.delivered_no') | translate }}
            </span>
          </ng-template>
        </app-ax-data-table>
      </section>
    </ng-container>

    <!--
      Redemptions: gift-card value actually SPENT at checkout (every debit
      tied to an order — a single card or the customer's whole wallet).
      Complements the per-card ledger with the cross-card picture.
    -->
    <ng-container *ngIf="tab === 'redemptions'">
      <section class="ax-card ax-mb-4">
        <div class="ax-filter-grid">
          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.redeemed_from' | translate }}</span>
            <input type="date" class="ax-input ax-input-sm" [(ngModel)]="redemptionFilters.from" (change)="applyRedemptionFilters()" />
          </label>
          <label class="ax-field">
            <span class="ax-field-label">{{ 'gift_cards_admin.redeemed_to' | translate }}</span>
            <input type="date" class="ax-input ax-input-sm" [(ngModel)]="redemptionFilters.to" (change)="applyRedemptionFilters()" />
          </label>
          <div class="ax-field ax-filter-actions">
            <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm" (click)="clearRedemptionFilters()">
              <app-icon name="close" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.filter_clear' | translate }}
            </button>
          </div>
        </div>
      </section>

      <!-- Summary over the whole filtered range (not just this page). -->
      <section class="ax-card ax-mb-4">
        <div class="ax-flex ax-gap-6 ax-items-center ax-flex-wrap">
          <div>
            <div class="ax-field-label">{{ 'gift_cards_admin.total_redeemed' | translate }}</div>
            <div class="ax-page-title" style="margin:0">{{ redemptionSummary.total_redeemed }} {{ redemptionSummary.currency }}</div>
          </div>
          <div>
            <div class="ax-field-label">{{ 'gift_cards_admin.redemption_count' | translate }}</div>
            <div class="ax-page-title" style="margin:0">{{ redemptionSummary.redemption_count }}</div>
          </div>
        </div>
      </section>

      <section class="ax-card ax-p-0">
        <app-ax-data-table [config]="redemptionConfig" [dataSource]="redemptionSource">
          <ng-template axCell="code" let-row>
            <span class="ax-font-medium ax-text-primary" style="font-variant-numeric:tabular-nums">{{ row.code }}</span>
          </ng-template>
          <ng-template axCell="amount" let-row>
            <span class="ax-font-medium">−{{ row.amount }} {{ row.currency }}</span>
          </ng-template>
          <ng-template axCell="purchaser_email" let-row>
            <span class="ax-text-primary">{{ row.purchaser_name || '—' }}</span>
            <span *ngIf="row.purchaser_email" class="ax-block ax-text-sm ax-text-secondary">{{ row.purchaser_email }}</span>
          </ng-template>
        </app-ax-data-table>
      </section>
    </ng-container>

    <!-- Secondary tab: storefront themes (read-only reference) -->
    <ng-container *ngIf="tab === 'themes'">
      <div *ngIf="themesLoading" class="ax-flex ax-justify-center ax-py-8">
        <span class="ax-spinner ax-spinner-lg"></span>
      </div>
      <section *ngIf="!themesLoading && themes.length > 0" class="ax-card ax-p-0">
        <div class="ax-table-wrapper">
          <table class="ax-table ax-table-hover">
            <thead>
              <tr>
                <th>{{ 'gift_cards_admin.theme' | translate }}</th>
                <th>{{ 'gift_cards_admin.theme_arabic' | translate }}</th>
                <th>{{ 'gift_cards_admin.theme_pattern' | translate }}</th>
                <th>{{ 'gift_cards_admin.theme_primary' | translate }}</th>
                <th>{{ 'gift_cards_admin.theme_accent' | translate }}</th>
                <th>{{ 'gift_cards_admin.theme_photo' | translate }}</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let t of themes">
                <td><span class="ax-font-medium ax-text-primary">{{ t.label }}</span></td>
                <td><span class="ax-text-sm ax-text-secondary" dir="rtl">{{ t.arabic_label }}</span></td>
                <td><span class="ax-badge ax-badge-neutral">{{ t.pattern }}</span></td>
                <td>
                  <span class="ax-flex ax-items-center ax-gap-2">
                    <span [style.background]="t.primary_color" style="width:1rem;height:1rem;border-radius:3px;display:inline-block;border:1px solid rgba(0,0,0,.1)"></span>
                    <span class="ax-text-sm ax-text-secondary">{{ t.primary_color }}</span>
                  </span>
                </td>
                <td>
                  <span class="ax-flex ax-items-center ax-gap-2">
                    <span [style.background]="t.accent_color" style="width:1rem;height:1rem;border-radius:3px;display:inline-block;border:1px solid rgba(0,0,0,.1)"></span>
                    <span class="ax-text-sm ax-text-secondary">{{ t.accent_color }}</span>
                  </span>
                </td>
                <td>
                  <span class="ax-badge" [class.ax-badge-success]="t.supports_photo" [class.ax-badge-neutral]="!t.supports_photo">
                    {{ (t.supports_photo ? 'gift_cards_admin.delivered_yes' : 'gift_cards_admin.delivered_no') | translate }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </ng-container>
  </div>

  <!-- Issue-card dialog -->
  <div *ngIf="issuing" class="ax-backdrop" style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:1.5rem;background:rgba(0,0,0,.45)" (click)="closeIssue()">
    <div class="ax-modal ax-modal-sm" (click)="$event.stopPropagation()" role="dialog" aria-modal="true">
      <header class="ax-modal-header">
        <h2 class="ax-modal-title">{{ 'gift_cards_admin.issue_card' | translate }}</h2>
        <button type="button" class="ax-modal-close" (click)="closeIssue()"><app-icon name="close"></app-icon></button>
      </header>
      <div class="ax-modal-body">
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.issue_value' | translate }}</span>
          <input type="number" min="100" max="10000" step="0.01" class="ax-input" [(ngModel)]="issueForm.value" placeholder="500.00" />
        </label>
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.issue_currency' | translate }}</span>
          <input type="text" class="ax-input" [(ngModel)]="issueForm.currency" maxlength="3" />
        </label>
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.issue_recipient_name' | translate }}</span>
          <input type="text" class="ax-input" [(ngModel)]="issueForm.recipient_name" />
        </label>
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.issue_recipient_email' | translate }}</span>
          <input type="email" class="ax-input" [(ngModel)]="issueForm.recipient_email" />
        </label>
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.issue_note' | translate }}</span>
          <textarea class="ax-input" rows="2" [(ngModel)]="issueForm.note"></textarea>
        </label>
      </div>
      <footer class="ax-modal-footer">
        <button type="button" class="ax-btn ax-btn-ghost" (click)="closeIssue()">{{ 'gift_cards_admin.cancel' | translate }}</button>
        <button type="button" class="ax-btn ax-btn-primary" [disabled]="issueSaving || !issueForm.value" (click)="submitIssue()">
          {{ 'gift_cards_admin.issue_submit' | translate }}
        </button>
      </footer>
    </div>
  </div>
</app-admin-shell>
  `,
})
export class GiftCardsAdminComponent implements OnInit {
  private readonly router = inject(Router);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  tab: 'issued' | 'redemptions' | 'themes' = 'issued';

  filters: GiftCardFilters = {
    status: '', min_balance: '', max_balance: '',
    min_value: '', max_value: '', created_from: '', created_to: '', delivered: '',
  };

  /** Searchable combobox options for the issued-cards filter dropdowns. */
  get statusFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('gift_cards_admin.filter_all') },
      { id: 'pending_payment', label: this.t('gift_cards_admin.status_label_pending_payment') },
      { id: 'active', label: this.t('gift_cards_admin.status_label_active') },
      { id: 'partially_used', label: this.t('gift_cards_admin.status_label_partially_used') },
      { id: 'exhausted', label: this.t('gift_cards_admin.status_label_exhausted') },
      { id: 'expired', label: this.t('gift_cards_admin.status_label_expired') },
      { id: 'voided', label: this.t('gift_cards_admin.status_label_voided') },
    ];
  }
  get deliveredFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('gift_cards_admin.filter_all') },
      { id: 'true', label: this.t('gift_cards_admin.delivered_yes') },
      { id: 'false', label: this.t('gift_cards_admin.delivered_no') },
    ];
  }

  config!: AxDataTableConfig<GiftCardRow>;
  dataSource!: AxServerDataSource<GiftCardRow>;

  // Redemptions (gift-card value spent at checkout)
  redemptionFilters = { from: '', to: '' };
  redemptionConfig!: AxDataTableConfig<RedemptionRow>;
  redemptionSource!: AxServerDataSource<RedemptionRow>;
  redemptionSummary = { total_redeemed: '0.00', redemption_count: 0, currency: 'AED' };
  private redemptionsBuilt = false;

  // Themes (secondary tab)
  themes: any[] = [];
  themesLoading = false;
  private themesLoaded = false;

  // Issue dialog
  issuing = false;
  issueSaving = false;
  issueForm = { value: '', currency: 'AED', recipient_name: '', recipient_email: '', note: '' };

  user_session: any = {};

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<GiftCardRow>((q) => this.fetchCards(q));
    this.config = {
      tableId: 'admin-gift-cards',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search by code, recipient or purchaser email…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No gift cards found',
      emptyDescription: 'No issued gift cards match your current filters.',
      columns: [
        { key: 'code', label: 'Code', sticky: 'left', width: '13rem' },
        { key: 'status', label: 'Status', align: 'center' },
        { key: 'balance', label: 'Balance', value: (r) => `${r.balance} ${r.currency}` },
        { key: 'recipient', label: 'Recipient', value: (r) => r.recipient_name || r.recipient_email || '—' },
        { key: 'purchaser_email', label: 'Purchaser', hideOnMobile: true },
        { key: 'is_delivered', label: 'Delivered', align: 'center', value: (r) => (r.is_delivered ? 'Yes' : 'No') },
        { key: 'created_at', label: 'Created', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
      ],
    };
  }

  private fetchCards(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;

    const f = this.filters;
    if (f.status) q.status = f.status;
    if (f.min_balance) q.min_balance = f.min_balance;
    if (f.max_balance) q.max_balance = f.max_balance;
    if (f.min_value) q.min_value = f.min_value;
    if (f.max_value) q.max_value = f.max_value;
    if (f.created_from) q.created_from = f.created_from;
    if (f.created_to) q.created_to = f.created_to;
    if (f.delivered) q.delivered = f.delivered;

    return this.adapter.get_v3('GET /admin/gift-cards', { query: q }).pipe(
      map((response: any): AxServerFetchResult<GiftCardRow> => {
        // The API only ever returns `data` as an array.
        const raw: any[] = Array.isArray(response?.data) ? response.data : [];
        const rows = raw.map((g) => this.mapCard(g));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        // Surface a clear, type-specific message (toast) and re-throw so the
        // data table renders its error state instead of a silent empty grid.
        this.toast.error(this.loadErrorMessage(err));
        return throwError(() => err);
      }),
    );
  }

  /** Map an HTTP failure to a clear, admin-facing message. */
  private loadErrorMessage(err: any): string {
    const status = Number(err?.status ?? err?.error?.error?.status ?? 0);
    if (status === 401 || status === 403) {
      return this.t('gift_cards_admin.load_error_forbidden');
    }
    if (status === 404 || status === 0) {
      return this.t('gift_cards_admin.load_error_unavailable');
    }
    const serverMessage = err?.error?.error?.message ?? err?.error?.message;
    return serverMessage || this.t('gift_cards_admin.load_error_generic');
  }

  /** Resolve a flat i18n key. */
  private t(key: string): string {
    return this.i18n.t(key);
  }

  private mapCard(g: any): GiftCardRow {
    return {
      id: g.id,
      code: g.code ?? '',
      theme: g.theme ?? '',
      denomination: g.denomination ?? '',
      balance: g.balance ?? '',
      currency: g.currency ?? '',
      status: g.status ?? '',
      is_spendable: !!g.is_spendable,
      purchaser_email: g.purchaser?.email ?? '',
      recipient_name: g.recipient_name ?? g.recipient_user?.name ?? '',
      recipient_email: g.recipient_email ?? g.recipient_user?.email ?? '',
      is_delivered: !!g.is_delivered,
      created_at: g.created_at ?? '',
    } as GiftCardRow;
  }

  /**
   * Friendly, bucketed status label for both the filter dropdown context and
   * the badge column. The underlying query values stay the raw status strings.
   */
  statusLabel(status: string): string {
    const key = GiftCardsAdminComponent.STATUS_LABEL_KEYS[status];
    if (key) return this.i18n.t(key);
    return String(status ?? '').replace(/_/g, ' ');
  }

  private static readonly STATUS_LABEL_KEYS: Record<string, string> = {
    pending_payment: 'gift_cards_admin.status_label_pending_payment',
    active: 'gift_cards_admin.status_label_active',
    partially_used: 'gift_cards_admin.status_label_partially_used',
    exhausted: 'gift_cards_admin.status_label_exhausted',
    expired: 'gift_cards_admin.status_label_expired',
    voided: 'gift_cards_admin.status_label_voided',
  };

  statusBadgeClass(status: string): string {
    switch (status) {
      case 'active': return 'ax-badge-success';
      case 'partially_used': return 'ax-badge-info';
      case 'pending_payment': return 'ax-badge-warning';
      case 'voided': return 'ax-badge-danger';
      case 'expired':
      case 'exhausted':
      default: return 'ax-badge-neutral';
    }
  }

  applyFilters() { this.dataSource.retry(); }

  clearFilters() {
    this.filters = {
      status: '', min_balance: '', max_balance: '',
      min_value: '', max_value: '', created_from: '', created_to: '', delivered: '',
    };
    this.dataSource.retry();
  }

  openDetail(row: GiftCardRow) {
    this.router.navigate(['/admin-gift-cards', row.id]);
  }

  // ── Redemptions (gift-card value spent at checkout) ─────────────────

  setRedemptionsTab() {
    this.tab = 'redemptions';
    if (this.redemptionsBuilt) return;
    this.redemptionsBuilt = true;
    this.redemptionSource = new AxServerDataSource<RedemptionRow>((q) => this.fetchRedemptions(q));
    this.redemptionConfig = {
      tableId: 'admin-gift-card-redemptions',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: false,
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No redemptions yet',
      emptyDescription: 'No gift-card balance has been spent at checkout in this period.',
      columns: [
        { key: 'created_at', label: 'When', sticky: 'left', width: '12rem',
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'code', label: 'Card', width: '13rem' },
        { key: 'amount', label: 'Redeemed', align: 'right' },
        { key: 'order_reference', label: 'Order' },
        { key: 'purchaser_email', label: 'Customer', hideOnMobile: true },
        { key: 'balance_after', label: 'Balance after', align: 'right', hideOnMobile: true,
          value: (r) => `${r.balance_after} ${r.currency}` },
      ],
    };
  }

  private fetchRedemptions(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (this.redemptionFilters.from) q.from = this.redemptionFilters.from;
    if (this.redemptionFilters.to) q.to = this.redemptionFilters.to;

    return this.adapter.get_v3('GET /admin/gift-cards/redemptions', { query: q }).pipe(
      map((response: any): AxServerFetchResult<RedemptionRow> => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : [];
        // Summary covers the whole filtered range, not just this page.
        const s = response?.summary;
        if (s) {
          this.redemptionSummary = {
            total_redeemed: s.total_redeemed ?? '0.00',
            redemption_count: Number(s.redemption_count ?? 0),
            currency: s.currency ?? 'AED',
          };
        }
        const rows = raw.map((t) => this.mapRedemption(t));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(this.loadErrorMessage(err));
        return throwError(() => err);
      }),
    );
  }

  private mapRedemption(t: any): RedemptionRow {
    return {
      id: t.id,
      amount: t.amount ?? '0.00',
      currency: this.redemptionSummary.currency,
      balance_after: t.balance_after ?? '0.00',
      order_reference: t.order_reference ?? '—',
      created_at: t.created_at ?? '',
      code: t.card?.code ?? '',
      card_status: t.card?.status ?? '',
      purchaser_email: t.purchaser?.email ?? '',
      purchaser_name: t.purchaser?.name ?? '',
    };
  }

  applyRedemptionFilters() { this.redemptionSource?.retry(); }

  clearRedemptionFilters() {
    this.redemptionFilters = { from: '', to: '' };
    this.redemptionSource?.retry();
  }

  // ── Themes (secondary tab) ──────────────────────────────────────────
  setThemesTab() {
    this.tab = 'themes';
    if (this.themesLoaded) return;
    this.themesLoading = true;
    this.adapter.get_v3('GET /gift-cards/themes').subscribe({
      next: (res: any) => {
        const giftData = res?.data ?? res;
        // The API returns themes as a slug-keyed OBJECT
        // ({ "birthday": {...}, "wedding": {...} }), not an array. Normalise
        // both shapes to an array of `{ slug, ...meta }` rows for the table.
        if (Array.isArray(giftData)) {
          this.themes = giftData;
        } else if (giftData && typeof giftData === 'object') {
          const nested = (giftData as any).themes ?? (res as any)?.themes;
          const source = nested && typeof nested === 'object' ? nested : giftData;
          this.themes = Array.isArray(source)
            ? source
            : Object.entries(source).map(([slug, meta]) => ({ slug, ...(meta as any) }));
        } else {
          this.themes = [];
        }
        this.themesLoaded = true;
        this.themesLoading = false;
      },
      error: (err: any) => { this.themesLoading = false; this.toast.error(apiErrorMessage(err, 'Failed to load gift card themes.')); },
    });
  }

  // ── Issue card ──────────────────────────────────────────────────────
  openIssue() {
    this.issueForm = { value: '', currency: 'AED', recipient_name: '', recipient_email: '', note: '' };
    this.issuing = true;
  }
  closeIssue() { this.issuing = false; }

  submitIssue() {
    const value = Number(this.issueForm.value);
    if (!value || value < 100 || value > 10000) {
      this.toast.error('Value must be between 100 and 10000.');
      return;
    }
    const body: any = { value: value.toFixed(2) };
    if (this.issueForm.currency) body.currency = this.issueForm.currency.trim().toUpperCase();
    if (this.issueForm.recipient_name) body.recipient_name = this.issueForm.recipient_name.trim();
    if (this.issueForm.recipient_email) body.recipient_email = this.issueForm.recipient_email.trim();
    if (this.issueForm.note) body.note = this.issueForm.note.trim();

    this.issueSaving = true;
    this.adapter.post_v3('POST /admin/gift-cards', body).subscribe({
      next: () => {
        this.issueSaving = false;
        this.issuing = false;
        this.toast.success('Gift card issued.');
        this.dataSource.retry();
      },
      error: (err: any) => {
        this.issueSaving = false;
        this.toast.error(err?.error?.error?.message ?? 'Failed to issue gift card.');
      },
    });
  }

  goBack() { this.navHistory.back('/backend'); }
}
