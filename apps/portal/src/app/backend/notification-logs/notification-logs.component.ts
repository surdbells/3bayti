import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { HotToastService } from '@ngneat/hot-toast';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { GlobalComponent } from '../../global-component';
import {
  AxTableComponent, AxColumnComponent,
  AxEmptyStateComponent, AxSkeletonComponent, AxPaginationComponent,
} from '../../shared/data';

const V3 = 'https://api-v3.3bayti.ae';

interface NotificationLog {
  id: number;
  order_id: number | null;
  template: string;
  recipient: string;
  status: 'sent' | 'failed';
  sent_at: string;
  error_kind: string | null;
  error_message: string | null;
  created_at: string;
}

@Component({
  selector: 'app-notification-logs',
  standalone: true,
  imports: [
    CommonModule, FormsModule,
    AdminShellComponent,
    AxTableComponent, AxColumnComponent,
    AxEmptyStateComponent, AxSkeletonComponent, AxPaginationComponent,
  ],
  templateUrl: './notification-logs.component.html',
  styleUrl: './notification-logs.component.css',
})
export class NotificationLogsComponent implements OnInit {
  logs: NotificationLog[] = [];
  total = 0;
  loading = false;
  error = '';

  filters = { status: '', template: '', order_id: '' };
  pageSize = 20;
  pageIndex = 0;

  get totalPages(): number { return Math.ceil(this.total / this.pageSize); }

  private token = '';

  constructor(private http: HttpClient, private toast: HotToastService) {}

  ngOnInit(): void {
    const raw = sessionStorage.getItem('SESSION');
    if (raw) this.token = GlobalComponent.decodeBase64(raw)?.token ?? '';
    this.load();
  }

  load(): void {
    this.loading = true; this.error = '';
    const params = new URLSearchParams({
      limit: String(this.pageSize),
      offset: String(this.pageIndex * this.pageSize),
    });
    if (this.filters.status)   params.set('status',   this.filters.status);
    if (this.filters.template) params.set('template', this.filters.template);
    if (this.filters.order_id) params.set('order_id', this.filters.order_id);
    const headers = new HttpHeaders({ Authorization: `Bearer ${this.token}` });
    this.http.get<{ data: NotificationLog[]; meta: { total: number } }>(
      `${V3}/v3/admin/notification-logs?${params}`, { headers }
    ).subscribe({
      next: (res) => {
        this.logs = res.data ?? [];
        this.total = res.meta?.total ?? 0;
        this.loading = false;
      },
      error: () => {
        this.error = 'Could not load notification logs.';
        this.loading = false;
        this.toast.error('Failed to load logs');
      },
    });
  }

  applyFilters(): void { this.pageIndex = 0; this.load(); }
  clearFilters(): void { this.filters = { status: '', template: '', order_id: '' }; this.pageIndex = 0; this.load(); }
  onPage(index: number): void { this.pageIndex = index; this.load(); }
}
