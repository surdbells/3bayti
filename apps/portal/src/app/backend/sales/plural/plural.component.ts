import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { CommonModule } from '@angular/common';

import { IconComponent } from '../../../shared/icon/icon.component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
export interface Items {
  product_name: string;
  quantity: number;
  /** Vendor payout for the line = subtotal − commission (computed client-side). */
  vendor_pay: string;
  total_price: string;
  customer: string;
  status: string;
  order_reference: string;
}

@Component({
  selector: 'app-plural',
  standalone: true,
  imports: [CommonModule, IconComponent, AdminShellComponent],
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
          this.get_vendorOrderItems();
        } else {
          this.ui_controls.is_loading = false;
        }
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  get_vendorOrderItems() {
    // Vendor ORDER ITEMS, the previous code fetched the vendor's product
    // CATALOG (GET /vendors/by-legacy-id/:id/products) and rendered products as
    // order items, so customer/qty/total/status were blank and the price object
    // rendered as "[object Object]". Fetch the admin cross-vendor orders scoped
    // to this vendor, then flatten THIS vendor's line items. Admin sees every
    // item in a matched order, so we filter by item.vendor_id.
    const vendorId = Number(this.single_vendor) || Number(this.vendor.id) || 0;
    const rate = Number((this.vendor as any).commission_rate) || 0;
    this.adapter.get_v3('GET /admin/orders', { query: { vendor_id: vendorId, limit: 100, offset: 0 } }).subscribe({
      next: (response: any) => {
        const raw = response?.data?.orders ?? response?.orders ?? response?.data ?? [];
        const orders: any[] = Array.isArray(raw) ? raw : [];
        const rows: Items[] = [];
        for (const o of orders) {
          const customer = `${o?.customer?.first_name ?? ''} ${o?.customer?.last_name ?? ''}`.trim()
            || o?.customer?.email || '—';
          for (const it of (o?.items ?? [])) {
            if (Number(it?.vendor_id) !== vendorId) continue;
            const subtotal = Number(it?.subtotal) || 0;
            // Vendor payout = line subtotal − commission (rate is a %); the rate
            // comes from the admin vendor detail, so this stays admin-only and
            // never leaks a vendor's payout to customer-facing endpoints.
            const payout = subtotal - (subtotal * rate) / 100;
            rows.push({
              product_name: it?.product_name ?? '—',
              quantity: it?.quantity ?? 0,
              vendor_pay: payout.toFixed(2),
              total_price: subtotal.toFixed(2),
              customer,
              status: it?.item_status ?? '',
              order_reference: o?.order_reference ?? '',
            });
          }
        }
        this.items = rows;
        this.ui_controls.no_data = rows.length === 0;
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  itemStatusClass(status: string): string {
    switch ((status || '').toLowerCase()) {
      case 'accepted':
      case 'preparing': return 'ax-badge ax-badge-brand';
      case 'shipped':   return 'ax-badge ax-badge-info';
      case 'delivered': return 'ax-badge ax-badge-success';
      case 'rejected':
      case 'cancelled': return 'ax-badge ax-badge-danger';
      case 'returned':
      case 'refunded':  return 'ax-badge ax-badge-warning';
      default:          return 'ax-badge ax-badge-neutral';
    }
  }

  /** Capitalise the lowercase item_status for display (e.g. 'pending' → 'Pending'). */
  prettyStatus(status: string): string {
    if (!status) { return '—'; }
    return status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ');
  }
}
