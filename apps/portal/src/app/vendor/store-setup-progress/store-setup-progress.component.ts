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
}

/**
 * Store setup checklist for the vendor dashboard — the guided-onboarding card
 * (Shopify/Stripe style) that walks a new seller through the steps needed to
 * start selling, with a progress bar, per-step actions, and a highlighted
 * "next step".
 *
 * Completion is read live from the vendor's own endpoints (store profile,
 * payout details, tax info) plus the product count passed in from the
 * dashboard. Once every step is done the vendor can dismiss the card, which is
 * remembered in localStorage so established stores aren't nagged.
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

  private profileDone = false;
  private paymentDone = false;
  private taxDone = false;

  ngOnInit(): void {
    this.dismissed = localStorage.getItem(StoreSetupProgressComponent.DISMISS_KEY) === '1';
    if (this.dismissed) {
      return; // Already completed + dismissed — don't fetch or render.
    }

    // Each call defaults to null on error (e.g. a section never filled in yet)
    // so a single missing section can't blank the whole card.
    forkJoin({
      store: this.adapter.get_v3('GET /vendor/store').pipe(catchError(() => of(null))),
      payment: this.adapter.get_v3('GET /vendor/store/payment').pipe(catchError(() => of(null))),
      tax: this.adapter.get_v3('GET /vendor/store/tax').pipe(catchError(() => of(null))),
    }).subscribe(({ store, payment, tax }) => {
      const s = this.body(store);
      const p = this.body(payment);
      const t = this.body(tax);

      const desc = String(s.description ?? s.store_description ?? '').trim();
      const logo = String(s.store_logo ?? s.logo_url ?? s.logo ?? '').trim();
      this.profileDone = desc !== '' && logo !== '' && !logo.includes('placeholder');

      this.paymentDone = String(p.store_account_number ?? p.account_number ?? '').trim() !== '';

      this.taxDone = String(t.tax_registration_number ?? '').trim() !== ''
        || String(t.licensing_authority ?? '').trim() !== '';

      this.loaded = true;
    });
  }

  private body(res: any): any {
    return res?.data ?? res ?? {};
  }

  get steps(): SetupStep[] {
    return [
      {
        key: 'product', icon: 'inventory_2', title: 'Add your first product',
        desc: 'List a product so customers can start buying from your store.',
        route: '/products', cta: 'Add product', done: this.productCount > 0,
      },
      {
        key: 'profile', icon: 'storefront', title: 'Complete your store profile',
        desc: 'Add your logo, cover image and a description shoppers will see.',
        route: '/store', cta: 'Edit store', done: this.profileDone,
      },
      {
        key: 'payment', icon: 'account_balance', title: 'Add your payout details',
        desc: 'Tell us where to send the earnings from your sales.',
        route: '/payment_info', cta: 'Add details', done: this.paymentDone,
      },
      {
        key: 'tax', icon: 'request_quote', title: 'Add tax information',
        desc: 'Provide your trade licence and tax registration details.',
        route: '/tax_information', cta: 'Add details', done: this.taxDone,
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
