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
  customer: string;
  total_price: string;
  status: string;
}

@Component({
  selector: 'app-plural',
  standalone: true,
  imports: [CommonModule, IconComponent],
  templateUrl: './plural.component.html',
  styleUrl: './plural.component.css',
})
export class PluralComponent implements OnInit {
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
    id: 0, phone: '', email: '', name: '',
    store_name: '', store_email: '', store_phone: '', store_address: '',
    store_bank_name: '', store_account_name: '', store_account_number: '',
    last_login: '', trade_license_number: '', licensing_authority: '',
  };

  getProcessingById = { id: 0, token: '', vendor: '' };
  getProductsByVendor = { id: 0, token: '', vendor: 0 };

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
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
    this.ui_controls.is_loading = true;
    // The `vendor` query param is the v3 vendor id (the sales row's vendor_id).
    // Fetch the full admin vendor profile, exactly like manage-store. The
    // previous code fetched an ORDER by the admin's OWN user id and bound it
    // as a vendor, so every store/bank/license field rendered blank.
    const vendorId = this.single_vendor;
    this.adapter.get_v3('GET /admin/vendors/:id', { params: { id: String(vendorId) } }).subscribe({
      next: (response: any) => {
        const v = response?.data ?? response ?? null;
        if (v) {
          this.vendor = v;
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
    // The products endpoint is keyed by LEGACY vendor id; the admin vendor
    // detail carries it as legacy_vendor_id.
    const legacyId = (this.vendor as any).legacy_vendor_id ?? this.vendor.id;
    this.adapter.get_v3('GET /vendors/by-legacy-id/:id/products', { params: { id: String(legacyId) } }).subscribe({
      next: (response: any) => {
        this.items = response?.data ?? [];
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
