import { Injectable, Signal, signal, computed, inject, PLATFORM_ID } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import type {
  CheckoutState,
  InitiateCheckoutInput,
  InitiateCheckoutResponse,
} from './checkout.types';
import { EMPTY_CHECKOUT_STATE } from './checkout.types';

/**
 * CheckoutService — multi-step checkout state machine.
 *
 * Holds in-progress state (selected addresses, promo code) in
 * sessionStorage, exposes signals for the UI, and offers the
 * initiate() call that converts cart → order + returns the
 * Noon-hosted checkout URL.
 *
 * Why a signal-driven state machine rather than route data
 * --------------------------------------------------------
 * The three checkout routes need a shared, refresh-survivable state.
 * Putting it in route data would lose on refresh; putting it on each
 * component would require child-route coupling we don't want. A
 * service signal + sessionStorage hits the sweet spot.
 */

const SESSION_KEY = 'bayti_checkout_state_v1';
const V3_BASE = 'https://api-v3.3bayti.ae';

@Injectable({ providedIn: 'root' })
export class CheckoutService {
  private readonly _state = signal<CheckoutState>(EMPTY_CHECKOUT_STATE);
  private readonly _isInitiating = signal<boolean>(false);

  readonly state: Signal<CheckoutState> = this._state.asReadonly();
  readonly shippingAddressId = computed(() => this._state().shipping_address_id);
  readonly billingAddressId = computed(() => this._state().billing_address_id);
  readonly promoCode = computed(() => this._state().promo_code);
  readonly isInitiating: Signal<boolean> = this._isInitiating.asReadonly();

  /** Convenience: true when a shipping address has been chosen. */
  readonly hasShippingAddress = computed(() => this._state().shipping_address_id !== null);

  private readonly platformId = inject(PLATFORM_ID);
  private readonly http = inject(HttpClient);

  constructor() {
    if (isPlatformBrowser(this.platformId)) {
      this._state.set(this.loadFromStorage());
    }
  }

  /* -----------------------------------------------------------------
     State mutations
     ----------------------------------------------------------------- */

  setShippingAddress(addressId: number): void {
    this.update(s => ({
      ...s,
      shipping_address_id: addressId,
      /* Default billing to the same address unless the user explicitly
         chose a different one earlier. */
      billing_address_id: s.billing_address_id ?? addressId,
    }));
  }

  setBillingAddress(addressId: number | null): void {
    this.update(s => ({ ...s, billing_address_id: addressId }));
  }

  setPromoCode(code: string | null): void {
    this.update(s => ({ ...s, promo_code: code }));
  }

  clear(): void {
    this._state.set(EMPTY_CHECKOUT_STATE);
    this.clearStorage();
  }

  private update(fn: (state: CheckoutState) => CheckoutState): void {
    const next = fn(this._state());
    this._state.set(next);
    this.saveToStorage(next);
  }

  /* -----------------------------------------------------------------
     Initiate (cart → order + Noon URL)
     ----------------------------------------------------------------- */

  async initiate(input: InitiateCheckoutInput): Promise<InitiateCheckoutResponse> {
    this._isInitiating.set(true);
    try {
      const response = await firstValueFrom(
        this.http.post<InitiateCheckoutResponse>(
          `${V3_BASE}/v3/checkout/initiate`,
          input,
        ),
      );
      return response;
    } finally {
      this._isInitiating.set(false);
    }
  }

  /* -----------------------------------------------------------------
     SessionStorage helpers
     ----------------------------------------------------------------- */

  private loadFromStorage(): CheckoutState {
    if (!isPlatformBrowser(this.platformId)) return EMPTY_CHECKOUT_STATE;
    try {
      const raw = sessionStorage.getItem(SESSION_KEY);
      if (raw === null) return EMPTY_CHECKOUT_STATE;
      const parsed = JSON.parse(raw) as Partial<CheckoutState>;
      return {
        shipping_address_id: typeof parsed.shipping_address_id === 'number' ? parsed.shipping_address_id : null,
        billing_address_id: typeof parsed.billing_address_id === 'number' ? parsed.billing_address_id : null,
        promo_code: typeof parsed.promo_code === 'string' ? parsed.promo_code : null,
      };
    } catch {
      return EMPTY_CHECKOUT_STATE;
    }
  }

  private saveToStorage(state: CheckoutState): void {
    if (!isPlatformBrowser(this.platformId)) return;
    try {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify(state));
    } catch {
      /* Quota exceeded — silently ignore. State will not survive
         refresh but the in-memory signal stays valid. */
    }
  }

  private clearStorage(): void {
    if (!isPlatformBrowser(this.platformId)) return;
    try {
      sessionStorage.removeItem(SESSION_KEY);
    } catch {
      /* ignore */
    }
  }
}
