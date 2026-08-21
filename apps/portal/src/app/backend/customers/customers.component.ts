import { Component, OnInit, inject } from '@angular/core';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
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
import { apiErrorMessage } from '../../shared/http/api-error';

interface CustomerRow extends Record<string, unknown> {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  last_login: string;
  status: boolean;
  is_email_verified: boolean;
  is_phone_verified: boolean;
  created_at: string;
  orders_count: number;
}

/** Filter-panel state, sent to GET /admin/users when present. */
interface CustomerFilters {
  status: '' | 'active' | 'inactive';
  email_verified: '' | 'true' | 'false';
  phone_verified: '' | 'true' | 'false';
  created_from: string;
  created_to: string;
  min_orders: string;
}

@Component({
  selector: 'app-customers',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent, TranslatePipe, AxComboboxComponent, TopPerformersComponent],
  templateUrl: './customers.component.html',
  styleUrl: './customers.component.css',
})
export class CustomersComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  private readonly i18n = inject(I18nService);
  private readonly navHistory = inject(NavigationHistoryService);
  private t(k: string): string { return this.i18n.t(k); }
  get statusFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('customers.filter_status_all') },
      { id: 'active', label: this.t('customers.status_active') },
      { id: 'inactive', label: this.t('customers.status_inactive') },
    ];
  }
  get emailVerifiedFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('customers.filter_all') },
      { id: 'true', label: this.t('customers.verified_yes') },
      { id: 'false', label: this.t('customers.verified_no') },
    ];
  }
  get phoneVerifiedFilterOptions(): AxComboboxOption[] {
    return [
      { id: '', label: this.t('customers.filter_all') },
      { id: 'true', label: this.t('customers.verified_yes') },
      { id: 'false', label: this.t('customers.verified_no') },
    ];
  }

  /** Registration-date + status/verification/min-orders filters. */
  filters: CustomerFilters = {
    status: '',
    email_verified: '',
    phone_verified: '',
    created_from: '',
    created_to: '',
    min_orders: '',
  };

  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<CustomerRow>;
  dataSource!: AxServerDataSource<CustomerRow>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  topCustomers: TopPerformer[] = [];
  topCustomersLoading = true;

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
    this.loadTopCustomers();
  }

  /** Top-performing customers carousel (by number of purchases). */
  private loadTopCustomers(): void {
    this.topCustomersLoading = true;
    this.adapter.get_v3('GET /admin/top-customers').pipe(
      map((res: any) => (res?.data ?? []) as any[]),
      catchError(() => of([] as any[])),
    ).subscribe((rows) => {
      this.topCustomers = rows.map((r) => ({
        id: r.id,
        rank: r.rank,
        name: r.name || `${r.first_name ?? ''} ${r.last_name ?? ''}`.trim() || 'Customer',
        value: r.purchases_count,
        imageUrl: r.avatar_url ?? null,
      }));
      this.topCustomersLoading = false;
    });
  }

  onTopCustomerSelect(it: TopPerformer): void {
    this.router.navigate(['/admin/customers', it.id], { queryParams: { name: it.name } });
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<CustomerRow>(
      (q) => this.fetchCustomers(q),
      // Short debounce so filter selections apply (near-)instantly while
      // still coalescing rapid search keystrokes.
      120,
    );
    this.config = {
      tableId: 'admin-customers',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search customers by name or email…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No customers found',
      emptyDescription: 'No customers match your current filters.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'customers' },
      columns: [
        { key: 'name', label: 'Customer', sortable: true, sticky: 'left', width: '14rem',
          value: (r) => `${r.first_name} ${r.last_name}` },
        { key: 'email', label: 'Email' },
        { key: 'phone', label: 'Phone', hideOnMobile: true },
        { key: 'created_at', label: 'Registered', hideOnMobile: true, sortable: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
        { key: 'orders_count', label: 'Orders', align: 'center',
          value: (r) => String(r.orders_count ?? 0) },
        { key: 'last_login', label: 'Last login', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleString() : '—') },
        { key: 'status', label: 'Status', align: 'center',
          value: (r) => (r.status ? 'Active' : 'Inactive') },
      ],
      rowActions: [
        { id: 'view', label: 'View customer', icon: 'eye' },
        { id: 'activate', label: 'Activate', icon: 'check',
          hidden: (r) => r.status === true },
        { id: 'deactivate', label: 'Deactivate', icon: 'block', variant: 'danger',
          hidden: (r) => r.status !== true },
      ],
    };
  }

  /** Row-level quick actions (the row click still opens the detail view). */
  onRowAction(e: { action: { id: string }; row: CustomerRow }) {
    switch (e.action.id) {
      case 'view': return this.onRowClick(e.row);
      case 'activate': return this.startActivate(e.row);
      case 'deactivate': return this.startDeactivate(e.row);
    }
  }

  private fetchCustomers(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
      role: 'customer',
    };
    if (query.search) q.search = query.search;

    // Apply the filter panel — only send params the user actually set.
    const f = this.filters;
    if (f.status) q.status = f.status;
    if (f.email_verified) q.email_verified = f.email_verified;
    if (f.phone_verified) q.phone_verified = f.phone_verified;
    if (f.created_from) q.created_from = f.created_from;
    if (f.created_to) q.created_to = f.created_to;
    if (f.min_orders && Number(f.min_orders) > 0) q.min_orders = f.min_orders;

    return this.adapter.get_v3('GET /admin/users', { query: q }).pipe(
      map((response: any): AxServerFetchResult<CustomerRow> => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : response?.data?.items ?? [];
        const rows = raw.map((u) => this.mapCustomer(u));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load customers at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<CustomerRow>);
      }),
    );
  }

  /** Map the admin customer() shape (phone, country_code, is_active, verification flags). */
  private mapCustomer(u: any): CustomerRow {
    return {
      id: u.id,
      first_name: u.first_name ?? '',
      last_name: u.last_name ?? '',
      email: u.email ?? '',
      phone: this.formatPhone(u.phone, u.country_code),
      last_login: u.last_login_at ?? u.last_login ?? '',
      status: u.is_active ?? true,
      is_email_verified: u.is_email_verified ?? false,
      is_phone_verified: u.is_phone_verified ?? false,
      created_at: u.created_at ?? '',
      orders_count: u.orders_count ?? 0,
    } as CustomerRow;
  }

  private static readonly DIAL_CODES: Record<string, string> = {
    AE: '+971', SA: '+966', KW: '+965', QA: '+974', BH: '+973', OM: '+968',
  };

  /**
   * Render a phone as clean E.164. New signups already store '+971…'; legacy-
   * migrated rows store a local number (optional leading 0) with the ISO
   * country_code, which the list previously glued together as "AE0506995999".
   */
  private formatPhone(phone: string | null | undefined, countryCode: string | null | undefined): string {
    const raw = (phone ?? '').trim();
    if (!raw) { return ''; }
    if (raw.startsWith('+')) { return raw; }
    const dial = CustomersComponent.DIAL_CODES[(countryCode ?? '').toUpperCase()] ?? '+971';
    const local = raw.replace(/\D/g, '').replace(/^0+/, '');
    return local ? `${dial}${local}` : raw;
  }

  /** Re-run the listing with the current filter panel (resets to page 1). */
  applyFilters() {
    this.dataSource.retry();
  }

  /** Clear all filters and reload. */
  clearFilters() {
    this.filters = {
      status: '',
      email_verified: '',
      phone_verified: '',
      created_from: '',
      created_to: '',
      min_orders: '',
    };
    this.dataSource.retry();
  }

  /** Whole-row click opens the customer detail view (profile + orders).
   *  The list is otherwise action-free; account status is managed there. */
  onRowClick(row: CustomerRow) {
    this.router.navigate(['/admin/customers', row.id], {
      queryParams: {
        name: `${row.first_name} ${row.last_name}`.trim(),
        active: row.status,
      },
    });
  }

  private refresh() { this.dataSource.retry(); }

  private startActivate(row: CustomerRow) {
    this.confirm.confirm({
      title: 'Activate customer', message: `${row.first_name}'s account will be activated.`,
      confirmLabel: 'Activate', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (ok) this.adapter.post_v3('POST /admin/users/:id/activate', {}, { params: { id: String(row.id) } })
        .subscribe({ next: (r: any) => { if (r) { this.toast.success('Customer activated.'); this.refresh(); } } });
    });
  }

  private startDeactivate(row: CustomerRow) {
    this.confirm.confirm({
      title: 'Deactivate customer', message: `${row.first_name} will be deactivated.`,
      confirmLabel: 'Deactivate', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => {
      if (ok) this.adapter.post_v3('POST /admin/users/:id/deactivate', {}, { params: { id: String(row.id) } })
        .subscribe({ next: (r: any) => { if (r) { this.toast.success('Customer deactivated.'); this.refresh(); } } });
    });
  }

  goBack() { this.navHistory.back('/backend'); }
}
