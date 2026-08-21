import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { AxConfirmService } from '../../shared/overlays';

interface VendorApplicationRow {
  id: number;
  name: string;
  business: string;
  email: string;
  phone: string;
  status: string;
  submitted_at: string;
  // Full detail for the review modal.
  country_code: string;
  license_number: string;
  category: string;
  message: string;
  reject_reason: string;
  reviewed_at: string;
}

@Component({
  selector: 'app-vendor-applications',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, TranslatePipe],
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
  /** The application open in the review modal, or null when closed. */
  selected: VendorApplicationRow | null = null;
  /** Reject sub-state: when true the modal shows the reason input. */
  rejecting = false;
  rejectReason = '';

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private i18n: I18nService,
    private confirmDialog: AxConfirmService,
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
          country_code: a.country_code ?? '',
          license_number: a.license_number ?? '',
          category: a.category ?? '',
          message: a.message ?? '',
          reject_reason: a.reject_reason ?? '',
          reviewed_at: a.reviewed_at ?? '',
        } as VendorApplicationRow));
        // Keep the open modal in sync with refreshed data (status/reject reason).
        if (this.selected) {
          this.selected = this.rows.find((r) => r.id === this.selected!.id) ?? null;
        }
        this.loading = false;
      },
      error: () => {
        this.toast.error(this.i18n.t('vendor_applications.load_error'));
        this.rows = [];
        this.loading = false;
      },
    });
  }

  /** Open the review modal for a row. */
  openReview(row: VendorApplicationRow) { this.selected = row; this.rejecting = false; this.rejectReason = ''; }
  closeReview() { if (this.busyId === null) { this.selected = null; this.rejecting = false; this.rejectReason = ''; } }

  /** Enter / leave the inline "reason for rejection" step within the modal. */
  startReject() { this.rejecting = true; this.rejectReason = ''; }
  cancelReject() { this.rejecting = false; this.rejectReason = ''; }

  approve(row: VendorApplicationRow) {
    if (this.busyId !== null) return;
    this.busyId = row.id;
    this.adapter.post_v3('POST /admin/vendor-applications/:id/approve', {}, { params: { id: row.id } }).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('vendor_applications.approve_success', { name: row.business }));
        this.busyId = null;
        this.selected = null;
        this.load();
      },
      error: (err: any) => {
        this.toast.error(this.apiError(err, this.i18n.t('vendor_applications.approve_error')));
        this.busyId = null;
      },
    });
  }

  reject(row: VendorApplicationRow) {
    if (this.busyId !== null) return;
    const reason = this.rejectReason.trim();
    if (!reason) { this.toast.error('Please enter a reason for the rejection.'); return; }
    this.busyId = row.id;
    this.adapter.post_v3('POST /admin/vendor-applications/:id/reject', { reason }, { params: { id: row.id } }).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('vendor_applications.reject_success', { name: row.business }));
        this.busyId = null;
        this.rejecting = false;
        this.rejectReason = '';
        this.selected = null;
        this.load();
      },
      error: (err: any) => {
        this.toast.error(this.apiError(err, this.i18n.t('vendor_applications.reject_error')));
        this.busyId = null;
      },
    });
  }

  /** Re-issue login credentials + resend the welcome email for an approved app. */
  resend(row: VendorApplicationRow) {
    if (this.busyId !== null) return;
    // Close the review modal first: it's a full-screen overlay above the CDK
    // confirm layer, so the confirm would otherwise render beneath it.
    this.selected = null;
    this.confirmDialog.confirm({
      title: 'Resend login credentials?',
      message: `This resets ${row.business || 'this vendor'}'s password and emails ${row.email} `
        + `fresh login details. Any password they have already set will stop working.`,
      confirmLabel: 'Resend credentials',
      cancelLabel: 'Cancel',
      variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.busyId = row.id;
      // Guard against a synchronous adapter throw (e.g. a missing route entry)
      // wedging busyId — which would leave the modal stuck and undismissable.
      try {
        this.adapter.post_v3('POST /admin/vendor-applications/:id/resend-credentials', {}, { params: { id: row.id } }).subscribe({
          next: () => {
            this.toast.success(`Login credentials emailed to ${row.email}.`);
            this.busyId = null;
          },
          error: (err: any) => {
            this.toast.error(this.apiError(err, 'Could not resend credentials. Please try again.'));
            this.busyId = null;
          },
        });
      } catch (err: any) {
        this.toast.error(this.apiError(err, 'Could not resend credentials. Please try again.'));
        this.busyId = null;
      }
    });
  }

  isApproved(row: VendorApplicationRow): boolean {
    return (row.status || '').toLowerCase() === 'approved';
  }

  /** First field-level validation message, else top-level message, else fallback. */
  private apiError(err: any, fallback: string): string {
    const body = err?.error?.error ?? err?.error;
    const details = body?.details;
    if (details && typeof details === 'object') {
      for (const key of Object.keys(details)) {
        const v = (details as Record<string, unknown>)[key];
        const msg = Array.isArray(v) ? v[0] : v;
        if (msg) return String(msg);
      }
    }
    return body?.message || fallback;
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

  goBack() { this.navHistory.back('/backend'); }
}
