import { Component, OnInit, HostListener, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

import { NavigationHistoryService } from '../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { AxConfirmService } from '../../shared/overlays/ax-confirm.service';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import {
  AxDataTableComponent,
  AxCellDirective,
  AxServerDataSource,
  type AxDataTableConfig,
  type AxQueryState,
  type AxServerFetchResult,
} from '../../shared/data/enterprise';

interface CustomizationRow extends Record<string, unknown> {
  id: number;
  product: string;
  productSlug: string;
  status: string;
  notes: string;
  customer_notes: string;
  created: string;
  quote: { amount: string; currency: string | null; lead_time_days: number | null; vendor_notes: string | null } | null;
  measurement: Record<string, unknown> | null;
}

@Component({
  selector: 'app-vendor-customization',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, FormsModule, AxDataTableComponent, AxCellDirective, IconComponent],
  templateUrl: './vendor-customization.component.html',
  styleUrl: './vendor-customization.component.css',
})
export class VendorCustomizationComponent implements OnInit {
  config!: AxDataTableConfig<CustomizationRow>;
  dataSource!: AxServerDataSource<CustomizationRow>;

  /** The row whose "Manage" modal is open, or null. */
  readonly selected = signal<CustomizationRow | null>(null);
  readonly busy = signal<boolean>(false);

  /** Quote-form model (template-driven). */
  quoteForm = { amount: '', currency: 'AED', lead_time_days: null as number | null, vendor_notes: '' };

  constructor(
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private confirm: AxConfirmService,
  ) {}

  ngOnInit(): void {
    this.buildTable();
  }

  private buildTable(): void {
    this.dataSource = new AxServerDataSource<CustomizationRow>((q) => this.fetch(q));
    this.config = {
      tableId: 'vendor-customization',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: false,
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No customization requests',
      emptyDescription: 'When a customer requests a bespoke customization on one of your products, it appears here.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'customization-requests' },
      columns: [
        { key: 'created', label: 'Date', sortable: true, sticky: 'left', width: '11rem',
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
        { key: 'product', label: 'Product' },
        { key: 'notes', label: 'Request', hideOnMobile: true },
        { key: 'status', label: 'Status', align: 'center' },
      ],
      rowActions: [
        { id: 'manage', label: 'Manage', icon: 'edit' },
      ],
    };
  }

  private fetch(query: AxQueryState) {
    const q: Record<string, string | number> = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    return this.adapter.get_v3('GET /vendor/customization-requests', { query: q }).pipe(
      map((response: any): AxServerFetchResult<CustomizationRow> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((r) => this.mapRow(r));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError((err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load customization requests.'));
        return of({ rows: [], total: 0 } as AxServerFetchResult<CustomizationRow>);
      }),
    );
  }

  private mapRow(r: any): CustomizationRow {
    const notes = String(r.customer_notes ?? '');
    return {
      ...r,
      id: r.id,
      product: r.product?.name ?? `Request #${r.id}`,
      productSlug: r.product?.slug ?? '',
      status: r.status ?? '',
      customer_notes: notes,
      notes: notes.length > 60 ? `${notes.slice(0, 60)}…` : notes,
      created: r.timestamps?.requested_at ?? '',
      quote: r.quote ?? null,
      measurement: r.measurement_snapshot ?? null,
    } as CustomizationRow;
  }

  onRowAction(e: { action: { id: string }; row: CustomizationRow }): void {
    if (e.action.id === 'manage') {
      this.openManage(e.row);
    }
  }

  openManage(row: CustomizationRow): void {
    this.quoteForm = { amount: '', currency: 'AED', lead_time_days: null, vendor_notes: '' };
    this.selected.set(row);
  }

  closeManage(): void {
    if (this.busy()) return;
    this.selected.set(null);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.closeManage();
  }

  /** Send a quote for the selected (pending) request. */
  sendQuote(): void {
    const row = this.selected();
    if (row === null || this.busy()) return;

    const amount = this.quoteForm.amount.trim();
    if (!/^\d+(\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) {
      this.toast.error('Enter a valid amount greater than zero.');
      return;
    }

    const body: Record<string, unknown> = {
      amount,
      currency: (this.quoteForm.currency || 'AED').toUpperCase(),
    };
    if (this.quoteForm.lead_time_days !== null && this.quoteForm.lead_time_days !== undefined) {
      body['lead_time_days'] = Number(this.quoteForm.lead_time_days);
    }
    const notes = this.quoteForm.vendor_notes.trim();
    if (notes !== '') {
      body['vendor_notes'] = notes;
    }

    this.busy.set(true);
    this.adapter
      .post_v3('POST /vendor/customization-requests/:id/quote', body, { params: { id: String(row.id) } })
      .subscribe({
        next: () => {
          this.busy.set(false);
          this.toast.success('Quote sent to the customer.');
          this.selected.set(null);
          this.dataSource.retry();
        },
        error: (err: any) => {
          this.busy.set(false);
          this.toast.error(apiErrorMessage(err, 'Could not send the quote.'));
        },
      });
  }

  /** Decline the selected (pending) request. */
  decline(): void {
    const row = this.selected();
    if (row === null || this.busy()) return;
    this.confirm
      .confirm({
        title: 'Decline request',
        message: 'Decline this customization request? The customer will be notified. This cannot be undone.',
        confirmLabel: 'Decline',
        cancelLabel: 'Cancel',
        variant: 'danger',
      })
      .then((ok) => {
        if (!ok) return;
        const notes = this.quoteForm.vendor_notes.trim();
        const body = notes !== '' ? { vendor_notes: notes } : {};
        this.busy.set(true);
        this.adapter
          .post_v3('POST /vendor/customization-requests/:id/decline', body, { params: { id: String(row.id) } })
          .subscribe({
            next: () => {
              this.busy.set(false);
              this.toast.success('Request declined.');
              this.selected.set(null);
              this.dataSource.retry();
            },
            error: (err: any) => {
              this.busy.set(false);
              this.toast.error(apiErrorMessage(err, 'Could not decline the request.'));
            },
          });
      });
  }

  /** Mark the selected (paid) request as completed. */
  complete(): void {
    const row = this.selected();
    if (row === null || this.busy()) return;
    this.confirm
      .confirm({
        title: 'Mark completed',
        message: 'Mark this customization as completed? The customer will be notified it’s ready.',
        confirmLabel: 'Mark completed',
        cancelLabel: 'Cancel',
      })
      .then((ok) => {
        if (!ok) return;
        this.busy.set(true);
        this.adapter
          .post_v3('POST /vendor/customization-requests/:id/complete', {}, { params: { id: String(row.id) } })
          .subscribe({
            next: () => {
              this.busy.set(false);
              this.toast.success('Marked as completed.');
              this.selected.set(null);
              this.dataSource.retry();
            },
            error: (err: any) => {
              this.busy.set(false);
              this.toast.error(apiErrorMessage(err, 'Could not update the request.'));
            },
          });
      });
  }

  /** Measurement snapshot as an array of {label, value} for display. */
  measurementRows(row: CustomizationRow): Array<{ label: string; value: string }> {
    const m = row.measurement;
    if (m === null || typeof m !== 'object') return [];
    return Object.entries(m).map(([k, v]) => ({ label: k.replace(/_/g, ' '), value: String(v) }));
  }

  statusBadgeClass(status: string): string {
    const s = (status || '').toLowerCase();
    if (s === 'completed' || s === 'paid') return 'ax-badge ax-badge-success';
    if (s === 'declined' || s === 'rejected' || s === 'cancelled') return 'ax-badge ax-badge-danger';
    if (s === 'quoted' || s === 'accepted') return 'ax-badge ax-badge-warning';
    return 'ax-badge ax-badge-neutral';
  }

  goBack(): void {
    this.navHistory.back('/account');
  }
}
