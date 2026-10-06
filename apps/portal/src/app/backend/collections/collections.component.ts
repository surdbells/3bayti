import { Component, OnInit } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxConfirmService } from '../../shared/overlays/ax-confirm.service';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import { PermissionService } from '../../services/permission.service';
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
  display_order: number | null;
}

/**
 * Admin "Collections" list — admin-curated storefront collections. Each row
 * opens the edit page (fields + product curation) by its v3 id; delete removes
 * the collection and its curated product list (the products themselves stay).
 */
@Component({
  selector: 'app-collections',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, RouterLink, AxDataTableComponent, AxCellDirective, IconComponent, AxCanDirective],
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
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private confirm: AxConfirmService,
    private perms: PermissionService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(
      sessionStorage.getItem('SESSION') ?? '',
    );
    this.buildTable();
  }

  private buildTable() {
    const canManage = () => this.perms.can('catalog.collections_manage');
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
      emptyDescription: 'Create your first collection, then add the products it should show on the storefront.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'collections' },
      columns: [
        { key: 'label', label: 'Name', sortable: true, sticky: 'left', width: '20rem' },
        {
          key: 'display_order', label: 'Display order', align: 'center', hideOnMobile: true,
          value: (r) => (r.display_order != null ? String(r.display_order) : '—'),
        },
        { key: 'is_active', label: 'Status', align: 'center', value: (r) => (r.is_active ? 'Active' : 'Inactive') },
      ],
      rowActions: [
        { id: 'edit', label: 'Edit', icon: 'edit', can: canManage },
        { id: 'delete', label: 'Delete', icon: 'delete', variant: 'danger', can: canManage },
      ],
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
          display_order: c.display_order ?? null,
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
    switch (e.action.id) {
      case 'edit':
        this.router.navigate(['/admin/collections/edit'], { queryParams: { id: e.row.id } });
        return;
      case 'delete':
        this.confirmDelete(e.row);
        return;
    }
  }

  private confirmDelete(row: CollectionRow): void {
    this.confirm.confirm({
      title: 'Delete collection',
      message: `Delete collection "${row.label}"? Its product list is removed; the products themselves are not deleted.`,
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
      variant: 'danger',
    }).then((ok) => { if (ok) this.deleteCollection(row); });
  }

  private deleteCollection(row: CollectionRow): void {
    this.adapter.delete_v3('DELETE /admin/collections/:id', { params: { id: String(row.id) } }).subscribe({
      next: () => {
        this.toast.success(`Collection "${row.label}" deleted.`);
        this.dataSource.retry();
      },
      error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to delete the collection.')),
    });
  }

  goBack() { this.navHistory.back('/backend'); }
}
