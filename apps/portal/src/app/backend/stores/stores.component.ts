import { Component, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { AxComboboxComponent, AxComboboxOption } from '../../shared/forms/ax-combobox.component';
import { TopPerformersComponent, TopPerformer } from '../../shared/top-performers/top-performers.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

/** Row shape surfaced to the enterprise table (mapped from v3 vendor). */
interface VendorRow extends Record<string, unknown> {
  id: number;
  store_name: string;
  store_email: string;
  store_phone: string;
  emirate: string;
  country: string;
  last_login: string;
  status: boolean;
  approved: boolean;
}

/** Filter-panel state, sent to GET /admin/vendors when present. */
interface StoreFilters {
  status: '' | 'pending' | 'approved' | 'suspended';
  is_active: '' | 'true' | 'false';
  is_verified: '' | 'true' | 'false';
  is_featured: '' | 'true' | 'false';
  emirate: string;
  created_from: string;
  created_to: string;
}

@Component({
  selector: 'app-stores',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AxDataTableComponent,
    AxCellDirective, IconComponent, TranslatePipe, AxComboboxComponent, TopPerformersComponent],
  templateUrl: './stores.component.html',
  styleUrl: './stores.component.css',
})
export class StoresComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  private readonly i18n = inject(I18nService);

  ui_controls = { is_loading: false, no_data: false, nav_open: false };

  /** Emirate filter options: value sent to the API + i18n key for the label. */
  readonly emirateOptions: ReadonlyArray<{ value: string; key: string }> = [
    { value: 'Dubai', key: 'emirate.dubai' },
    { value: 'Abu Dhabi', key: 'emirate.abu_dhabi' },
    { value: 'Sharjah', key: 'emirate.sharjah' },
    { value: 'Ajman', key: 'emirate.ajman' },
    { value: 'Umm Al Quwain', key: 'emirate.umm_al_quwain' },
    { value: 'Ras Al Khaimah', key: 'emirate.ras_al_khaimah' },
    { value: 'Fujairah', key: 'emirate.fujairah' },
  ];

  // ── Searchable filter dropdown options (labels resolved via i18n) ──────
  private t(k: string): string { return this.i18n.t(k); }
  get statusFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('stores.filter_status_any') },
      { id: 'pending', label: this.t('stores.filter_status_pending') },
      { id: 'approved', label: this.t('stores.filter_status_approved') },
      { id: 'suspended', label: this.t('stores.filter_status_suspended') },
    ];
  }
  get activeFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('stores.filter_any') },
      { id: 'true', label: this.t('stores.filter_active_yes') },
      { id: 'false', label: this.t('stores.filter_active_no') },
    ];
  }
  get verifiedFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('stores.filter_any') },
      { id: 'true', label: this.t('stores.filter_verified_yes') },
      { id: 'false', label: this.t('stores.filter_verified_no') },
    ];
  }
  get featuredFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('stores.filter_any') },
      { id: 'true', label: this.t('stores.filter_featured_yes') },
      { id: 'false', label: this.t('stores.filter_featured_no') },
    ];
  }
  get emirateFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('stores.filter_emirate_any') },
      ...this.emirateOptions.map((e) => ({ id: e.value, label: this.t(e.key) })),
    ];
  }

  /** Status/active/verified/featured/emirate + join-date-range filters. */
  filters: StoreFilters = {
    status: '',
    is_active: '',
    is_verified: '',
    is_featured: '',
    emirate: '',
    created_from: '',
    created_to: '',
  };

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_store: false,
  };

  /** Enterprise-table config + data source. */
  config!: AxDataTableConfig<VendorRow>;
  dataSource!: AxServerDataSource<VendorRow>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  topStores: TopPerformer[] = [];
  topStoresLoading = true;

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
    this.loadTopStores();
  }

  /** Top-performing stores carousel (by units sold). */
  private loadTopStores(): void {
    this.topStoresLoading = true;
    this.adapter.get_v3('GET /admin/top-stores').pipe(
      map((res: any) => (res?.data ?? []) as any[]),
      catchError(() => of([] as any[])),
    ).subscribe((rows) => {
      this.topStores = rows.map((r) => ({
        id: r.id,
        rank: r.rank,
        name: r.name,
        value: r.sales_count,
        imageUrl: r.logo_url ?? null,
      }));
      this.topStoresLoading = false;
    });
  }

  onTopStoreSelect(it: TopPerformer): void {
    this.router.navigate(['/admin/stores', it.id], { queryParams: { name: it.name } });
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<VendorRow>(
      (query: AxQueryState) => this.fetchVendors(query),
      // Short debounce so filter selections apply (near-)instantly while
      // still coalescing rapid search keystrokes.
      120,
    );

    this.config = {
      tableId: 'admin-stores',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search stores by name, email…',
      selectable: true,
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No stores found',
      emptyDescription: 'No vendor stores match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx', 'pdf'], filename: 'stores' },
      columns: [
        { key: 'store_name', label: 'Store', sortable: true, sticky: 'left', width: '16rem' },
        { key: 'store_email', label: 'Email', hideOnMobile: true },
        { key: 'store_phone', label: 'Phone', hideOnMobile: true },
        { key: 'emirate', label: 'Emirate', sortable: true, align: 'center', hideOnMobile: true,
          value: (r) => r.emirate || '—' },
        { key: 'country', label: 'Country', sortable: true, align: 'center', hideOnMobile: true,
          value: (r) => r.country || '—' },
        {
          key: 'last_login', label: 'Updated', sortable: true, hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—'),
        },
        { key: 'status', label: 'Status', sortable: true, align: 'center' },
        { key: 'approved', label: 'Approval', align: 'center' },
      ],
      bulkActions: [
        { id: 'bulk-approve', label: 'Approve', icon: 'check_circle' },
        { id: 'bulk-suspend', label: 'Suspend', icon: 'block', variant: 'danger' },
      ],
    };
  }

  /** Map an AxDataTable column key → the backend sort whitelist key. */
  private static readonly SORT_KEY_MAP: Record<string, string> = {
    store_name: 'name',
    emirate: 'emirate',
    country: 'country',
    last_login: 'updated_at',
    status: 'status',
  };

  /** v3 fetch → AxServerFetchResult. Maps vendor fields to VendorRow. */
  private fetchVendors(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    // Single-column server sort, translated to the API's whitelist keys.
    if (query.sort.length > 0) {
      const mapped = StoresComponent.SORT_KEY_MAP[query.sort[0].key];
      if (mapped) {
        q.sort = mapped;
        q.dir = query.sort[0].direction;
      }
    }

    // Filters → query params (only applied when set).
    const f = this.filters;
    if (f.status) q.status = f.status;
    if (f.is_active) q.is_active = f.is_active;
    if (f.is_verified) q.is_verified = f.is_verified;
    if (f.is_featured) q.is_featured = f.is_featured;
    if (f.emirate) q.emirate = f.emirate;
    if (f.created_from) q.created_from = f.created_from;
    if (f.created_to) q.created_to = f.created_to;

    return this.adapter.get_v3('GET /admin/vendors', { query: q }).pipe(
      map((response: any): AxServerFetchResult<VendorRow> => {
        const raw: any[] = response?.vendors ?? response?.data ?? [];
        const rows: VendorRow[] = raw.map((v: any) => ({
          id: v.id,
          store_name: v.name,
          store_email: v.contact_email ?? '',
          store_phone: v.contact_phone ?? '',
          emirate: v.emirate ?? '',
          country: v.country ?? '',
          last_login: v.updated_at ?? '',
          status: v.is_active === true,
          approved: v.status === 'approved',
        }));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load stores at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<VendorRow>);
      }),
    );
  }

  // ── Filter panel ─────────────────────────────────────────────────────
  /** Re-run the listing with the current filter panel. */
  applyFilters() {
    this.dataSource.retry();
  }

  /** Clear all filters and reload. */
  clearFilters() {
    this.filters = {
      status: '',
      is_active: '',
      is_verified: '',
      is_featured: '',
      emirate: '',
      created_from: '',
      created_to: '',
    };
    this.dataSource.retry();
  }

  // ── Table event handlers ─────────────────────────────────────────────
  /** Whole-row click opens the vendor's management page (per-row action
   *  buttons were removed in favour of a clickable row + bulk actions). */
  onRowClick(row: VendorRow) {
    this.router.navigate(['/admin/stores', row.id], { queryParams: { name: row.store_name } });
  }

  onBulkAction(e: { action: { id: string }; selection: VendorRow[] }) {
    const ids = e.selection.map((r) => r.id);
    if (e.action.id === 'bulk-approve') {
      this.confirm.confirm({
        title: 'Approve stores',
        message: `Approve ${ids.length} selected store(s)?`,
        confirmLabel: 'Approve', cancelLabel: 'Cancel',
      }).then((ok) => { if (ok) ids.forEach((id) => this.approve(id)); });
    } else if (e.action.id === 'bulk-suspend') {
      this.confirm.confirm({
        title: 'Suspend stores',
        message: `Suspend ${ids.length} selected store(s)?`,
        confirmLabel: 'Suspend', cancelLabel: 'Cancel', variant: 'danger',
      }).then((ok) => { if (ok) ids.forEach((id) => this.suspend(id)); });
    }
  }

  private confirmApprove(row: VendorRow) {
    this.confirm.confirm({
      title: 'Approve store', message: `${row.store_name} will be approved.`,
      confirmLabel: 'Approve', cancelLabel: 'Cancel',
    }).then((ok) => { if (ok) this.approve(row.id); });
  }

  private confirmSuspend(row: VendorRow) {
    this.confirm.confirm({
      title: 'Suspend store', message: `${row.store_name} will be suspended.`,
      confirmLabel: 'Suspend', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => { if (ok) this.suspend(row.id); });
  }

  private confirmDelete(row: VendorRow) {
    this.confirm.confirm({
      title: 'Delete store', message: `${row.store_name} will be deleted.`,
      confirmLabel: 'Delete', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => { if (ok) this.remove(row.id); });
  }

  private approve(id: number) {
    this.adapter.post_v3('POST /admin/vendors/:id/approve', {}, { params: { id: String(id) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('Store approved.'); this.refresh(); } } });
  }

  private suspend(id: number) {
    this.adapter.post_v3('POST /admin/vendors/:id/suspend', {}, { params: { id: String(id) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('Store suspended.'); this.refresh(); } } });
  }

  private remove(id: number) {
    this.adapter.delete_v3('DELETE /admin/vendors/:id', { params: { id: String(id) } })
      .subscribe({ next: (r: any) => { if (r) { this.toast.success('Store deleted.'); this.refresh(); } } });
  }

  private refresh() { this.dataSource.retry(); }

  private openTab(path: string, id: number, name: string) {
    this.router.navigate([path], { queryParams: { id, name } });
  }

  goBack() { this.router.navigate(['/backend']); }
}
