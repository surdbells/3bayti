import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../../global-component';
import { CommonModule } from '@angular/common';

export interface Items {
  product_name: string;
  billing_city: number;
  billing_area: string;
  billing_email: string;
  customer: string;
  quantity: number;
  villa_number: string;
  status: string;
  billing_name: string;
  billing_street: string;
  billing_phone: string;
}

@Component({
  selector: 'app-deliveries',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './deliveries.component.html',
  styleUrl: './deliveries.component.css',
})
export class DeliveriesComponent implements OnInit {
  items?: Items[];

  ui_controls = {
    is_loading: false,
    no_data: false,
  };

  session_data: any = '';
  single_vendor: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  vendor = {
    id: 0,
    phone: '', email: '', name: '',
    store_name: '', store_email: '', store_phone: '', store_address: '',
    store_bank_name: '', store_account_name: '', store_account_number: '',
    last_login: '', trade_license_number: '', licensing_authority: '',
  };

  getProcessingById = { id: 0, token: '', vendor: '' };
  getProductsByVendor = { id: 0, token: '', vendor: 0 };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private route: ActivatedRoute,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);

    this.getProcessingById.id = this.user_session.id;
    this.getProcessingById.token = this.user_session.token;
    this.getProductsByVendor.id = this.user_session.id;
    this.getProductsByVendor.token = this.user_session.token;
    this.single_vendor = this.route.snapshot.queryParamMap.get('vendor');
    this.get_processingById();
  }

  get_processingById() {
    this.getProcessingById.vendor = this.single_vendor;
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.getProcessingById, GlobalComponent.pluralById).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.vendor = response.data;
          this.get_vendorProducts();
        } else {
          this.ui_controls.is_loading = false;
        }
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  get_vendorProducts() {
    this.getProductsByVendor.vendor = this.vendor.id;
    this.crudService.post_request(this.getProductsByVendor, GlobalComponent.productsByVendorId).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.items = response.data;
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
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
