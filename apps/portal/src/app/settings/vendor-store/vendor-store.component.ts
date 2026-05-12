import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { AxRichEditorComponent } from '../../shared/rich/ax-rich-editor.component';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
@Component({
  selector: 'app-vendor-store',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, FormsModule, AxRichEditorComponent],
  templateUrl: './vendor-store.component.html',
  styleUrl: './vendor-store.component.css',
})
export class VendorStoreComponent implements OnInit {
  base64String: any;

  ui_controls = {
    is_loading: false,
    is_saving_basic: false,
    is_saving_status: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  store_single = {
    store_name: '',
    store_address: '',
    store_status: false,
    store_approved: false,
    store_email: '',
    store_phone: '',
    store_description: '',
    store_logo: '../assets/img/placeholder-1.png',
    store_cover: '../assets/img/placeholder-1.png',
  };

  get_single = { id: 0, token: '' };

  update_store = {
    id: 0, token: '',
    store_name: '',
    store_address: '',
    store_email: '',
    store_phone: '',
    store_description: '',
    store_logo: '../assets/img/placeholder-1.png',
    store_cover: '../assets/img/placeholder-1.png',
  };

  update_status = {
    id: 0, token: '',
    store_status: false,
  };

  constructor(
    private router: Router,
    private crudService: CrudService,
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
    this.crudService.post_request(this.get_single, GlobalComponent.getVendorStore).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.store_single = response.data;
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
      },
    });
  }

  update_changes() {
    this.update_store.id = this.user_session.id;
    this.update_store.token = this.user_session.token;
    this.update_store.store_name = this.store_single.store_name;
    this.update_store.store_address = this.store_single.store_address;
    this.update_store.store_email = this.store_single.store_email;
    this.update_store.store_phone = this.store_single.store_phone;
    this.update_store.store_description = this.store_single.store_description;
    this.update_store.store_logo = this.store_single.store_logo;
    this.update_store.store_cover = this.store_single.store_cover;

    this.ui_controls.is_saving_basic = true;
    this.crudService.post_request(this.update_store, GlobalComponent.updateStoreBasic).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_data();
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving_basic = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_saving_basic = false;
      },
    });
  }

  change_store_status() {
    this.update_status.id = this.user_session.id;
    this.update_status.token = this.user_session.token;
    this.update_status.store_status = this.store_single.store_status;

    this.ui_controls.is_saving_status = true;
    this.crudService.post_request(this.update_status, GlobalComponent.updateStoreStatus).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_data();
        } else if (response.status === 'failed') {
          this.error_notification(response.message);
        }
        this.ui_controls.is_saving_status = false;
      },
      error: () => {
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_saving_status = false;
      },
    });
  }

  select_logo(event: any) {
    const file = event.target.files[0];
    const reader = new FileReader();
    reader.onloadend = () => {
      this.base64String = reader.result as string;
      this.store_single.store_logo = this.base64String;
    };
    if (file) reader.readAsDataURL(file);
  }

  select_cover(event: any) {
    const file = event.target.files[0];
    const reader = new FileReader();
    reader.onloadend = () => {
      this.base64String = reader.result as string;
      this.store_single.store_cover = this.base64String;
    };
    if (file) reader.readAsDataURL(file);
  }
}
