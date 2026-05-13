import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { Orders } from '../../class/orders';
import { CartItems } from '../../class/cart_items';
import { FormsModule } from '@angular/forms';
import {
  AxTableComponent,
  AxColumnComponent,
  AxEmptyStateComponent,
} from '../../shared/data';

@Component({
  selector: 'app-vendor-orders',
  imports: [
    VendorShellComponent,
    CommonModule,
    FormsModule,
    AxTableComponent,
    AxColumnComponent,
    AxEmptyStateComponent,
  ],
  standalone: true,
  templateUrl: './vendor-orders.component.html',
  styleUrl: './vendor-orders.component.css',
})
export class VendorOrdersComponent implements OnInit {
  cartItems?: CartItems[];
  orders?: Orders[];

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    no_orders: false,
    loading_items: false,
    updating_order: false,
  };

  session_data: any = '';
  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false,
  };

  order = {
    token: '',
    id: 0,
  };

  singleStatus = {
    id: 0,
    token: '',
    status: 'Accepted',
  };

  readonly statusOptions = [
    { value: 'pending',            label: 'Pending' },
    { value: 'Accepted',           label: 'Accepted' },
    { value: 'Ready for Delivery', label: 'Ready for Delivery' },
    { value: 'Delivered',          label: 'Delivered' },
    { value: 'Return Requested',   label: 'Return Requested' },
    { value: 'Returned',           label: 'Returned' },
    { value: 'Cancelled',          label: 'Cancelled' },
    { value: 'Refunded',           label: 'Refunded' },
  ];

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.order.token = this.user_session.token;
    this.order.id = this.user_session.id;
    this.singleStatus.token = this.user_session.token;
    this.singleStatus.id = this.user_session.id;
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
    this.crudService.post_request(this.order, GlobalComponent.getVendorOrders).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.orders = response.data;
          this.ui_controls.is_loading = false;
          this.ui_controls.no_orders = false;
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

  onStatusChange(event: Event) {
    this.singleStatus.status = (event.target as HTMLSelectElement).value;
    this.get_vendor_orders_status();
  }

  get_vendor_orders_status() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_orders = false;
    this.orders = [];
    this.crudService.post_request(this.singleStatus, GlobalComponent.getVendorOrdersByStatus).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.orders = response.data;
          this.ui_controls.is_loading = false;
          this.ui_controls.no_orders = false;
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

  /** Maps a status value to its badge treatment. */
  statusBadgeClass(status: string): string {
    switch (status) {
      case 'Accepted':
      case 'Ready for Delivery':
      case 'Delivered':
        return 'ax-badge-success';
      case 'Return Requested':
        return 'ax-badge-warning';
      case 'pending':
        return 'ax-badge-danger';
      case 'Returned':
      case 'Cancelled':
      case 'Refunded':
      default:
        return 'ax-badge-neutral';
    }
  }

  statusLabel(status: string): string {
    return status === 'pending' ? 'Pending' : status;
  }
}
