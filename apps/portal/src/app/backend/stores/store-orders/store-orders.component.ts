import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { Orders } from '../../../class/orders';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-store-orders',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule],
  templateUrl: './store-orders.component.html',
  styleUrl: './store-orders.component.css',
})
export class StoreOrdersComponent implements OnInit {
  orders?: Orders[];

  ui_controls = {
    is_loading: false,
    no_orders: false,
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

  order = { token: '', id: 0, store: 0 };
  singleStatus = { id: 0, token: '', store: 0, status: 'Accepted' };

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

    this.order.token = this.user_session.token;
    this.order.id = this.user_session.id;
    this.order.store = storeId || 0;

    this.singleStatus.store = storeId || 0;
    this.singleStatus.token = this.user_session.token;
    this.singleStatus.id = this.user_session.id;

    this.get_vendor_orders();
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

  get_vendor_orders() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_orders = false;
    this.crudService.post_request(this.order, GlobalComponent.getStoreOrders).subscribe({
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
      .navigate(['/', 'admin_order'], { queryParams: { id, name } })
      .then(r => console.log(r));
  }

  get_vendor_orders_status(event: Event) {
    this.singleStatus.status = (event.target as HTMLSelectElement).value;
    this.ui_controls.is_loading = true;
    this.ui_controls.no_orders = false;
    this.orders = [];
    this.crudService.post_request(this.singleStatus, GlobalComponent.getStoreOrdersByStatus).subscribe({
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

  statusBadgeClass(status: string): string {
    switch (status) {
      case 'pending':            return 'ax-badge ax-badge-warning';
      case 'Accepted':           return 'ax-badge ax-badge-brand';
      case 'Ready for Delivery': return 'ax-badge ax-badge-info';
      case 'Delivered':          return 'ax-badge ax-badge-success';
      case 'Return Requested':   return 'ax-badge ax-badge-warning';
      case 'Returned':           return 'ax-badge ax-badge-neutral';
      case 'Cancelled':          return 'ax-badge ax-badge-neutral';
      case 'Refunded':           return 'ax-badge ax-badge-neutral';
      default:                   return 'ax-badge ax-badge-neutral';
    }
  }
}
