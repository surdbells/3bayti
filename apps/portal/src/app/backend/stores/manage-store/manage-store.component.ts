import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { ActivatedRoute, Router } from '@angular/router';
import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { FormsModule } from '@angular/forms';

import { AccountSetupComponent } from '../account-setup/account-setup.component';

// Ax design system
import { AxRichEditorComponent } from '../../../shared/rich/ax-rich-editor.component';
import { AxTabsComponent, AxTabComponent, AxConfirmService } from '../../../shared/overlays';

import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AxComboboxComponent, AxComboboxOption } from '../../../shared/forms/ax-combobox.component';
import { AxCanDirective } from '../../../shared/security/ax-can.directive';
import { ImpersonationService } from '../../../services/impersonation.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
@Component({
  selector: 'app-manage-store',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AccountSetupComponent,
    AxRichEditorComponent,
    AxTabsComponent,
    AxTabComponent, IconComponent, AxComboboxComponent, AxCanDirective],
  templateUrl: './manage-store.component.html',
  styleUrl: './manage-store.component.css',
})
export class ManageStoreComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  private readonly impersonation = inject(ImpersonationService);
  private readonly navHistory = inject(NavigationHistoryService);

  ui_controls = {
    is_loading: false,
    no_data: false,
    sending_message: false,
    nav_open: false,
    acting: false,
    saving: false,
  };

  private storeId = 0;

  session_data: any = '';
  store_name: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  message = {
    id: 0, token: '',
    name: '', email: '',
    subject: '', message: '',
  };

  get_data = { id: 0, token: '', store: 0 };

  /** The seven UAE emirates for the location select. */
  readonly emirates = [
    'Dubai', 'Abu Dhabi', 'Sharjah', 'Ajman',
    'Umm Al Quwain', 'Ras Al Khaimah', 'Fujairah',
  ];
  get emirateOptions(): AxComboboxOption[] {
    return this.emirates.map((e) => ({ id: e, label: e }));
  }

  store = {
    id: 0, token: '',
    first_name: '', last_name: '', email: '', phone: '',
    avatar: '', id_front: '', id_back: '', is_active: '',
    license_doc: '', store_name: '', store_status: false,
    approved: false, store_email: '', store_phone: '',
    store_address: '', store_description: '',
    emirate: '', country: '',
    vat_status: '', store_legal_name: '', trade_license_number: '',
    licensing_authority: '', tax_registration_number: '',
    vat_registration_effective_date: '', registered_tax_address: '',
    tax_contact_email: '',
    store_bank_name: '', store_account_name: '', store_account_number: '',
    last_login: '',
  };

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private sanitizer: DomSanitizer,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    const storeId = Number(this.route.snapshot.paramMap.get('id') ?? this.route.snapshot.queryParamMap.get('id'));
    this.store_name = this.route.snapshot.queryParamMap.get('name');
    this.storeId = storeId || 0;

    this.store.id = this.user_session.id;
    this.store.token = this.user_session.token;
    this.get_data.store = storeId || 0;
    this.get_data.id = this.user_session.id;
    this.get_data.token = this.user_session.token;

    this.message.id = this.user_session.id;
    this.message.token = this.user_session.token;

    this.get_store();
  }

  goBack() {
    this.navHistory.back('/stores');
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_store() {
    this.ui_controls.is_loading = true;
    const msVId = this.get_data.store ?? this.get_data.id;
    this.adapter.get_v3('GET /admin/vendors/:id', { params: { id: String(msVId) } }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.store = response.data;
          this.message.name = this.store.first_name;
          this.message.email = this.store.email;
          this.applyComplianceDocUrls();
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
    this.loadCompliance();
  }

  /**
   * Persist the store's emirate + country via PUT /admin/vendors/{id}.
   * name + contact_email are required by the update endpoint, so we
   * echo the current values back alongside the location fields.
   */
  saveLocation() {
    if (!this.storeId) return;
    this.ui_controls.saving = true;
    const body = {
      name: this.store.store_name,
      contact_email: this.store.store_email || this.store.email,
      emirate: this.store.emirate || null,
      country: this.store.country || null,
    };
    this.adapter.put_v3('PUT /admin/vendors/:id', body, { params: { id: String(this.storeId) } })
      .subscribe({
        next: (r: any) => {
          if (r) {
            this.toast.success('Store location updated.');
            if (r?.vendor) {
              this.store.emirate = r.vendor.emirate ?? this.store.emirate;
              this.store.country = r.vendor.country ?? this.store.country;
            }
          }
          this.ui_controls.saving = false;
        },
        error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to update store location.')); this.ui_controls.saving = false; },
      });
  }

  // ── KYC compliance review ───────────────────────────────────────────
  compliance: any = {
    front: null, back: null, license_doc: null,
    compliance_status: 'pending', reviewed_at: null, reviewed_by: null, review_note: null,
  };
  reject_note = '';

  /** The vendor's submitted identity documents, for the KYC grid + viewer. */
  get documents(): { key: string; label: string; url: string | null }[] {
    return [
      { key: 'front', label: 'ID — front', url: this.compliance?.front ?? null },
      { key: 'back', label: 'ID — back', url: this.compliance?.back ?? null },
      { key: 'license_doc', label: 'Trade licence', url: this.compliance?.license_doc ?? null },
    ];
  }

  /** Full-screen document viewer (view a submitted ID in full, never download). */
  docViewer: { url: string; label: string; safeUrl: SafeResourceUrl } | null = null;
  openDoc(url: string | null | undefined, label: string) {
    if (!url) return;
    // The doc is served inline (Content-Disposition: inline, framable) — view it
    // in-app: images as <img>, PDFs/others in a sanitized <iframe>.
    this.docViewer = { url, label, safeUrl: this.sanitizer.bypassSecurityTrustResourceUrl(url) };
  }
  closeDoc() { this.docViewer = null; }

  /** True for image docs (render as <img>); PDFs/others go in an <iframe>. */
  isImageDoc(url: string | null | undefined): boolean {
    if (!url) return false;
    const u = url.split('?')[0].toLowerCase();
    return /\.(png|jpe?g|gif|webp|bmp|heic|heif|svg)$/.test(u) || url.startsWith('data:image');
  }

  loadCompliance() {
    if (!this.storeId) return;
    this.adapter.get_v3('GET /admin/vendors/:id/compliance', { params: { id: String(this.storeId) } })
      .subscribe({
        next: (r: any) => { if (r?.data) { this.compliance = r.data; this.applyComplianceDocUrls(); } },
        error: () => { /* non-fatal — section just stays empty */ },
      });
  }

  /**
   * Prefer the served/signed compliance document URLs for the KYC images in the
   * completeness grid. The raw vendor fields hold private storage paths (or
   * legacy values) that don't render directly; the compliance endpoint returns
   * loadable URLs for all formats. Runs from whichever of the two loads
   * finishes last (reassigns `store` so the grid re-renders).
   */
  private applyComplianceDocUrls(): void {
    if (!this.store || !this.compliance) return;
    const { front, back, license_doc } = this.compliance;
    if (front == null && back == null && license_doc == null) return;
    this.store = {
      ...this.store,
      id_front: front ?? this.store.id_front,
      id_back: back ?? this.store.id_back,
      license_doc: license_doc ?? this.store.license_doc,
    };
  }

  approveCompliance() {
    this.confirm.confirm({
      title: 'Approve compliance',
      message: `Approve ${this.store.store_name || 'this vendor'}'s KYC documents?`,
      confirmLabel: 'Approve', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (!ok) return;
      this.ui_controls.acting = true;
      this.adapter.post_v3('POST /admin/vendors/:id/compliance/approve', {}, { params: { id: String(this.storeId) } })
        .subscribe({
          next: (r: any) => { if (r) { this.toast.success('Compliance approved.'); this.loadCompliance(); } this.ui_controls.acting = false; },
          error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to approve compliance.')); this.ui_controls.acting = false; },
        });
    });
  }

  rejectCompliance() {
    this.confirm.confirm({
      title: 'Reject compliance',
      message: 'Reject this submission? The vendor will be able to re-upload.',
      confirmLabel: 'Reject', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.ui_controls.acting = true;
      this.adapter.post_v3('POST /admin/vendors/:id/compliance/reject', { note: this.reject_note }, { params: { id: String(this.storeId) } })
        .subscribe({
          next: (r: any) => { if (r) { this.toast.success('Compliance rejected.'); this.reject_note = ''; this.loadCompliance(); } this.ui_controls.acting = false; },
          error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to reject compliance.')); this.ui_controls.acting = false; },
        });
    });
  }

  send_message() {
    if (this.message.subject.length === 0) {
      this.error_notification('Subject is required.');
      return;
    }
    if (this.message.message.length === 0) {
      this.error_notification('Empty message cannot be sent.');
      return;
    }
    this.ui_controls.sending_message = true;
    const storeVid = this.get_data.store ?? this.message.id;
    this.adapter.post_v3('POST /admin/vendors/:id/messages', this.message, { params: { id: String(storeVid) } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.message.subject = '';
          this.message.message = '';
          this.success_notification(response.message);
        }
        this.ui_controls.sending_message = false;
      },
      error: () => {
        this.ui_controls.sending_message = false;
      },
    });
  }

  // ── Quick navigation to this store's sub-screens ───────────────────
  private openTab(path: string) {
    // storeId here is the v3 vendor id (the /admin/stores/:id route param,
    // which get_store() resolves against GET /admin/vendors/:id). The
    // sub-screens historically treated their `id` param as a LEGACY store
    // id and resolved it via /vendors/by-legacy-id — passing a v3 id there
    // silently loaded a DIFFERENT vendor. Send it as `vendor_id` so they
    // use it directly; `id` is kept only for backward-compatible links.
    this.router.navigate([path], {
      queryParams: {
        id: this.storeId,
        vendor_id: this.storeId,
        name: this.store.store_name || this.store_name,
      },
    });
  }
  openOrders()   { this.openTab('/store_orders'); }
  openProducts() { this.openTab('/store_products'); }
  openSales()    { this.openTab('/store_sales'); }

  // ── Store status actions ───────────────────────────────────────────
  approveStore() {
    this.confirm.confirm({
      title: 'Approve store',
      message: `${this.store.store_name || 'This store'} will be approved and made visible.`,
      confirmLabel: 'Approve', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (!ok) return;
      this.ui_controls.acting = true;
      this.adapter.post_v3('POST /admin/vendors/:id/approve', {}, { params: { id: String(this.storeId) } })
        .subscribe({
          next: (r: any) => { if (r) { this.toast.success('Store approved.'); this.get_store(); } this.ui_controls.acting = false; },
          error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to approve store.')); this.ui_controls.acting = false; },
        });
    });
  }

  suspendStore() {
    this.confirm.confirm({
      title: 'Suspend store',
      message: `${this.store.store_name || 'This store'} will be suspended and hidden from customers.`,
      confirmLabel: 'Suspend', cancelLabel: 'Cancel', variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      this.ui_controls.acting = true;
      this.adapter.post_v3('POST /admin/vendors/:id/suspend', {}, { params: { id: String(this.storeId) } })
        .subscribe({
          next: (r: any) => { if (r) { this.toast.success('Store suspended.'); this.get_store(); } this.ui_controls.acting = false; },
          error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to suspend store.')); this.ui_controls.acting = false; },
        });
    });
  }

  reactivateStore() {
    this.confirm.confirm({
      title: 'Reactivate store',
      message: `${this.store.store_name || 'This store'} will be reactivated.`,
      confirmLabel: 'Reactivate', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (!ok) return;
      this.ui_controls.acting = true;
      this.adapter.post_v3('POST /admin/vendors/:id/reactivate', {}, { params: { id: String(this.storeId) } })
        .subscribe({
          next: (r: any) => { if (r) { this.toast.success('Store reactivated.'); this.get_store(); } this.ui_controls.acting = false; },
          error: (err: any) => { this.toast.error(apiErrorMessage(err, 'Unable to reactivate store.')); this.ui_controls.acting = false; },
        });
    });
  }

  get isActive(): boolean {
    return this.store.store_status === true || String(this.store.is_active) === 'true' || String(this.store.is_active) === '1';
  }

  // ── Impersonation (admin "sign in as vendor") ──────────────────────
  get impersonateBusy(): boolean {
    return this.impersonation.starting;
  }

  /**
   * Start a full act-as session for this vendor. Confirmed first because it
   * swaps the admin's session for the vendor's and reloads the app; every
   * action then runs on the vendor's behalf until the banner's Exit is used.
   */
  impersonateStore() {
    const name = this.store.store_name || this.store_name || 'this vendor';
    this.confirm.confirm({
      title: 'Sign in as vendor',
      message: `You'll act as ${name} — placing you in their account with their permissions. A banner will let you exit back to admin at any time. This action is logged.`,
      confirmLabel: 'Sign in as vendor', cancelLabel: 'Cancel',
    }).then((ok) => {
      if (!ok) return;
      this.impersonation.start(this.storeId, this.store.store_name || this.store_name);
    });
  }
}
