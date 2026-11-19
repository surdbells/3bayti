import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { parseCartCashback, type CartCashbackPreview, type CartCashbackQuery } from './cashback.model';

/** Direct v3 base, same convention as GiftCardService / OrderService. */
const V3_BASE = 'https://api-v3.3bayti.ae';

/**
 * CashbackService, the storefront's read of the cashback programme.
 *
 *   GET /v3/cart/cashback (auth) → estimated cashback for the current cart.
 *
 * Fail-safe: any error (signed out, network, older API) resolves to null so
 * the cart and checkout simply omit the cashback line.
 */
@Injectable({ providedIn: 'root' })
export class CashbackService {
  private readonly http = inject(HttpClient);

  async previewCart(query: CartCashbackQuery = {}): Promise<CartCashbackPreview | null> {
    let params = new HttpParams().set('channel', 'web');
    const promo = (query.promoCode ?? '').trim();
    if (promo !== '') params = params.set('promo_code', promo);
    const code = (query.giftCardCode ?? '').replace(/[\s-]/g, '').toUpperCase();
    if (code !== '') {
      params = params.set('gift_card_code', code);
    } else if (query.useGiftWallet === true) {
      params = params.set('use_gift_wallet', 'true');
    }
    try {
      const res = await firstValueFrom(
        this.http.get<{ data?: unknown }>(`${V3_BASE}/v3/cart/cashback`, { params }),
      );
      return parseCartCashback(res?.data);
    } catch {
      return null;
    }
  }
}
