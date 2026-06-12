import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface ReturnRow extends Record<string, unknown> {
  id: number;
  name: string;
  product: string;
  quantity: number;
  total_price: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-vendor-returns',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './vendor-returns.component.html',
  styleUrl: './vendor-returns.component.css',
})
export class VendorReturnsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<ReturnRow>;
  dataSource!: AxServerDataSource<ReturnRow>;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<ReturnRow>((q) => this.fetchReturns(q));
    this.config = {
      tableId: 'vendor-returns',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search returns by product or customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No returns',
      emptyDescription: 'You have no return requests yet.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'returns' },
      columns: [
        { key: 'created', label: 'Date', sortable: true, sticky: 'left', width: '11rem',
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
        { key: 'product', label: 'Product' },
        { key: 'name', label: 'Customer', hideOnMobile: true },
        { key: 'quantity', label: 'Qty', align: 'center', hideOnMobile: true },
        { key: 'total_price', label: 'Total', align: 'right',
          format: (v) => (v != null ? `AED ${Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : '—') },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [
        { id: 'open', label: 'View order', icon: 'visibility' },
      ],
    };
  }

  private fetchReturns(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /vendor/returns', { query: q }).pipe(
      map((response: any): AxServerFetchResult<ReturnRow> => {
        const raw: any[] = response?.data ?? [];
        return { rows: raw as ReturnRow[], total: response?.meta?.total ?? raw.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load returns at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<ReturnRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: ReturnRow }) {
    if (e.action.id === 'open') {
      this.router.navigate(['/', 'order'], { queryParams: { id: e.row.id, name: e.row.product } });
    }
  }

  statusBadgeClass(status: string): string {
    const s = (status || '').toLowerCase();
    if (s.includes('approved') || s.includes('complete')) return 'ax-badge ax-badge-success';
    if (s.includes('reject')) return 'ax-badge ax-badge-danger';
    if (s.includes('request')) return 'ax-badge ax-badge-warning';
    return 'ax-badge ax-badge-neutral';
  }

  goBack() { this.router.navigate(['/account']); }
}
