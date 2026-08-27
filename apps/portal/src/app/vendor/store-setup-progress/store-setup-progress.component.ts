import { Component, Input, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { forkJoin, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { IconComponent } from '../../shared/icon/icon.component';

interface SetupStep {
  key: string;
  icon: string;
  title: string;
  desc: string;
  route: string;
  cta: string;
  done: boolean;
  /** Sub-field progress for multi-field steps (undefined for the product step). */
  filled?: number;
  total?: number;
}

/**
 * Store setup checklist for the vendor dashboard, the guided-onboarding card
 * (Shopify/Stripe style) that walks a new seller through everything needed to
 * start selling, with a progress bar, per-step field counts, actions, and a
 * highlighted "next step".
 *
 * Each step maps to one settings page and is only "done" when that whole form
 * is complete, the store profile, the full business/tax section, and the
 * payout details, plus a first product. Completion is read live from the
 * vendor's own endpoints; once every step is done the card collapses to a
 * dismissible banner (remembered in localStorage).
 */
@Component({
  selector: 'app-store-setup-progress',
  standalone: true,
  imports: [CommonModule, IconComponent],
  templateUrl: './store-setup-progress.component.html',
  styleUrl: './store-setup-progress.component.css',
})
export class StoreSetupProgressComponent implements OnInit {
  /** Live product count from the dashboard payload (catalog.total_products). */
  @Input() productCount = 0;

  private readonly adapter = inject(PortalCrudAdapter);
  private readonly router = inject(Router);

  private static readonly DISMISS_KEY = 'vendor_setup_dismissed';

  loaded = false;
  dismissed = false;

  private store: any = {};
  private tax: any = {};
  private payment: any = {};

  // Required fields per settings form (any listed key variant counts as filled).
  private readonly PROFILE_FIELDS = [
    ['name', 'store_name'],
    ['description', 'store_description'],
    ['contact_email', 'store_email'],
    ['contact_phone', 'store_phone'],
  ];
  private readonly TAX_FIELDS = [
    ['store_legal_name'], ['trade_license_number'], ['licensing_authority'],
    ['vat_status'], ['tax_registration_number'], ['registered_tax_address'],
    ['tax_contact_email'],
  ];
  private readonly PAYOUT_FIELDS = [
    ['store_bank_name', 'bank_name'],
    ['store_account_name', 'account_name'],
    ['store_account_number', 'account_number'],
  ];

  ngOnInit(): void {
    this.dismissed = localStorage.getItem(StoreSetupProgressComponent.DISMISS_KEY) === '1';
    if (this.dismissed) {
      return; // Already completed + dismissed, don't fetch or render.
    }

    // Each call defaults to null on error (e.g. a section never filled in yet)
    // so a single missing section can't blank the whole card.
    forkJoin({
      store: this.adapter.get_v3('GET /vendor/store').pipe(catchError(() => of(null))),
      payment: this.adapter.get_v3('GET /vendor/store/payment').pipe(catchError(() => of(null))),
      tax: this.adapter.get_v3('GET /vendor/store/tax').pipe(catchError(() => of(null))),
    }).subscribe(({ store, payment, tax }) => {
      this.store = this.body(store);
      this.payment = this.body(payment);
      this.tax = this.body(tax);
      this.loaded = true;
    });
  }

  private body(res: any): any {
    return res?.data ?? res ?? {};
  }

  /** First non-empty value across the given key variants, trimmed. */
  private val(obj: any, keys: string[]): string {
    for (const k of keys) {
      const v = obj?.[k];
      if (v !== undefined && v !== null && String(v).trim() !== '') {
        return String(v).trim();
      }
    }
    return '';
  }

  private countFilled(obj: any, fields: string[][]): number {
    return fields.filter((variants) => this.val(obj, variants) !== '').length;
  }

  /** Profile also needs a real (non-placeholder) logo, tracked as one field. */
  private get profileFilled(): number {
    const logo = this.val(this.store, ['logo_url', 'store_logo', 'logo']);
    const logoOk = logo !== '' && !logo.includes('placeholder');
    return this.countFilled(this.store, this.PROFILE_FIELDS) + (logoOk ? 1 : 0);
  }
  private get profileTotal(): number { return this.PROFILE_FIELDS.length + 1; }

  get steps(): SetupStep[] {
    const taxFilled = this.countFilled(this.tax, this.TAX_FIELDS);
    const payoutFilled = this.countFilled(this.payment, this.PAYOUT_FIELDS);
    return [
      {
        key: 'product', icon: 'inventory_2', title: 'Add your first product',
        desc: 'List a product so customers can start buying from your store.',
        route: '/products', cta: 'Add product', done: this.productCount > 0,
      },
      {
        key: 'profile', icon: 'storefront', title: 'Complete your store profile',
        desc: 'Store name, contact details, description and logo shoppers will see.',
        route: '/store', cta: 'Edit store',
        filled: this.profileFilled, total: this.profileTotal,
        done: this.profileFilled === this.profileTotal,
      },
      {
        key: 'tax', icon: 'request_quote', title: 'Add business & tax details',
        desc: 'Legal name, trade licence, VAT status and tax registration.',
        route: '/tax_information', cta: 'Add details',
        filled: taxFilled, total: this.TAX_FIELDS.length,
        done: taxFilled === this.TAX_FIELDS.length,
      },
      {
        key: 'payout', icon: 'account_balance', title: 'Add your payout details',
        desc: 'Bank name, account name and account number for your earnings.',
        route: '/payment_info', cta: 'Add details',
        filled: payoutFilled, total: this.PAYOUT_FIELDS.length,
        done: payoutFilled === this.PAYOUT_FIELDS.length,
      },
    ];
  }

  get doneCount(): number { return this.steps.filter((s) => s.done).length; }
  get total(): number { return this.steps.length; }
  get percent(): number { return Math.round((this.doneCount / this.total) * 100); }
  get allDone(): boolean { return this.doneCount === this.total; }
  get nextStep(): SetupStep | undefined { return this.steps.find((s) => !s.done); }

  go(route: string): void {
    this.router.navigate([route]);
  }

  dismiss(): void {
    this.dismissed = true;
    localStorage.setItem(StoreSetupProgressComponent.DISMISS_KEY, '1');
  }
}
