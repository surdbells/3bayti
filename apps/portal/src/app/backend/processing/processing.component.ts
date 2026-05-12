import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
export interface Transaction {
  id: number;
  order_id: string;
  transaction_id: string;
  merchantReference: string;
  customer: string;
  total_paid: string;
  delivery_fee: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-processing',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule],
  templateUrl: './processing.component.html',
  styleUrl: './processing.component.css',
})
export class ProcessingComponent implements OnInit {
  transaction?: Transaction[];

  ui_controls = {
    is_loading: false,
    no_data: false,
    nav_open: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  get_processing_s = { id: 0, token: '' };
  get_processing_r = { id: 0, token: '', start_date: '', end_date: '' };
  getProcessingById = { id: 0, token: '', order: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    this.get_processing_s.id = this.user_session.id;
    this.get_processing_s.token = this.user_session.token;
    this.get_processing_r.id = this.user_session.id;
    this.get_processing_r.token = this.user_session.token;
    this.getProcessingById.id = this.user_session.id;
    this.getProcessingById.token = this.user_session.token;

    this.get_processing();
  }

  goBack() {
    this.router.navigate(['/backend']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_processing() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_processing_s, GlobalComponent.processing).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.transaction = response.data;
          this.ui_controls.no_data = !this.transaction || this.transaction.length === 0;
        } else {
          this.ui_controls.no_data = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
        this.ui_controls.no_data = true;
      },
    });
  }

  get_processingById(order: string) {
    this.getProcessingById.order = order;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.getProcessingById, GlobalComponent.processingById).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.transaction = response.data;
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  get_processing_range() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_processing_r, GlobalComponent.processing).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.transaction = response.data;
          this.ui_controls.no_data = !this.transaction || this.transaction.length === 0;
        } else {
          this.ui_controls.no_data = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
        this.ui_controls.no_data = true;
      },
    });
  }

  openPopup(route: string) {
    const baseUrl = window.location.origin;
    const fullUrl = `${baseUrl}/${route.replace(/^\/+/, '')}`;
    const screenWidth = window.screen.availWidth;
    const screenHeight = window.screen.availHeight;
    const width = screenWidth * 0.8;
    const height = screenHeight * 0.8;
    const left = (screenWidth - width) / 2;
    const top = (screenHeight - height) / 2;
    const features = `
    width=${width},
    height=${height},
    left=${left},
    top=${top},
    scrollbars=yes,
    resizable=yes`.replace(/\s+/g, '');
    window.open(fullUrl, 'popupWindow', features);
  }
}
