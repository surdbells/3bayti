import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { CommonModule } from '@angular/common';

import { IconComponent } from '../../../shared/icon/icon.component';
export interface Items {
  product_name: string;
  quantity: number;
  price: string;
  store: string;
  total_price: string;
  status: string;
}

@Component({
  selector: 'app-single',
  standalone: true,
  imports: [CommonModule, IconComponent],
  templateUrl: './single.component.html',
  styleUrl: './single.component.css',
})
export class SingleComponent implements OnInit {
  items?: Items[];

  ui_controls = {
    is_loading: false,
    no_data: false,
  };

  session_data: any = '';
  order: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  single_order = {
    order_id: '', transaction_id: '', merchantReference: '',
    customer: '', total_paid: '', delivery_fee: '',
    delivery_name: '', delivery_phone: '', delivery_email: '',
    delivery_city: '', delivery_area: '', delivery_street_address: '',
    villa_number: '', status: '', paymentType: '',
    created: '', updated: '',
  };

  getProcessingById = { id: 0, token: '', order: '' };
  getProductsById = { id: 0, token: '', order: '' };

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private route: ActivatedRoute,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    this.getProcessingById.id = this.user_session.id;
    this.getProcessingById.token = this.user_session.token;
    this.getProductsById.id = this.user_session.id;
    this.getProductsById.token = this.user_session.token;
    this.order = this.route.snapshot.queryParamMap.get('order');
    this.get_processingById();
  }

  get_processingById() {
    this.getProcessingById.order = this.order;
    this.ui_controls.is_loading = true;
    const orderId = this.getProcessingById.order ?? this.getProcessingById.id;
    this.adapter.get_v3('GET /admin/orders/:id', { params: { id: String(orderId) } }).subscribe({
      next: (response: any) => {
        if (response) {
          this.single_order = response.data;
          this.get_orderProducts();
        } else {
          this.ui_controls.is_loading = false;
        }
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  get_orderProducts() {
    // M3.4-F: Products embedded in order items from GET /admin/orders/:id.
    // order_products was a legacy property; items now read directly from this.order.items in the template.
  }

  statusBadgeClass(status: string): string {
    switch (status) {
      case 'paid':    return 'ax-badge ax-badge-success';
      case 'pending': return 'ax-badge ax-badge-warning';
      case 'failed':  return 'ax-badge ax-badge-danger';
      default:        return 'ax-badge ax-badge-neutral';
    }
  }

  itemStatusClass(status: string): string {
    switch (status) {
      case 'Accepted':           return 'ax-badge ax-badge-brand';
      case 'Ready for Delivery': return 'ax-badge ax-badge-info';
      case 'Delivered':          return 'ax-badge ax-badge-success';
      default:                   return 'ax-badge ax-badge-neutral';
    }
  }
}
