import { Component, inject, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AxMultiselectComponent, AxMultiselectOption } from '../../shared/forms';

import { AxConfirmService } from '../../shared/overlays';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { apiErrorMessage } from '../../shared/http/api-error';
interface StoreOption { id: number; store_name: string; }
interface NamedOption { id: number; name: string; }

@Component({
  selector: 'app-edit-coupon',
  standalone: true,
  imports: [
    VendorShellComponent,
    AdminShellComponent,
    CommonModule, FormsModule,
    AxMultiselectComponent, IconComponent],
  templateUrl: './edit-coupon.component.html',
  styleUrl: './edit-coupon.component.css',
})
export class EditCouponComponent implements OnInit {
  private readonly confirm = inject(AxConfirmService);
  private readonly navHistory = inject(NavigationHistoryService);

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  ui = { loading: false, page_loading: true, nav_open: false };

  coupon_id = 0;
  coupon_code = '';

  form = {
    name: '',
    description: '',
    discount_type: 'percentage' as 'percentage' | 'fixed_amount' | 'free_shipping',
    discount_value: 0,
    max_discount_amount: null as number | null,
    scope: 'all_products' as 'all_products' | 'specific_products' | 'specific_categories',
    max_uses: null as number | null,
    max_uses_per_customer: 1,
    min_order_amount: 0,
    first_order_only: false,
    starts_at: '',
    expires_at: '',
    status: 'active',
  };

  coupon_mode: 'platform' | 'as_vendor' = 'platform';
  assign_to_store: number | null = null;
  original_store_id: number | null = null;

  stats = {
    total_uses: 0,
    unique_customers: 0,
    total_discount_given: 0,
    total_revenue_generated: 0,
  };

  categories: NamedOption[] = [];
  products: NamedOption[] = [];
  stores: StoreOption[] = [];

  selectedProductIds: (string | number)[] = [];
  selectedCategoryIds: (string | number)[] = [];
  selectedStoreIds: (string | number)[] = [];

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.coupon_id = Number(this.route.snapshot.queryParamMap.get('id'));

    this.fetchCoupon();
    this.fetchLookupData();
  }

  fetchCoupon(): void {
    const payload = {
      token: this.user_session.token,
      id: this.user_session.id,
      coupon_id: this.coupon_id,
    };

    const couponId = payload.coupon_id ?? payload.id;
    this.adapter.get_v3('GET /vendor/coupons/:id', { params: { id: String(couponId) } }).subscribe({
      next: (response: any) => {
        if (response) {
          const d = response.data;
          this.coupon_code = d.code;

          this.form.name = d.name;
          this.form.description = d.description || '';
          this.form.discount_type = d.discount_type;
          this.form.discount_value = d.discount_value;
          this.form.max_discount_amount = d.max_discount_amount;
          this.form.scope = d.scope;
          this.form.max_uses = d.max_uses;
          this.form.max_uses_per_customer = d.max_uses_per_customer ?? 1;
          this.form.min_order_amount = d.min_order_amount;
          this.form.first_order_only = !!d.first_order_only;
          this.form.starts_at = d.starts_at ? this.toDatetimeLocal(d.starts_at) : '';
          this.form.expires_at = d.expires_at ? this.toDatetimeLocal(d.expires_at) : '';
          this.form.status = d.status;

          if (d.stats) this.stats = d.stats;

          const targets = d.target_products || [];
          this.selectedProductIds = targets.filter((t: any) => t.target_type === 'product').map((t: any) => t.target_id);
          this.selectedCategoryIds = targets.filter((t: any) => t.target_type === 'category').map((t: any) => t.target_id);
          this.selectedStoreIds = (d.target_stores || []).map((s: any) => s.store_id);

          this.original_store_id = d.store_id;
          if (d.created_by_role === 'admin' && d.store_id !== null) {
            this.coupon_mode = 'as_vendor';
            this.assign_to_store = d.store_id;
          } else if (d.created_by_role === 'admin') {
            this.coupon_mode = 'platform';
          }

          this.ui.page_loading = false;
        } else {
          this.toast.error('Coupon not found.');
          this.router.navigate(['/coupons']);
        }
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load coupon.'));
        this.router.navigate(['/coupons']);
      },
    });
  }

  fetchLookupData(): void {
    this.adapter.get_v3('GET /utility/categories').subscribe({
      next: (r: any) => {
        if (r) {
          this.categories = (r.data || []).map((c: any) => ({ id: c.id ?? c.category_id, name: c.name ?? c.category_name }));
        }
      },
    });

    if (this.user_session.is_vendor) {
      const payload = { token: this.user_session.token, id: this.user_session.id, store: this.user_session.id };
      this.adapter.get_v3('GET /vendors/by-legacy-id/:id/products', { params: { id: String(payload.id ?? 0) }, query: { limit: 100 } }).subscribe({
        next: (r: any) => {
          if (r) {
            this.products = (r.data || []).map((p: any) => ({ id: p.id, name: p.name }));
          }
        },
      });
    }

    if (this.user_session.is_admin) {
      this.adapter.get_v3('GET /utility/stores').subscribe({
        next: (r: any) => {
          if (r) {
            this.stores = (r.data || []).map((s: any) => ({ id: s.user_id ?? s.id, store_name: s.store_name }));
          }
        },
      });
    }
  }

  get productOptions(): AxMultiselectOption[] {
    return this.products.map(p => ({ id: p.id, label: p.name }));
  }
  get categoryOptions(): AxMultiselectOption[] {
    return this.categories.map(c => ({ id: c.id, label: c.name }));
  }
  get storeOptions(): AxMultiselectOption[] {
    return this.stores.map(s => ({ id: s.id, label: s.store_name }));
  }

  private validate(): boolean {
    if (!this.form.name.trim()) { this.toast.error('Coupon name is required.'); return false; }
    if (this.form.discount_type !== 'free_shipping' && this.form.discount_value <= 0) {
      this.toast.error('Discount value must be greater than 0.'); return false;
    }
    if (this.form.discount_type === 'percentage' && this.form.discount_value > 100) {
      this.toast.error('Percentage cannot exceed 100%.'); return false;
    }
    return true;
  }

  startUpdate(): void {
    if (!this.validate()) return;
    if (this.user_session.is_admin && this.coupon_mode === 'as_vendor' && !this.assign_to_store) {
      this.toast.error('Please select a store to assign this coupon to.');
      return;
    }

    this.confirm
      .confirm({
        title: 'Save changes',
        message: 'Update this coupon? Changes take effect immediately.',
        confirmLabel: 'Save',
        cancelLabel: 'Cancel'
      })
      .then((ok) => {
        if (ok) this.submitUpdate();
      });
  }

  private submitUpdate(): void {
    this.ui.loading = true;

    const target_products: any[] = [];
    if (this.form.scope === 'specific_products') {
      for (const id of this.selectedProductIds) {
        target_products.push({ target_type: 'product', target_id: Number(id) });
      }
    }
    if (this.form.scope === 'specific_categories') {
      for (const id of this.selectedCategoryIds) {
        target_products.push({ target_type: 'category', target_id: Number(id) });
      }
    }

    const payload: any = {
      token: this.user_session.token,
      id: this.user_session.id,
      coupon_id: this.coupon_id,
      name: this.form.name,
      description: this.form.description,
      discount_type: this.form.discount_type,
      discount_value: this.form.discount_type === 'free_shipping' ? 0 : this.form.discount_value,
      max_discount_amount: this.form.max_discount_amount,
      scope: this.form.scope,
      max_uses: this.form.max_uses,
      max_uses_per_customer: this.form.max_uses_per_customer,
      min_order_amount: this.form.min_order_amount,
      first_order_only: this.form.first_order_only ? 1 : 0,
      starts_at: this.form.starts_at || null,
      expires_at: this.form.expires_at || null,
      status: this.form.status,
      target_products,
      target_stores: this.selectedStoreIds.map(id => Number(id)),
    };

    if (this.user_session.is_admin) {
      payload.coupon_mode = this.coupon_mode;
      if (this.coupon_mode === 'as_vendor' && this.assign_to_store) {
        payload.assign_to_store = this.assign_to_store;
      }
    }

    const couponId = payload.coupon_id ?? payload.id;
    this.adapter.put_v3('PUT /vendor/coupons/:id', payload, { params: { id: String(couponId) } }).subscribe({
      next: (response: any) => {
        this.ui.loading = false;
        if (response?.data?.id) {
          this.toast.success('Coupon updated successfully');
          this.router.navigate(['/coupons']);
        } else {
          this.toast.error(response.message);
        }
      },
      error: (err: any) => {
        this.ui.loading = false;
        this.toast.error(apiErrorMessage(err, 'Unable to update coupon.'));
      },
    });
  }

  private toDatetimeLocal(dt: string): string {
    if (!dt) return '';
    const d = new Date(dt);
    return d.toISOString().slice(0, 16);
  }

  goBack(): void {
    this.navHistory.back('/coupons');
  }
}
