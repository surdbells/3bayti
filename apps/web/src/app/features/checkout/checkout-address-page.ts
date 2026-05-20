import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { CheckoutStepperComponent } from './checkout-stepper';
import { AddressService } from '../../core/addresses';
import type { Address } from '../../core/addresses';
import { AddressFormComponent } from '../addresses/address-form';
import { CheckoutService } from '../../core/checkout';
import { CartService } from '../../core/cart';
import { ToastService } from '../../shared/forms';

/**
 * /checkout/address — step 1 of 3.
 *
 * Lets the user pick a shipping address from their address book
 * (or add a new one inline), then advances to /checkout/review.
 *
 * Empty-cart guard
 * ----------------
 * If the cart is empty on landing we bounce back to /cart. The
 * checkout flow shouldn't be reachable from an empty cart but
 * this protects against direct URL navigation.
 *
 * Address picker
 * --------------
 * Renders the user's address book as a radio-group of cards. The
 * pre-selected card is (in order): the previously-chosen address
 * in checkout state → the default-shipping address → the first
 * available address. If the user has zero addresses we open the
 * inline create form straight away.
 *
 * No-address-yet UX
 * -----------------
 * The AddressFormComponent appears inline with no list shown.
 * After save, the new address becomes the selected shipping
 * address automatically.
 *
 * Promo carry-over
 * ----------------
 * The cart page may have set CheckoutService.promo_code. We don't
 * re-display it here (review step does); we just preserve it
 * across the address selection.
 *
 * Continue CTA
 * ------------
 * Disabled until a shipping address is selected. On click:
 *   - CheckoutService.setShippingAddress(id)
 *   - router.navigateByUrl('/checkout/review')
 *
 * Authentication
 * --------------
 * Route is authActivateGuard-protected. By the time this component
 * mounts the user is signed in.
 */
@Component({
  selector: 'app-checkout-address',
  standalone: true,
  imports: [
    NgIf,
    NgFor,
    TranslatePipe,
    CheckoutStepperComponent,
    AddressFormComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="checkout-page" data-testid="checkout-address-page">
      <div class="checkout-page__container">
        <h1 class="checkout-page__title">
          {{ 'checkout.address.title' | translate }}
        </h1>

        <app-checkout-stepper [activeStep]="0"></app-checkout-stepper>

        <ng-container *ngIf="!showAddForm(); else addFormBlock">
          <section
            *ngIf="addresses().length > 0; else loadingOrEmpty"
            class="checkout-page__section"
            aria-labelledby="address-picker-heading"
          >
            <h2 id="address-picker-heading" class="checkout-page__section-title">
              {{ 'checkout.address.pickHeading' | translate }}
            </h2>

            <ul class="checkout-page__addresses" role="radiogroup" aria-labelledby="address-picker-heading">
              <li
                *ngFor="let addr of addresses(); trackBy: trackById"
                class="address-pick"
                [class.address-pick--selected]="selectedId() === addr.id"
                role="radio"
                [attr.aria-checked]="selectedId() === addr.id"
                [attr.tabindex]="selectedId() === addr.id ? 0 : -1"
                (click)="onSelect(addr.id)"
                (keydown.enter)="onSelect(addr.id)"
                (keydown.space)="onSelect(addr.id); $event.preventDefault()"
                [attr.data-testid]="'address-pick-' + addr.id"
              >
                <div class="address-pick__radio" aria-hidden="true"></div>
                <div class="address-pick__body">
                  <div class="address-pick__head">
                    <span class="address-pick__label">
                      {{ addr.label || ('addresses.unlabeled' | translate) }}
                    </span>
                    <span
                      *ngIf="addr.is_default_shipping"
                      class="address-pick__badge"
                    >
                      {{ 'addresses.defaultBadge' | translate }}
                    </span>
                  </div>
                  <p class="address-pick__name">{{ addr.recipient_name }}</p>
                  <p class="address-pick__line">
                    <ng-container *ngIf="addr.street_address !== null">
                      {{ addr.street_address }},
                    </ng-container>
                    <ng-container *ngIf="addr.building_details !== null">
                      {{ addr.building_details }},
                    </ng-container>
                    {{ addr.area }}, {{ addr.emirate }}
                  </p>
                  <p class="address-pick__phone">{{ addr.recipient_phone }}</p>
                </div>
              </li>
            </ul>

            <button
              type="button"
              class="checkout-page__add-link"
              (click)="onStartAdd()"
              data-testid="checkout-add-address"
            >
              + {{ 'addresses.actions.add' | translate }}
            </button>
          </section>

          <ng-template #loadingOrEmpty>
            <p
              *ngIf="isLoading()"
              class="checkout-page__loading"
              data-testid="checkout-address-loading"
            >
              {{ 'common.loading' | translate }}
            </p>
            <ng-container *ngIf="!isLoading()">
              <!-- Auto-shown form below covers this case. -->
            </ng-container>
          </ng-template>
        </ng-container>

        <ng-template #addFormBlock>
          <section
            class="checkout-page__section"
            aria-labelledby="add-address-heading"
            data-testid="checkout-address-form-section"
          >
            <h2 id="add-address-heading" class="checkout-page__section-title">
              {{ 'checkout.address.addHeading' | translate }}
            </h2>
            <app-address-form
              (saved)="onAddressSaved($event)"
              (cancelled)="onCancelAdd()"
            ></app-address-form>
          </section>
        </ng-template>

        <div class="checkout-page__actions">
          <button
            type="button"
            class="checkout-page__back"
            (click)="onBack()"
            data-testid="checkout-back-to-cart"
          >
            ← {{ 'checkout.address.backToCart' | translate }}
          </button>
          <button
            type="button"
            class="checkout-page__continue"
            [disabled]="!canContinue()"
            (click)="onContinue()"
            data-testid="checkout-continue"
          >
            {{ 'checkout.address.continue' | translate }}
          </button>
        </div>
      </div>
    </main>
  `,
  styleUrl: './checkout.scss',
})
export class CheckoutAddressPageComponent implements OnInit {
  private readonly addressService = inject(AddressService);
  private readonly checkout = inject(CheckoutService);
  private readonly cart = inject(CartService);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);

  protected readonly addresses = this.addressService.addresses;
  protected readonly isLoading = this.addressService.isLoading;

  private readonly _showAddForm = signal(false);
  protected readonly showAddForm = this._showAddForm.asReadonly();

  /** The currently-selected address id. Initialized to whatever
   *  CheckoutService has on landing; falls back to default-shipping
   *  → first available in onAddressesReady. */
  private readonly _selectedId = signal<number | null>(null);
  protected readonly selectedId = this._selectedId.asReadonly();

  protected readonly canContinue = computed(() => {
    return this._selectedId() !== null && !this.showAddForm();
  });

  async ngOnInit(): Promise<void> {
    /* Empty-cart bounce guard. */
    if (this.cart.cart().items.length === 0) {
      await this.router.navigateByUrl('/cart');
      return;
    }

    /* Seed selectedId from checkout state if present. */
    const prior = this.checkout.shippingAddressId();
    if (prior !== null) this._selectedId.set(prior);

    try {
      const list = await this.addressService.list();
      this.onAddressesReady(list);
    } catch {
      this.toast.error('addresses.errors.loadFailed');
    }
  }

  private onAddressesReady(list: Address[]): void {
    if (list.length === 0) {
      /* No addresses → show the inline create form straight away. */
      this._showAddForm.set(true);
      return;
    }

    /* If we don't yet have a selection (or our prior selection no
       longer exists), fall back to default-shipping → first. */
    const currentId = this._selectedId();
    const stillExists = list.some(a => a.id === currentId);
    if (currentId === null || !stillExists) {
      const def = list.find(a => a.is_default_shipping) ?? list[0];
      this._selectedId.set(def.id);
    }
  }

  protected trackById(_idx: number, addr: Address): number {
    return addr.id;
  }

  protected onSelect(id: number): void {
    this._selectedId.set(id);
  }

  protected onStartAdd(): void {
    this._showAddForm.set(true);
  }

  protected onCancelAdd(): void {
    /* If the user had no addresses they can't cancel into nothing —
       keep them on the form. (The cancel button hides in that case
       via the form's own UX; here we double-check.) */
    if (this.addresses().length === 0) return;
    this._showAddForm.set(false);
  }

  protected onAddressSaved(addr: Address): void {
    /* Select the newly-created address and close the form. */
    this._selectedId.set(addr.id);
    this._showAddForm.set(false);
    this.toast.success('addresses.toast.saved');
  }

  protected async onBack(): Promise<void> {
    await this.router.navigateByUrl('/cart');
  }

  protected async onContinue(): Promise<void> {
    const id = this._selectedId();
    if (id === null) return;
    this.checkout.setShippingAddress(id);
    await this.router.navigateByUrl('/checkout/review');
  }
}
