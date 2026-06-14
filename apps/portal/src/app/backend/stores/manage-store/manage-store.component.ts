import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
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
    AxTabComponent, IconComponent],
  templateUrl: './manage-store.component.html',
  styleUrl: './manage-store.component.css',
})
export class ManageStoreComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);

  ui_controls = {
    is_loading: false,
    no_data: false,
    sending_message: false,
    nav_open: false,
    acting: false,
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

  store = {
    id: 0, token: '',
    first_name: '', last_name: '', email: '', phone: '',
    avatar: '', id_front: '', id_back: '', is_active: '',
    license_doc: '', store_name: '', store_status: false,
    approved: false, store_email: '', store_phone: '',
    store_address: '', store_description: '',
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
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    const storeId = Number(this.route.snapshot.queryParamMap.get('id'));
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
    this.router.navigate(['/stores']).then(r => console.log(r));
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
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
    this.loadCompliance();
  }

  // ── KYC compliance review ───────────────────────────────────────────
  compliance: any = {
    front: null, back: null, license_doc: null,
    compliance_status: 'pending', reviewed_at: null, reviewed_by: null, review_note: null,
  };
  reject_note = '';

  loadCompliance() {
    if (!this.storeId) return;
    this.adapter.get_v3('GET /admin/vendors/:id/compliance', { params: { id: String(this.storeId) } })
      .subscribe({
        next: (r: any) => { if (r?.data) this.compliance = r.data; },
        error: () => { /* non-fatal — section just stays empty */ },
      });
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
          error: () => { this.toast.error('Unable to approve compliance.'); this.ui_controls.acting = false; },
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
          error: () => { this.toast.error('Unable to reject compliance.'); this.ui_controls.acting = false; },
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
    this.router.navigate([path], {
      queryParams: { id: this.storeId, name: this.store.store_name || this.store_name },
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
          error: () => { this.toast.error('Unable to approve store.'); this.ui_controls.acting = false; },
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
          error: () => { this.toast.error('Unable to suspend store.'); this.ui_controls.acting = false; },
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
          error: () => { this.toast.error('Unable to reactivate store.'); this.ui_controls.acting = false; },
        });
    });
  }

  get isActive(): boolean {
    return this.store.store_status === true || String(this.store.is_active) === 'true' || String(this.store.is_active) === '1';
  }
}
