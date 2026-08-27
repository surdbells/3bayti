import { Component, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { NavigationHistoryService } from '../../services/navigation-history.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';

/**
 * Admin coupon create / edit form.
 *
 * Create (no ?id) → POST /admin/promo-codes, which mints a PLATFORM-WIDE code
 * (vendor_id null → applies to the whole cart at checkout).
 * Edit (?id=123)  → GET to prefill, PUT to save. Editing works for any code,
 * including vendor-owned ones (admin moderation).
 *
 * Fields mirror the admin DTO (CreatePromoCodeInput / UpdatePromoCodeInput):
 * code, description, discount_type, discount_value, currency, min_subtotal,
 * max_discount_amount, usage_limit_global, usage_limit_per_user, valid_from,
 * valid_until, is_active.
 */
@Component({
  selector: 'app-admin-coupon-form',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent],
  templateUrl: './admin-coupon-form.component.html',
  styleUrl: './admin-coupon-form.component.css',
})
export class AdminCouponFormComponent implements OnInit {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly navHistory = inject(NavigationHistoryService);

  /** Present in edit mode. */
  id: number | null = null;
  ui = { loading: false, saving: false };

  form = {
    code: '',
    description: '',
    discount_type: 'percentage' as 'percentage' | 'fixed_amount',
    discount_value: null as number | null,
    currency: 'AED',
    min_subtotal: null as number | null,
    max_discount_amount: null as number | null,
    usage_limit_global: null as number | null,
    usage_limit_per_user: 1 as number | null,
    valid_from: '',
    valid_until: '',
    is_active: true,
  };

  get isEdit(): boolean {
    return this.id !== null;
  }

  ngOnInit(): void {
    const raw = this.route.snapshot.queryParamMap.get('id');
    const parsed = raw != null ? Number(raw) : NaN;
    if (Number.isFinite(parsed) && parsed > 0) {
      this.id = parsed;
      this.load(parsed);
    }
  }

  private load(id: number): void {
    this.ui.loading = true;
    this.adapter.get_v3('GET /admin/promo-codes/:id', { params: { id: String(id) } }).subscribe({
      next: (res: any) => {
        const c = res?.data ?? res;
        if (c) {
          this.form = {
            code: c.code ?? '',
            description: c.description ?? '',
            discount_type: c.discount_type === 'fixed_amount' ? 'fixed_amount' : 'percentage',
            discount_value: c.discount_value != null ? Number(c.discount_value) : null,
            currency: c.currency ?? 'AED',
            min_subtotal: c.min_subtotal != null ? Number(c.min_subtotal) : null,
            max_discount_amount: c.max_discount_amount != null ? Number(c.max_discount_amount) : null,
            usage_limit_global: c.usage_limit_global ?? null,
            usage_limit_per_user: c.usage_limit_per_user ?? null,
            valid_from: this.toLocalInput(c.valid_from),
            valid_until: this.toLocalInput(c.valid_until),
            is_active: !!c.is_active,
          };
        }
        this.ui.loading = false;
      },
      error: (err: any) => {
        this.ui.loading = false;
        this.toast.error(apiErrorMessage(err, 'Unable to load this coupon.'));
        this.goBack();
      },
    });
  }

  generateCode(): void {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let code = '';
    for (let i = 0; i < 10; i++) code += chars.charAt(Math.floor(Math.random() * chars.length));
    this.form.code = code;
  }

  private validate(): boolean {
    if (!this.form.code.trim()) {
      this.toast.error('A coupon code is required.');
      return false;
    }
    const value = Number(this.form.discount_value);
    if (!Number.isFinite(value) || value <= 0) {
      this.toast.error('Discount value must be greater than 0.');
      return false;
    }
    if (this.form.discount_type === 'percentage' && value > 100) {
      this.toast.error('A percentage discount cannot exceed 100%.');
      return false;
    }
    if (this.form.valid_from && this.form.valid_until
      && new Date(this.form.valid_until) < new Date(this.form.valid_from)) {
      this.toast.error('The end date must be on or after the start date.');
      return false;
    }
    return true;
  }

  save(): void {
    if (!this.validate()) return;
    this.ui.saving = true;

    const body: any = {
      code: this.form.code.trim(),
      description: this.form.description.trim() || null,
      discount_type: this.form.discount_type,
      discount_value: this.moneyStr(this.form.discount_value),
      currency: (this.form.currency || 'AED').toUpperCase(),
      min_subtotal: this.moneyOrNull(this.form.min_subtotal),
      max_discount_amount: this.form.discount_type === 'percentage'
        ? this.moneyOrNull(this.form.max_discount_amount)
        : null,
      usage_limit_global: this.intOrNull(this.form.usage_limit_global),
      usage_limit_per_user: this.intOrNull(this.form.usage_limit_per_user),
      valid_from: this.form.valid_from ? new Date(this.form.valid_from).toISOString() : null,
      valid_until: this.form.valid_until ? new Date(this.form.valid_until).toISOString() : null,
      is_active: this.form.is_active,
    };

    const req = this.isEdit
      ? this.adapter.put_v3('PUT /admin/promo-codes/:id', body, { params: { id: String(this.id) } })
      : this.adapter.post_v3('POST /admin/promo-codes', body);

    req.subscribe({
      next: () => {
        this.ui.saving = false;
        this.toast.success(this.isEdit ? 'Coupon updated.' : 'Coupon created.');
        this.router.navigate(['/admin/coupons']);
      },
      error: (err: any) => {
        this.ui.saving = false;
        this.toast.error(apiErrorMessage(err, this.isEdit ? 'Unable to update coupon.' : 'Unable to create coupon.'));
      },
    });
  }

  /** Money value → "10.00" string (or throws off validation earlier). */
  private moneyStr(v: number | null): string {
    return Number(v ?? 0).toFixed(2);
  }

  private moneyOrNull(v: number | null): string | null {
    if (v == null || !Number.isFinite(Number(v))) return null;
    return Number(v).toFixed(2);
  }

  private intOrNull(v: number | null): number | null {
    if (v == null || !Number.isFinite(Number(v))) return null;
    return Math.max(0, Math.trunc(Number(v)));
  }

  /**
   * ISO/ATOM datetime → 'YYYY-MM-DDTHH:mm' in the browser's LOCAL time, so it
   * round-trips cleanly with save (new Date(local).toISOString() → same UTC
   * instant). Slicing the raw UTC string instead would double-shift on save.
   */
  private toLocalInput(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(String(iso));
    if (isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }

  goBack(): void {
    this.navHistory.back('/admin/coupons');
  }
}
