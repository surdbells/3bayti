import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
export interface Transactions {
  id: number;
  order_id: string;
  transaction_id: string;
  cart_code: string;
  merchantReference: string;
  amount: string;
  customer: string;
  status: string;
  created: string;
  updated: string;
}

@Component({
  selector: 'app-transactions',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule],
  templateUrl: './transactions.component.html',
  styleUrl: './transactions.component.css',
})
export class TransactionsComponent implements OnInit {
  transactions?: Transactions[];

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

  get_trx = { id: 0, token: '' };
  get_trx_range = { id: 0, token: '', start_date: '', end_date: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.get_trx.id = this.user_session.id;
    this.get_trx.token = this.user_session.token;
    this.get_trx_range.id = this.user_session.id;
    this.get_trx_range.token = this.user_session.token;
    this.get_trxs();
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

  get_trxs() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.adapter.get_v3('GET /admin/transactions', { query: { limit: 50, offset: 0 } }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.transactions = Array.isArray(response.data) ? response.data : response.data?.items ?? [];
          this.ui_controls.no_data = !this.transactions || this.transactions.length === 0;
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

  get_range_trx() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.adapter.get_v3('GET /admin/transactions', { query: { limit: 50, offset: 0, since: this.get_trx_range.start_date, until: this.get_trx_range.end_date } }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.transactions = Array.isArray(response.data) ? response.data : response.data?.items ?? [];
          this.ui_controls.no_data = !this.transactions || this.transactions.length === 0;
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
