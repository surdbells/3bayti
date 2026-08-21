import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { apiErrorMessage } from '../../shared/http/api-error';
@Component({
  selector: 'app-vendor-tax',
  standalone: true,
  imports: [VendorShellComponent, FormsModule, CommonModule, IconComponent],
  templateUrl: './vendor-tax.component.html',
  styleUrl: './vendor-tax.component.css',
})
export class VendorTaxComponent implements OnInit {
  ui_controls = {
    is_loading: false,
    is_saving: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  store_single = {
    store_legal_name: '',
    trade_license_number: '',
    licensing_authority: '',
    tax_registration_number: '',
    vat_registration_effective_date: '',
    registered_tax_address: '',
    tax_contact_email: '',
    vat_status: '',
  };

  get_single = { id: 0, token: '' };
  update_tax = {
    id: 0, token: '',
    store_legal_name: '',
    trade_license_number: '',
    licensing_authority: '',
    tax_registration_number: '',
    vat_registration_effective_date: '',
    registered_tax_address: '',
    tax_contact_email: '',
    vat_status: '',
  };

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    if (!this.user_session.is_active) {
      this.router.navigate(['/', '']).then(r => console.log(r));
      return;
    }
    this.get_single.id = this.user_session.id;
    this.get_single.token = this.user_session.token;
    this.get_data();
  }

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  get_data() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/store/tax').subscribe({
      next: (response: any) => {
        if (response) {
          this.store_single = response.data;
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_loading = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Unable to complete your request at this time.'));
        this.ui_controls.is_loading = false;
      },
    });
  }

  save_change() {
    this.update_tax.id = this.user_session.id;
    this.update_tax.token = this.user_session.token;
    this.update_tax.vat_status = this.store_single.vat_status;
    this.update_tax.store_legal_name = this.store_single.store_legal_name;
    this.update_tax.trade_license_number = this.store_single.trade_license_number;
    this.update_tax.licensing_authority = this.store_single.licensing_authority;
    this.update_tax.tax_registration_number = this.store_single.tax_registration_number;
    this.update_tax.vat_registration_effective_date = this.store_single.vat_registration_effective_date;
    this.update_tax.registered_tax_address = this.store_single.registered_tax_address;
    this.update_tax.tax_contact_email = this.store_single.tax_contact_email;

    this.ui_controls.is_saving = true;
    this.adapter.patch_v3('PATCH /vendor/store/tax', this.update_tax).subscribe({
      next: (response: any) => {
        if (response) {
          this.success_notification(response.message);
          this.get_data();
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving = false;
      },
      error: (err: any) => {
        this.error_notification(apiErrorMessage(err, 'Unable to complete your request at this time.'));
        this.ui_controls.is_saving = false;
      },
    });
  }
}
