import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../../global-component';
import { FormsModule } from '@angular/forms';
import { TranslatePipe } from '../../../translate.pipe';

import { AccountSetupComponent } from '../account-setup/account-setup.component';

// Ax design system
import { AxRichEditorComponent } from '../../../shared/rich/ax-rich-editor.component';
import { AxTabsComponent, AxTabComponent } from '../../../shared/overlays';

import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-manage-store',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AccountSetupComponent,
    TranslatePipe,
    AxRichEditorComponent,
    AxTabsComponent,
    AxTabComponent,
  ],
  templateUrl: './manage-store.component.html',
  styleUrl: './manage-store.component.css',
})
export class ManageStoreComponent implements OnInit {
  ui_controls = {
    is_loading: false,
    no_data: false,
    sending_message: false,
    nav_open: false,
  };

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
    private crudService: CrudService,
    private route: ActivatedRoute,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    const storeId = Number(this.route.snapshot.queryParamMap.get('id'));
    this.store_name = this.route.snapshot.queryParamMap.get('name');

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
    this.crudService.post_request(this.get_data, GlobalComponent.getSingleStore).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
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
    this.crudService.post_request(this.message, GlobalComponent.messageVendor).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
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
}
