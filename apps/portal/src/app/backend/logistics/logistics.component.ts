import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import {
  AxDataTableComponent,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface LogisticRow extends Record<string, unknown> {
  store: number;
  store_name: string;
  store_address: string;
  store_email: string;
  store_phone: string;
}

@Component({
  selector: 'app-logistics',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, AxDataTableComponent],
  templateUrl: './logistics.component.html',
  styleUrl: './logistics.component.css',
})
export class LogisticsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<LogisticRow>;
  dataSource!: AxServerDataSource<LogisticRow>;

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
    this.dataSource = new AxServerDataSource<LogisticRow>((q) => this.fetchLogistics(q));
    this.config = {
      tableId: 'admin-logistics',
      mode: 'server',
      rowId: 'store',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search stores with shipments…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No shipments',
      emptyDescription: 'No stores currently have orders awaiting delivery.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'logistics' },
      columns: [
        { key: 'store_name', label: 'Store', sortable: true, sticky: 'left', width: '16rem' },
        { key: 'store_address', label: 'Location', hideOnMobile: true },
        { key: 'store_email', label: 'Email', hideOnMobile: true },
        { key: 'store_phone', label: 'Phone', hideOnMobile: true },
      ],
      rowActions: [{ id: 'deliveries', label: 'View deliveries', icon: 'local_shipping' }],
    };
  }

  private fetchLogistics(query: AxQueryState) {
    const q: any = {
      status: 'shipped',
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /admin/orders', { query: q }).pipe(
      map((response: any): AxServerFetchResult<LogisticRow> => {
        const raw: any[] = response?.orders ?? response?.data ?? [];
        // Group by store so each store appears once.
        const byStore = new Map<number, LogisticRow>();
        for (const o of raw) {
          const sid = o.store ?? o.vendor_id;
          if (sid != null && !byStore.has(sid)) {
            byStore.set(sid, {
              store: sid,
              store_name: o.store_name ?? '',
              store_address: o.store_address ?? o.country_code ?? '',
              store_email: o.store_email ?? o.contact_email ?? '',
              store_phone: o.store_phone ?? o.contact_phone ?? '',
            });
          }
        }
        const rows = [...byStore.values()];
        return { rows, total: response?.pagination?.total ?? response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load logistics at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<LogisticRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: LogisticRow }) {
    if (e.action.id === 'deliveries') {
      window.open('/deliveries?vendor=' + e.row.store, '_blank', 'width=900,height=800');
    }
  }

  goBack() { this.router.navigate(['/backend']); }
}
