import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
export interface Sales {
  id: number;
  order_ref: string;
  product_name: string;
  transaction_ref: string;
  quantity: number;
  price: number;
  total_price: string;
  delivery: string;
  total_paid: string;
  noon: string;
  commission: string;
  charges: string;
  vendor_pay: string;
  customer_email: string;
  customer_name: string;
  status: string;
  created: string;
}

@Component({
  selector: 'app-commissions',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule],
  templateUrl: './commissions.component.html',
  styleUrl: './commissions.component.css',
})
export class CommissionsComponent implements OnInit {
  sales?: Sales[];

  ui_controls = {
    is_loading: false,
    no_data: false,
    nav_open: false,
  };

  session_data: any = '';
  stats = { commissions: '', charges: '' };
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  get_s = { id: 0, token: '' };
  get_sale_range = { id: 0, token: '', start_date: '', end_date: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.get_s.id = this.user_session.id;
    this.get_s.token = this.user_session.token;
    this.get_sale_range.id = this.user_session.id;
    this.get_sale_range.token = this.user_session.token;
    this.get_sales();
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

  get_sales() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_s, GlobalComponent.commissions).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.sales = response.data;
          this.stats = response.message;
          this.ui_controls.no_data = !this.sales || this.sales.length === 0;
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

  get_range_sales() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_sale_range, GlobalComponent.commissions).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.sales = response.data;
          this.stats = response.message;
          this.ui_controls.no_data = !this.sales || this.sales.length === 0;
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
}
