import { Component, OnInit } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { Orders } from '../../class/orders';
import { GlobalComponent } from '../../global-component';
import { NgClass, NgForOf, NgIf } from '@angular/common';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
@Component({
  selector: 'app-vendor-returns',
  imports: [VendorShellComponent, NgClass, NgForOf, NgIf, RouterLink],
  standalone: true,
  templateUrl: './vendor-returns.component.html',
  styleUrl: './vendor-returns.component.css',
})
export class VendorReturnsComponent implements OnInit {
  orders?: Orders[];

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    no_orders: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  order = { token: '', id: 0 };

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.order.token = this.user_session.token;
    this.order.id = this.user_session.id;
    this.get_vendor_orders();
  }

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_vendor_orders() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.order, GlobalComponent.getVendorReturnOrders).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.orders = response.data;
          this.ui_controls.no_orders = !this.orders || this.orders.length === 0;
        } else {
          this.ui_controls.no_orders = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
        this.ui_controls.no_orders = true;
      },
    });
  }

  open_order(id: number, name: string) {
    this.router
      .navigate(['/', 'order'], { queryParams: { id, name } })
      .then(r => console.log(r));
  }

  statusBadgeClass(status: string): string {
    const s = (status || '').toLowerCase();
    if (s.includes('approved') || s.includes('complete')) return 'ax-badge ax-badge-success';
    if (s.includes('pending') || s.includes('review')) return 'ax-badge ax-badge-warning';
    if (s.includes('reject')) return 'ax-badge ax-badge-danger';
    return 'ax-badge ax-badge-neutral';
  }
}
