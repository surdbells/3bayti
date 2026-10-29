import { Component, DestroyRef, OnInit, ViewChild, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Observable, defer, throwError } from 'rxjs';
import { catchError, map } from 'rxjs/operators';

import {
  TryOnAdminService,
  TryOnEligibility,
  TryOnProduct,
  TRY_ON_PAGE_SIZE_DEFAULT,
} from '../../services/try-on-admin.service';
import { PermissionService } from '../../services/permission.service';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { CfImagePipe } from '../../shared/cf-image.pipe';
import { AxTooltipDirective } from '../../shared/overlays/ax-tooltip.directive';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';
import {
  EligibilityBadge,
  ToggleState,
  Translate,
  TryOnFilterTab,
  defaultFilterTab,
  eligibilityBadge,
  eligibilityFromError,
  eligibilityHintKey,
  enabledDelta,
  offReasonKeys,
  showProductStatus,
  summarizeBulk,
  toggleErrorMessage,
  toggleState,
} from './try-on-ui';

/** Flat table row (mapped from the T2 product item). */
export interface TryOnRow extends Record<string, unknown> {
  /** v3 product id, the admin product page's route id. */
  id: number;
  slug: string;
  name: string;
  status: string;
  store: string;
  image: string | null;
  try_on_enabled: boolean;
  eligibility: TryOnEligibility | null;
}

/** Optimistic / server-confirmed state of a row changed on this page. */
interface RowPatch {
  readonly enabled: boolean;
  readonly eligibility: TryOnEligibility | null;
  /**
   * The page's settle counter when this row's PUT finished; null while it is
   * still in flight (the switch is busy). A list response only supersedes the
   * patches that settled before its request was sent.
   */
  readonly settledAt: number | null;
}

export function toTryOnRow(p: TryOnProduct): TryOnRow {
  return {
    id: p.id,
    slug: p.slug,
    name: p.name || p.slug || `#${p.id}`,
    status: p.status,
    store: p.vendor?.name ?? '',
    image: p.primary_image_url,
    try_on_enabled: p.try_on_enabled,
    eligibility: p.eligibility,
  };
}

/**
 * Admin "Virtual Try-On": choose which products show customers the "Try it
 * on" button (products.try_on_enabled).
 *
 * Status (GET /admin/try-on/status) says whether Try-On is live; while it's
 * off the page explains which env flag is missing and still lets admins
 * prepare products (the storefront only shows "Try it on" once Try-On is on
 * AND the product's main image is a JPEG/PNG/WebP in 3bayti's image storage). The
 * list (GET /admin/try-on/products) shows each garment image's eligibility;
 * the per-row switch (PUT /admin/products/:id/try-on) is optimistic with
 * rollback, and the bulk actions use POST /admin/try-on/products/bulk.
 * Viewing needs products.view (route guard); switching needs products.edit.
 * Products are linked and written by v3 id only.
 */
@Component({
  selector: 'app-admin-try-on',
  standalone: true,
  imports: [
    AdminShellComponent, CommonModule, RouterLink, AxDataTableComponent, AxCellDirective,
    IconComponent, TranslatePipe, CfImagePipe, AxTooltipDirective, AxCanDirective,
  ],
  templateUrl: './admin-try-on.component.html',
  styleUrl: './admin-try-on.component.css',
})
export class AdminTryOnComponent implements OnInit {
  private readonly svc = inject(TryOnAdminService);
  private readonly perms = inject(PermissionService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly destroyRef = inject(DestroyRef);

  @ViewChild(AxDataTableComponent) private table?: AxDataTableComponent<TryOnRow>;

  /** Bound translate (for the pure helpers). */
  readonly t: Translate = (key, params) => this.i18n.t(key, params);

  readonly status = this.svc.status;
  readonly statusLoading = signal(true);
  readonly statusError = signal('');
  readonly canEdit = computed(() => this.perms.can('products.edit'));
  readonly offReasons = computed(() => offReasonKeys(this.status()));
  readonly bulkBusy = signal(false);

  /** Rows changed on this page, by product id (wins over the fetched row). */
  private readonly patches = signal<ReadonlyMap<number, RowPatch>>(new Map());
  /** Bumped every time a row's PUT settles (see RowPatch.settledAt). */
  private settleSeq = 0;

  /** Built once the status is known (the default tab depends on it). */
  config: AxDataTableConfig<TryOnRow> | null = null;
  readonly dataSource = new AxServerDataSource<TryOnRow>((q, opts) => this.fetch(q, opts));

  ngOnInit(): void {
    this.loadStatus();
  }

  /** (Re)load the status; the table is built after the first answer. */
  loadStatus(): void {
    this.statusLoading.set(true);
    this.statusError.set('');
    this.svc.loadStatus().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: () => {
        this.statusLoading.set(false);
        this.ensureTable();
      },
      error: (err: unknown) => {
        this.statusLoading.set(false);
        this.statusError.set(apiErrorMessage(err, this.t('tryon.status_error')));
        this.ensureTable();
      },
    });
  }

  private ensureTable(): void {
    if (this.config) return;
    this.config = this.buildConfig(defaultFilterTab(this.status()));
  }

  buildConfig(tab: TryOnFilterTab): AxDataTableConfig<TryOnRow> {
    const t = this.t;
    return {
      tableId: 'admin-try-on',
      mode: 'server',
      rowId: 'id',
      pageSize: TRY_ON_PAGE_SIZE_DEFAULT,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: t('tryon.search_placeholder'),
      selectable: this.canEdit(),
      stickyHeader: true,
      hover: true,
      emptyTitle: t('tryon.empty_title'),
      emptyDescription: t('tryon.empty_desc'),
      filters: [
        {
          key: 'enabled',
          label: t('tryon.filter_label'),
          type: 'chips',
          options: [
            { label: t('tryon.filter_enabled'), value: 'true' },
            { label: t('tryon.filter_all'), value: '' },
          ],
          defaultValue: tab,
        },
      ],
      columns: [
        { key: 'image', label: '', width: '4.5rem', pinned: true, excludeFromExport: true },
        { key: 'name', label: t('tryon.col_product'), sticky: 'left', width: '18rem', widthMobile: '11rem' },
        { key: 'store', label: t('tryon.col_store'), hideOnMobile: true, value: (r) => r.store || '—' },
        { key: 'eligibility', label: t('tryon.col_image'), align: 'center' },
        { key: 'try_on_enabled', label: t('tryon.col_try_on'), align: 'center', pinned: true },
      ],
      bulkActions: [
        { id: 'enable', label: t('tryon.bulk_enable'), icon: 'check_circle', can: () => this.canEdit() && !this.bulkBusy() },
        { id: 'disable', label: t('tryon.bulk_disable'), icon: 'block', can: () => this.canEdit() && !this.bulkBusy() },
      ],
    };
  }

  /** Table fetcher: T2 page → rows. Errors toast and surface the table's retry state. */
  private fetch(q: AxQueryState, opts: { readonly forExport?: boolean } = {}): Observable<AxServerFetchResult<TryOnRow>> {
    const tab = q.filters['enabled'];
    return defer(() => {
      // Patches settled up to now are older than this request's answer;
      // later ones (and in-flight ones) may be newer than it, so they stay.
      const issuedAt = this.settleSeq;
      return this.svc
        .listProducts({
          q: q.search,
          enabled: tab === 'true' ? true : null,
          page: q.pageIndex + 1,
          limit: q.pageSize,
        })
        .pipe(
          map((page): AxServerFetchResult<TryOnRow> => {
            // An export doesn't replace the rows on screen, so it never prunes.
            if (!opts.forExport) this.dropPatchesSettledBy(issuedAt);
            return { rows: page.items.map(toTryOnRow), total: page.total };
          }),
        );
    }).pipe(
      catchError((err: unknown) => {
        this.toast.error(apiErrorMessage(err, this.t('tryon.list_error')));
        return throwError(() => err);
      }),
    );
  }

  /** Fresh server rows supersede the patches that settled at or before `seq`. */
  private dropPatchesSettledBy(seq: number): void {
    this.patches.update((m) => new Map([...m].filter(([, p]) => p.settledAt === null || p.settledAt > seq)));
  }

  // ── Row state ────────────────────────────────────────────────────────

  enabledOf(row: TryOnRow): boolean {
    return this.patches().get(row.id)?.enabled ?? row.try_on_enabled;
  }

  eligibilityOf(row: TryOnRow): TryOnEligibility | null {
    const p = this.patches().get(row.id);
    return p ? p.eligibility : row.eligibility;
  }

  busyOf(row: TryOnRow): boolean {
    const p = this.patches().get(row.id);
    return !!p && p.settledAt === null;
  }

  badgeOf(row: TryOnRow): EligibilityBadge {
    return eligibilityBadge(this.eligibilityOf(row));
  }

  toggleOf(row: TryOnRow): ToggleState {
    return toggleState(this.enabledOf(row), this.eligibilityOf(row), this.canEdit(), this.busyOf(row) || this.bulkBusy());
  }

  /** Tooltip on the switch: why it's locked ('' when it isn't). */
  toggleHint(row: TryOnRow): string {
    const key = this.toggleOf(row).hintKey;
    return key ? this.t(key) : '';
  }

  toggleAriaLabel(row: TryOnRow): string {
    const label = this.t(this.enabledOf(row) ? 'tryon.toggle.aria_disable' : 'tryon.toggle.aria_enable', { name: row.name });
    const hint = this.toggleHint(row);
    return hint ? `${label}. ${hint}` : label;
  }

  eligibilityHint(row: TryOnRow): string {
    return this.t(eligibilityHintKey(this.eligibilityOf(row)));
  }

  showStatus(status: string): boolean {
    return showProductStatus(status);
  }

  /** Localized product status (raw value when there is no translation). */
  statusLabel(status: string): string {
    const key = `tryon.product_status.${String(status).toLowerCase()}`;
    const label = this.t(key);
    return label === key ? status : label;
  }

  private setPatch(id: number, patch: RowPatch): void {
    this.patches.update((m) => new Map(m).set(id, patch));
  }

  /** Per-row switch: optimistic flip, server-confirmed, rolled back on failure. */
  toggle(row: TryOnRow, ev?: Event): void {
    const prev = this.enabledOf(row);
    const eligibility = this.eligibilityOf(row);
    if (this.toggleOf(row).disabled) {
      const input = ev?.target as HTMLInputElement | null;
      if (input) input.checked = prev;
      return;
    }
    const next = !prev;
    this.setPatch(row.id, { enabled: next, eligibility, settledAt: null });
    this.svc.setEnabled(row.id, next).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (p) => {
        this.setPatch(row.id, { enabled: p.try_on_enabled, eligibility: p.eligibility ?? eligibility, settledAt: ++this.settleSeq });
        this.svc.bumpEnabledCount(enabledDelta(prev, p.try_on_enabled));
        this.toast.success(this.t(p.try_on_enabled ? 'tryon.toggle.enabled' : 'tryon.toggle.disabled', { name: row.name }));
      },
      error: (err: unknown) => {
        this.setPatch(row.id, { enabled: prev, eligibility: eligibilityFromError(err) ?? eligibility, settledAt: ++this.settleSeq });
        this.toast.error(toggleErrorMessage(err, this.t));
      },
    });
  }

  // ── Bulk ─────────────────────────────────────────────────────────────

  onBulkAction(e: { action: { id: string }; selection: TryOnRow[] }): void {
    if (e.action.id !== 'enable' && e.action.id !== 'disable') return;
    this.runBulk(e.selection.map((r) => r.id), e.action.id === 'enable');
  }

  runBulk(ids: readonly number[], enabled: boolean): void {
    if (!this.canEdit() || this.bulkBusy() || !ids.length) return;
    this.bulkBusy.set(true);
    this.svc.bulkSetEnabled(ids, enabled).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (result) => {
        this.bulkBusy.set(false);
        const summary = summarizeBulk(result, enabled, this.t);
        if (summary.ok) this.toast.success(summary.message);
        else this.toast.error(summary.message);
        this.table?.clearSelection();
        this.refresh();
      },
      error: (err: unknown) => {
        this.bulkBusy.set(false);
        this.toast.error(apiErrorMessage(err, this.t('tryon.bulk_error')));
        // Earlier chunks may have applied before the failure: re-read.
        this.refresh();
      },
    });
  }

  /** Re-read the rows and the (count-bearing) status. */
  refresh(): void {
    this.dataSource.retry();
    this.svc.loadStatus().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({ error: () => {} });
  }

  goBack(): void {
    this.navHistory.back('/backend');
  }
}
