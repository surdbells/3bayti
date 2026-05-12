import { Component, inject, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { Customers } from '../../class/customers';
import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-customers',
  standalone: true,
  imports: [AdminShellComponent, CommonModule],
  templateUrl: './customers.component.html',
  styleUrl: './customers.component.css',
})
export class CustomersComponent implements OnInit {
  customers?: Customers[];
  private readonly confirm = inject(AxConfirmService);

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

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.get_data.id = this.user_session.id;
    this.get_data.token = this.user_session.token;

    this.activate.id = this.user_session.id;
    this.activate.token = this.user_session.token;

    this.deactivate.id = this.user_session.id;
    this.deactivate.token = this.user_session.token;
    this.get_customer();
  }

  get_data = { id: 0, token: '' };
  activate = { id: 0, token: '', customer: 0, status: true };
  deactivate = { id: 0, token: '', customer: 0, status: false };

  goBack() {
    this.router.navigate(['/backend']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_customer() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_data, GlobalComponent.getCustomers).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.customers = response.data;
          this.ui_controls.no_data = !this.customers || this.customers.length === 0;
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

  start_activate(customer: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `${name}'s account will be activated?`,
        confirmLabel: 'Activate',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) this.activate_customer(customer, name);
      });
  }

  activate_customer(customer: number, _name: string) {
    this.ui_controls.is_loading = true;
    this.activate.customer = customer;
    this.crudService.post_request(this.activate, GlobalComponent.activateCustomer).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_customer();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  start_deactivate(customer: number, name: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `${name} will be deactivated?`,
        confirmLabel: 'Deactivate',
        cancelLabel: 'Cancel',
        variant: 'danger'
      })
      .then((response) => {
        if (response) this.deactivate_customer(customer, name);
      });
  }

  deactivate_customer(customer: number, _name: string) {
    this.ui_controls.is_loading = true;
    this.deactivate.customer = customer;
    this.crudService.post_request(this.deactivate, GlobalComponent.deactivateCustomer).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_customer();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }
}
