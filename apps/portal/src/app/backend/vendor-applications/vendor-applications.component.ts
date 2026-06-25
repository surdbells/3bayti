import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

interface VendorApplicationRow {
  id: number;
  name: string;
  business: string;
  email: string;
  phone: string;
  status: string;
  submitted_at: string;
}

@Component({
  selector: 'app-vendor-applications',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, IconComponent, TranslatePipe],
  templateUrl: './vendor-applications.component.html',
  styleUrl: './vendor-applications.component.css',
})
export class VendorApplicationsComponent implements OnInit {
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  rows: VendorApplicationRow[] = [];
  loading = false;
  /** id of the row currently being approved/rejected, to disable its buttons. */
  busyId: number | null = null;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private i18n: I18nService,
  ) {}

  ngOnInit() {
    this.user_session = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.load();
  }

  load() {
    this.loading = true;
    this.adapter.get_v3('GET /admin/vendor-applications', { query: { limit: 200, offset: 0 } }).subscribe({
      next: (response: any) => {
        const raw: any[] = Array.isArray(response?.data) ? response.data : response?.data?.items ?? [];
        this.rows = raw.map((a) => ({
          id: a.id,
          name: [a.first_name, a.last_name].filter(Boolean).join(' ') || '—',
          business: a.business ?? a.business_name ?? a.legal_name ?? '—',
          email: a.email ?? a.contact_email ?? '—',
          phone: a.phone ?? a.contact_phone ?? '—',
          status: a.status ?? 'pending',
          submitted_at: a.submitted_at ?? a.created_at ?? '',
        } as VendorApplicationRow));
        this.loading = false;
      },
      error: () => {
        this.toast.error(this.i18n.t('vendor_applications.load_error'));
        this.rows = [];
        this.loading = false;
      },
    });
  }

  approve(row: VendorApplicationRow) {
    if (this.busyId !== null) return;
    this.busyId = row.id;
    this.adapter.post_v3('POST /admin/vendor-applications/:id/approve', {}, { params: { id: row.id } }).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('vendor_applications.approve_success', { name: row.business }));
        this.busyId = null;
        this.load();
      },
      error: () => {
        this.toast.error(this.i18n.t('vendor_applications.approve_error'));
        this.busyId = null;
      },
    });
  }

  reject(row: VendorApplicationRow) {
    if (this.busyId !== null) return;
    const reason = (window.prompt(this.i18n.t('vendor_applications.reject_prompt')) ?? '').trim();
    if (!reason) return;
    this.busyId = row.id;
    this.adapter.post_v3('POST /admin/vendor-applications/:id/reject', { reason }, { params: { id: row.id } }).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('vendor_applications.reject_success', { name: row.business }));
        this.busyId = null;
        this.load();
      },
      error: () => {
        this.toast.error(this.i18n.t('vendor_applications.reject_error'));
        this.busyId = null;
      },
    });
  }

  statusBadgeClass(status: string): string {
    switch ((status || '').toLowerCase()) {
      case 'approved': return 'ax-badge-success';
      case 'rejected': return 'ax-badge-danger';
      default: return 'ax-badge-warning';
    }
  }

  formatDate(iso: string): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  isPending(row: VendorApplicationRow): boolean {
    return (row.status || '').toLowerCase() === 'pending';
  }

  goBack() { this.router.navigate(['/backend']); }
}
