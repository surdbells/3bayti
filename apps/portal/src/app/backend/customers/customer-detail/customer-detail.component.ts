import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AxCanDirective } from '../../../shared/security/ax-can.directive';
import { AxConfirmService } from '../../../shared/overlays';
import { apiErrorMessage } from '../../../shared/http/api-error';

interface CustomerProfile {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  country_code: string;
  avatar_url: string;
  is_email_verified: boolean;
  is_phone_verified: boolean;
  marketing_opt_out: boolean;
  last_login_at: string | null;
  created_at: string | null;
}

interface CustomerOrder {
  id: number;
  order_reference: string;
  status: string;
  total: unknown;
  date: string;
}

/**
 * Admin read view of a single customer: profile + order history, with the
 * account status action. Reached by clicking a row on the Customers list
 * (id + display name + current active flag arrive as query params, since the
 * public-profile payload doesn't carry the admin `is_active` flag).
 */
@Component({
  selector: 'app-customer-detail',
  standalone: true,
  imports: [CommonModule, FormsModule, AdminShellComponent, IconComponent, AxCanDirective],
  templateUrl: './customer-detail.component.html',
  styleUrl: './customer-detail.component.css',
})
export class CustomerDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly confirm = inject(AxConfirmService);

  id = 0;
  displayName = '';
  isActive = true;

  loading = true;
  profile: CustomerProfile | null = null;

  /** Contact-info edit form (name/email/phone), populated from the profile. */
  editing = false;
  saving = false;
  form = { first_name: '', last_name: '', email: '', phone: '' };

  ordersLoading = true;
  orders: CustomerOrder[] = [];

  ngOnInit(): void {
    const pm = this.route.snapshot.paramMap;
    const qp = this.route.snapshot.queryParamMap;
    this.id = Number(pm.get('id') ?? qp.get('id')) || 0;
    this.displayName = qp.get('name') || '';
    this.isActive = qp.get('active') !== 'false';
    if (!this.id) {
      this.router.navigate(['/admin/customers']);
      return;
    }
    this.loadProfile();
    this.loadOrders();
  }

  private loadProfile(): void {
    this.loading = true;
    this.adapter.get_v3('GET /admin/users/:id', { params: { id: String(this.id) } }).subscribe({
      next: (res: any) => {
        this.profile = (res?.data ?? res) as CustomerProfile;
        if (this.profile && !this.displayName) {
          this.displayName = `${this.profile.first_name ?? ''} ${this.profile.last_name ?? ''}`.trim();
        }
        this.loading = false;
      },
      error: (err: any) => {
        this.toast.error(apiErrorMessage(err, 'Unable to load this customer.'));
        this.loading = false;
      },
    });
  }

  private loadOrders(): void {
    this.ordersLoading = true;
    this.adapter.get_v3('GET /admin/orders', { query: { user_id: this.id, limit: 50 } }).subscribe({
      next: (res: any) => {
        this.orders = (res?.orders ?? res?.data ?? []) as CustomerOrder[];
        this.ordersLoading = false;
      },
      error: () => {
        this.ordersLoading = false;
      },
    });
  }

  /** Enter edit mode, seeding the form from the loaded profile. */
  startEdit(): void {
    const p = this.profile;
    if (!p) return;
    this.form = {
      first_name: p.first_name ?? '',
      last_name: p.last_name ?? '',
      email: p.email ?? '',
      phone: p.phone ?? '',
    };
    this.editing = true;
  }

  cancelEdit(): void {
    this.editing = false;
  }

  /** Persist the contact-info edit via PUT /admin/users/:id. */
  saveEdit(): void {
    if (this.saving) return;
    const email = this.form.email.trim();
    if (!email) {
      this.toast.error('Email is required.');
      return;
    }
    this.saving = true;
    const body = {
      first_name: this.form.first_name.trim(),
      last_name: this.form.last_name.trim(),
      email,
      // Empty phone clears it server-side.
      phone: this.form.phone.trim(),
    };
    this.adapter
      .put_v3('PUT /admin/users/:id', body, { params: { id: String(this.id) } })
      .subscribe({
        next: (res: any) => {
          this.profile = (res?.data ?? res) as CustomerProfile;
          this.displayName = `${this.profile.first_name ?? ''} ${this.profile.last_name ?? ''}`.trim();
          this.saving = false;
          this.editing = false;
          this.toast.success('Customer details updated.');
        },
        error: (err: any) => {
          this.saving = false;
          this.toast.error(apiErrorMessage(err, 'Unable to update customer details.'));
        },
      });
  }

  get initials(): string {
    const p = this.profile;
    const src = p ? `${p.first_name ?? ''} ${p.last_name ?? ''}` : this.displayName;
    const parts = src.trim().split(/\s+/).filter(Boolean).slice(0, 2);
    return parts.map((w) => w[0]).join('').toUpperCase() || '?';
  }

  get ordersTotal(): string {
    const sum = this.orders.reduce((acc, o) => acc + this.toAmount(o.total), 0);
    return this.formatMoney(sum);
  }

  prettyStatus(s: string): string {
    return String(s ?? '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  statusClass(s: string): string {
    if (s === 'delivered') return 'ax-badge-success';
    if (s === 'cancelled' || s === 'refunded' || s === 'failed') return 'ax-badge-danger';
    if (s === 'pending_payment') return 'ax-badge-warning';
    return 'ax-badge-info';
  }

  private toAmount(v: unknown): number {
    if (v && typeof v === 'object' && 'amount' in (v as any)) return Number((v as any).amount) || 0;
    return Number(v) || 0;
  }

  money(v: unknown): string {
    return this.formatMoney(this.toAmount(v));
  }

  private formatMoney(n: number): string {
    return `AED ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }

  openOrder(o: CustomerOrder): void {
    this.router.navigate(['/admin/orders', o.id]);
  }

  toggleActive(): void {
    const activate = !this.isActive;
    this.confirm
      .confirm({
        title: activate ? 'Activate customer' : 'Deactivate customer',
        message: `${this.displayName || 'This customer'}'s account will be ${activate ? 'activated' : 'deactivated'}.`,
        confirmLabel: activate ? 'Activate' : 'Deactivate',
        cancelLabel: 'Cancel',
        variant: activate ? undefined : 'danger',
      })
      .then((ok) => {
        if (!ok) return;
        const req = activate
          ? this.adapter.post_v3('POST /admin/users/:id/activate', {}, { params: { id: String(this.id) } })
          : this.adapter.post_v3('POST /admin/users/:id/deactivate', {}, { params: { id: String(this.id) } });
        req.subscribe({
          next: (r: any) => {
            if (r) {
              this.isActive = activate;
              this.toast.success(`Customer ${activate ? 'activated' : 'deactivated'}.`);
            }
          },
        });
      });
  }

  back(): void {
    this.router.navigate(['/admin/customers']);
  }
}
