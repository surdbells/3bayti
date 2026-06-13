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

interface ReviewRow extends Record<string, unknown> {
  id: number;
  product_name: string;
  customer_name: string;
  rating: number;
  comment: string;
  created: string;
}

@Component({
  selector: 'app-vendor-reviews',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, AxDataTableComponent, AxCellDirective],
  templateUrl: './vendor-reviews.component.html',
  styleUrl: './vendor-reviews.component.css',
})
export class VendorReviewsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  config!: AxDataTableConfig<ReviewRow>;
  dataSource!: AxServerDataSource<ReviewRow>;

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
    this.dataSource = new AxServerDataSource<ReviewRow>((q) => this.fetchReviews(q));
    this.config = {
      tableId: 'vendor-reviews',
      mode: 'server',
      rowId: 'id',
      pageSize: 20,
      pageSizeOptions: [20, 50, 100],
      globalSearch: true,
      searchPlaceholder: 'Search reviews by product or customer…',
      stickyHeader: true,
      hover: true,
      emptyTitle: 'No reviews yet',
      emptyDescription: 'Your products have not received any reviews yet.',
      export: { enabled: true, formats: ['csv', 'xlsx'], filename: 'reviews' },
      filters: [
        {
          key: 'rating', label: 'Rating', type: 'select',
          options: [
            { label: '5 stars', value: 5 },
            { label: '4 stars', value: 4 },
            { label: '3 stars', value: 3 },
            { label: '2 stars', value: 2 },
            { label: '1 star', value: 1 },
          ],
        },
      ],
      columns: [
        { key: 'product_name', label: 'Product', sortable: true, sticky: 'left', width: '14rem' },
        { key: 'customer_name', label: 'Customer', hideOnMobile: true },
        { key: 'rating', label: 'Rating', align: 'center' },
        { key: 'comment', label: 'Comment' },
        { key: 'created', label: 'Date', hideOnMobile: true,
          format: (v) => (v ? new Date(String(v)).toLocaleDateString() : '—') },
      ],
    };
  }

  private fetchReviews(query: AxQueryState) {
    const q: any = {
      limit: query.pageSize,
      offset: query.pageIndex * query.pageSize,
    };
    if (query.search) q.search = query.search;
    if (query.filters['rating']) q.rating = query.filters['rating'];
    return this.adapter.get_v3('GET /vendor/reviews', { query: q }).pipe(
      map((response: any): AxServerFetchResult<ReviewRow> => {
        const raw: any[] = response?.data ?? [];
        const rows = raw.map((r) => ({
          ...r,
          id: r.id,
          product_name: r.product_name ?? '—',
          customer_name: r.reviewer ?? r.customer_name ?? '—',
          rating: r.star ?? r.rating ?? 0,
          comment: r.comment ?? r.title ?? '',
          created: r.created_at ?? '',
        } as ReviewRow));
        return { rows, total: response?.meta?.total ?? rows.length };
      }),
      catchError(() => {
        this.toast.error('Unable to load reviews at this time.');
        return of({ rows: [], total: 0 } as AxServerFetchResult<ReviewRow>);
      }),
    );
  }

  ratingStars(rating: number): { filled: number[]; empty: number[] } {
    const r = Math.max(0, Math.min(5, Math.round(rating)));
    return {
      filled: Array(r).fill(0),
      empty: Array(5 - r).fill(0),
    };
  }

  goBack() { this.router.navigate(['/account']); }
}
