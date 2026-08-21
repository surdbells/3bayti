import { Component, OnInit } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
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
import { apiErrorMessage } from '../../shared/http/api-error';

interface CampaignRow extends Record<string, unknown> {
  id: number;
  title: string;
  type: string;
  discount_percent: number;
  is_active: boolean;
  is_live: boolean;
  item_count: number;
  ends_at: string;
}

@Component({
  selector: 'app-campaigns',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, RouterLink, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './campaigns.component.html',
  styleUrl: './campaigns.component.css',
})
export class CampaignsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<CampaignRow>;
  dataSource!: AxServerDataSource<CampaignRow>;

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.buildTable();
  }

  private buildTable() {
    this.dataSource = new AxServerDataSource<CampaignRow>((q) => this.fetchCampaigns(q));
    this.config = {
      tableId: 'admin-campaigns',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: false,
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No campaigns',
      emptyDescription: 'Create an Anniversary or Flash Sale campaign to feature deals on the storefront homepage.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'campaigns' },
      columns: [
        { key: 'title', label: 'Campaign', sortable: false, sticky: 'left', width: '22rem' },
        { key: 'type', label: 'Type', align: 'center' },
        { key: 'discount_percent', label: 'Discount', align: 'center', value: (r) => `${r.discount_percent}%` },
        { key: 'is_live', label: 'Status', align: 'center' },
        { key: 'item_count', label: 'Products', align: 'center', value: (r) => String(r.item_count ?? 0) },
        { key: 'ends_at', label: 'Ends', align: 'center', value: (r) => this.formatDate(r.ends_at) },
      ],
      rowActions: [{ id: 'edit', label: 'Edit', icon: 'edit' }],
    };
  }

  private fetchCampaigns(query: AxQueryState) {
    const q: Record<string, string | number | boolean | undefined | null> = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    return this.adapter.get_v3('GET /admin/campaigns', { query: q }).pipe(
      map((response: any): AxServerFetchResult<CampaignRow> => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : response?.data?.items ?? [];
        const rows = raw.map((c) => ({
          ...c,
          id: c.id,
          title: c.title ?? '—',
          type: c.type ?? 'anniversary',
          discount_percent: c.discount_percent ?? 0,
          is_active: c.is_active ?? false,
          is_live: c.is_live ?? false,
          item_count: c.item_count ?? 0,
          ends_at: c.ends_at ?? '',
        } as CampaignRow));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load campaigns at this time.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<CampaignRow>);
      }),
    );
  }

  statusLabel(row: CampaignRow): string {
    if (row.is_live) return 'Live';
    if (!row.is_active) return 'Disabled';
    return 'Scheduled';
  }

  typeLabel(type: string): string {
    return type === 'flash' ? 'Flash Sale' : 'Anniversary';
  }

  private formatDate(iso: string): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  onRowAction(e: { action: { id: string }; row: CampaignRow }) {
    if (e.action.id === 'edit') {
      this.router.navigate(['/edit_campaign'], { queryParams: { id: e.row.id } });
    }
  }

  goBack() { this.navHistory.back('/backend'); }
}
