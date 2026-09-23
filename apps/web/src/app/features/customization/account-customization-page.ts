import {
  Component,
  ChangeDetectionStrategy,
  OnInit,
  inject,
  signal,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';
import { ToastService } from '../../shared/forms';
import { CheckoutService } from '../../core/checkout/checkout.service';
import { CustomizationService } from './customization.service';
import { markCustomizationCheckout } from './customization-checkout-handoff';
import type { CustomizationRequest } from './customization.model';

/**
 * /account/customization-requests — the customer's bespoke-customization
 * requests (P5). Lists each request with the vendor's quote (when given) and
 * the contextual action: accept/reject a quote, cancel a pending/quoted
 * request, or pay an accepted quote (→ Noon checkout).
 *
 * Structurally mirrors the Following page (paginated, three-state, OnPush,
 * signal-backed, optimistic in-place updates).
 */
@Component({
  selector: 'app-account-customization-page',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, CfImagePipe],
  template: `
    <section class="account-cust">
      <div class="account-cust__container">
        <nav class="account-cust__breadcrumb" aria-label="Breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.customizationRequests.title' | translate }}</span>
        </nav>
        <h1 class="account-cust__title">{{ 'account.customizationRequests.title' | translate }}</h1>

        <ng-container *ngIf="!isInitialLoading(); else loadingState">
          <ng-container *ngIf="requests().length > 0; else emptyState">
            <ul class="account-cust__list" data-testid="cust-list">
              <li
                *ngFor="let req of requests(); trackBy: trackById"
                class="account-cust__card"
                [attr.data-testid]="'cust-card-' + req.id"
              >
                <a class="account-cust__thumb" [routerLink]="['/product', req.product.slug]">
                  <img
                    *ngIf="(req.product.primary_image ?? '') !== ''; else thumbBlank"
                    [src]="req.product.primary_image | cfImage: 'thumb'"
                    [alt]="req.product.name"
                    loading="lazy"
                  />
                  <ng-template #thumbBlank><span class="account-cust__thumb-blank" aria-hidden="true"></span></ng-template>
                </a>

                <div class="account-cust__body">
                  <div class="account-cust__head">
                    <a class="account-cust__name" [routerLink]="['/product', req.product.slug]">{{ req.product.name }}</a>
                    <span class="account-cust__status" [attr.data-status]="req.status">
                      {{ 'account.customizationRequests.statuses.' + req.status | translate }}
                    </span>
                  </div>

                  <p class="account-cust__vendor">{{ req.vendor.name }}</p>
                  <p class="account-cust__notes">{{ req.customer_notes }}</p>

                  <div class="account-cust__quote" *ngIf="req.quote as q">
                    <span class="account-cust__price">{{ q.amount }} {{ q.currency ?? 'AED' }}</span>
                    <span class="account-cust__lead" *ngIf="q.lead_time_days !== null">
                      · {{ 'account.customizationRequests.leadTime' | translate: { days: q.lead_time_days } }}
                    </span>
                    <p class="account-cust__vendor-note" *ngIf="(q.vendor_notes ?? '') !== ''">“{{ q.vendor_notes }}”</p>
                  </div>

                  <div class="account-cust__actions">
                    <!-- quoted → accept / reject -->
                    <ng-container *ngIf="req.status === 'quoted'">
                      <button
                        type="button"
                        class="account-cust__btn account-cust__btn--primary"
                        [disabled]="isBusy(req.id)"
                        (click)="accept(req)"
                        [attr.data-testid]="'cust-accept-' + req.id"
                      >{{ 'account.customizationRequests.accept' | translate }}</button>
                      <button
                        type="button"
                        class="account-cust__btn account-cust__btn--ghost"
                        [disabled]="isBusy(req.id)"
                        (click)="reject(req)"
                      >{{ 'account.customizationRequests.reject' | translate }}</button>
                    </ng-container>

                    <!-- accepted → pay -->
                    <button
                      *ngIf="req.status === 'accepted'"
                      type="button"
                      class="account-cust__btn account-cust__btn--primary"
                      [disabled]="isBusy(req.id)"
                      (click)="pay(req)"
                      [attr.data-testid]="'cust-pay-' + req.id"
                    >{{ 'account.customizationRequests.pay' | translate }}</button>

                    <!-- pending or quoted → cancel -->
                    <button
                      *ngIf="req.status === 'pending' || req.status === 'quoted'"
                      type="button"
                      class="account-cust__btn account-cust__btn--ghost"
                      [disabled]="isBusy(req.id)"
                      (click)="cancel(req)"
                    >{{ 'account.customizationRequests.cancel' | translate }}</button>
                  </div>
                </div>
              </li>
            </ul>

            <div class="account-cust__more" *ngIf="hasMore()">
              <button
                type="button"
                class="account-cust__btn account-cust__btn--ghost"
                [disabled]="isLoading()"
                (click)="onLoadMore()"
              >{{ 'account.customizationRequests.loadMore' | translate }}</button>
            </div>
          </ng-container>

          <ng-template #emptyState>
            <div class="account-cust__empty" data-testid="cust-empty">
              <p>{{ 'account.customizationRequests.empty' | translate }}</p>
              <a routerLink="/" class="account-cust__btn account-cust__btn--primary">
                {{ 'account.customizationRequests.browse' | translate }}
              </a>
            </div>
          </ng-template>
        </ng-container>

        <ng-template #loadingState>
          <div class="account-cust__loading" aria-live="polite">
            {{ 'account.customizationRequests.loading' | translate }}
          </div>
        </ng-template>
      </div>
    </section>
  `,
  styleUrl: './account-customization.scss',
})
export class AccountCustomizationPageComponent implements OnInit {
  private readonly service = inject(CustomizationService);
  private readonly checkout = inject(CheckoutService);
  private readonly toast = inject(ToastService);

  private readonly _requests = signal<CustomizationRequest[]>([]);
  protected readonly requests = this._requests.asReadonly();
  private readonly _hasMore = signal<boolean>(false);
  protected readonly hasMore = this._hasMore.asReadonly();
  protected readonly isLoading = this.service.isLoading;
  /** Ids with an in-flight action (accept/reject/cancel/pay). */
  private readonly _busy = signal<ReadonlySet<number>>(new Set());
  private hasLoadedOnce = false;

  protected isInitialLoading = (): boolean => this.isLoading() && !this.hasLoadedOnce;
  protected isBusy = (id: number): boolean => this._busy().has(id);

  async ngOnInit(): Promise<void> {
    await this.load(0);
  }

  private async load(offset: number): Promise<void> {
    try {
      const page = await this.service.list({ offset });
      this._requests.set(offset === 0 ? page.items : [...this._requests(), ...page.items]);
      this._hasMore.set(page.hasMore);
    } catch {
      this.toast.error('account.customizationRequests.errors.loadFailed');
    } finally {
      this.hasLoadedOnce = true;
    }
  }

  protected onLoadMore(): void {
    void this.load(this._requests().length);
  }

  protected async accept(req: CustomizationRequest): Promise<void> {
    await this.runAction(req.id, () => this.service.accept(req.id));
  }

  protected async reject(req: CustomizationRequest): Promise<void> {
    await this.runAction(req.id, () => this.service.reject(req.id));
  }

  protected async cancel(req: CustomizationRequest): Promise<void> {
    await this.runAction(req.id, () => this.service.cancel(req.id));
  }

  private async runAction(
    id: number,
    action: () => Promise<CustomizationRequest>,
  ): Promise<void> {
    if (this.isBusy(id)) return;
    this.setBusy(id, true);
    try {
      const updated = await action();
      this.replaceItem(updated);
    } catch {
      this.toast.error('account.customizationRequests.errors.actionFailed');
    } finally {
      this.setBusy(id, false);
    }
  }

  /** Pay an accepted quote via the Noon checkout (synthetic order). */
  protected async pay(req: CustomizationRequest): Promise<void> {
    if (this.isBusy(req.id)) return;
    this.setBusy(req.id, true);
    try {
      const res = await this.checkout.initiate({
        channel: 'web',
        delivery_fee: '0.00',
        discount: '0.00',
        customization_request_id: req.id,
      });
      if (!res.checkout_url) {
        throw new Error('checkout_url missing for customization payment');
      }
      markCustomizationCheckout(res.order_reference);
      // Navigating away — leave the busy flag set so the button stays disabled.
      window.location.assign(res.checkout_url);
    } catch {
      this.toast.error('account.customizationRequests.errors.payFailed');
      this.setBusy(req.id, false);
    }
  }

  private replaceItem(updated: CustomizationRequest): void {
    this._requests.set(this._requests().map((r) => (r.id === updated.id ? updated : r)));
  }

  private setBusy(id: number, busy: boolean): void {
    const next = new Set(this._busy());
    if (busy) {
      next.add(id);
    } else {
      next.delete(id);
    }
    this._busy.set(next);
  }

  protected trackById(_index: number, req: CustomizationRequest): number {
    return req.id;
  }
}
