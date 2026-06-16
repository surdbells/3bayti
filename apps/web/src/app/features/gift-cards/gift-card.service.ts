import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import {
  GiftCard,
  GiftCardCartPreview,
  GiftCardPurchaseInput,
  GiftCardTheme,
  GiftCardThemeMeta,
  GiftCardThemeOption,
  GIFT_CARD_THEME_ORDER,
} from './gift-card.model';

/**
 * Direct v3 base — matches the convention in OrderService, wishlist and
 * profile services (each declares this locally rather than importing a
 * shared const). The auth Bearer is attached by the HTTP interceptor;
 * the two public endpoints (themes, balance) simply carry no token when
 * the visitor is logged out.
 */
const V3_BASE = 'https://api-v3.3bayti.ae';

/** v3 envelope wrapper. */
interface Envelope<T> {
  data: T;
}

/** Raw `/themes` payload: a map keyed by theme. */
type ThemesPayload = Record<
  GiftCardTheme,
  GiftCardThemeMeta & { presets: string[]; min_denomination: string; max_denomination: string }
>;

/**
 * GiftCardService — storefront gift-card operations (M3.5 / Phase E).
 *
 * Endpoints
 * ---------
 *   GET  /v3/gift-cards/themes        (public)  → theme catalogue + presets
 *   GET  /v3/gift-cards/balance?code= (public)  → a single card by code
 *   GET  /v3/gift-cards/mine          (auth)    → the buyer's owned cards
 *   POST /v3/gift-cards/purchase      (auth)    → a pending card to fund
 *   POST /v3/gift-cards/redeem        (auth)    → claim a code into the account
 *   POST /v3/cart/gift-card           (auth)    → preview a card against the cart
 *
 * NOTE: there is no customer "activate" call — activation happens
 * server-side via the Noon webhook once the funding order is paid
 * (POST /v3/gift-cards/{id}/activate is admin-only).
 *
 * Stateless by design: pages own their own view state (a card can be
 * open in multiple tabs); this service is a thin typed transport.
 */
@Injectable({ providedIn: 'root' })
export class GiftCardService {
  private readonly http = inject(HttpClient);

  /** Public — the 6 themes with palette + denomination presets, ordered. */
  async getThemes(): Promise<GiftCardThemeOption[]> {
    const res = await firstValueFrom(
      this.http.get<Envelope<ThemesPayload>>(`${V3_BASE}/v3/gift-cards/themes`),
    );
    const map = res?.data ?? ({} as ThemesPayload);
    return GIFT_CARD_THEME_ORDER.filter((t) => map[t]).map((theme) => ({
      theme,
      ...map[theme],
    }));
  }

  /** Public — look up a single card by its code (for balance check / redeem preview). */
  async checkBalance(code: string): Promise<GiftCard> {
    const params = new HttpParams().set('code', this.normaliseCode(code));
    const res = await firstValueFrom(
      this.http.get<Envelope<GiftCard>>(`${V3_BASE}/v3/gift-cards/balance`, { params }),
    );
    return res.data;
  }

  /** Auth — every gift card the signed-in buyer owns or received. */
  async listMine(): Promise<GiftCard[]> {
    const res = await firstValueFrom(
      this.http.get<Envelope<GiftCard[]>>(`${V3_BASE}/v3/gift-cards/mine`),
    );
    return Array.isArray(res?.data) ? res.data : [];
  }

  /**
   * Auth — create a card in `pending_payment`. The returned card's `id`
   * is then funded via POST /v3/checkout/initiate { gift_card_purchase_id }.
   */
  async purchase(input: GiftCardPurchaseInput): Promise<GiftCard> {
    const res = await firstValueFrom(
      this.http.post<Envelope<GiftCard>>(`${V3_BASE}/v3/gift-cards/purchase`, input),
    );
    return res.data;
  }

  /** Auth — claim a gift-card code into the signed-in account. */
  async redeem(code: string): Promise<GiftCard> {
    const res = await firstValueFrom(
      this.http.post<Envelope<GiftCard>>(`${V3_BASE}/v3/gift-cards/redeem`, {
        code: this.normaliseCode(code),
      }),
    );
    return res.data;
  }

  /**
   * Auth — preview how much of a card applies to the current cart.
   * Pure read; no balance is debited (the debit happens at checkout).
   */
  async previewCartApply(code: string): Promise<GiftCardCartPreview> {
    const res = await firstValueFrom(
      this.http.post<Envelope<GiftCardCartPreview>>(`${V3_BASE}/v3/cart/gift-card`, {
        code: this.normaliseCode(code),
      }),
    );
    return res.data;
  }

  /** Strip hyphens + spaces and upper-case, matching the server's normalisation. */
  private normaliseCode(code: string): string {
    return (code ?? '').replace(/[\s-]/g, '').toUpperCase();
  }
}
