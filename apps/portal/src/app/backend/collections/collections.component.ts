import { Component, OnInit } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { GlobalComponent } from '../../global-component';
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

interface CollectionRow extends Record<string, unknown> {
  id: number;
  label: string;
  is_active: boolean;
}

@Component({
  selector: 'app-collections',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, RouterLink, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './collections.component.html',
  styleUrl: './collections.component.css',
})
export class CollectionsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<CollectionRow>;
  dataSource!: AxServerDataSource<CollectionRow>;

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
    this.dataSource = new AxServerDataSource<CollectionRow>((q) => this.fetchCollections(q));
    this.config = {
      tableId: 'admin-collections',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search collections…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No collections',
      emptyDescription: 'Create your first collection to group products.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'collections' },
      columns: [
        { key: 'label', label: 'Name', sortable: true, sticky: 'left', width: '20rem' },
        { key: 'is_active', label: 'Status', align: 'center', value: (r) => (r.is_active ? 'Active' : 'Inactive') },
      ],
      rowActions: [{ id: 'edit', label: 'Edit', icon: 'edit' }],
    };
  }

  private fetchCollections(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    return this.adapter.get_v3('GET /admin/collections', { query: q }).pipe(
      map((response: any): AxServerFetchResult<CollectionRow> => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : response?.data?.items ?? [];
        const rows = raw.map((c) => ({
          ...c,
          id: c.id,
          label: c.name ?? c.collection ?? '—',
          is_active: c.is_active ?? false,
        } as CollectionRow));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load collections at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<CollectionRow>);
      }),
    );
  }

  onRowAction(e: { action: { id: string }; row: CollectionRow }) {
    if (e.action.id === 'edit') {
      this.router.navigate(['/edit_collection'], {
        queryParams: { id: e.row.id, label: e.row.label, active: e.row.is_active },
      });
    }
  }

  goBack() { this.router.navigate(['/backend']); }
}
