import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

/**
 * Cashback programme (paid out as gift cards), customer side.
 *
 * Cashback is platform-funded and is paid as a gift card that lands in the
 * customer's gift-card wallet once the order is delivered and the rule's
 * hold days have passed. An order that is paid with ANY cashback gift card
 * earns no cashback at all; the API enforces that, the app only explains it.
 *
 * Shapes (API contract):
 *   GET /v3/cart/cashback → { estimated_amount, currency, eligible, reason,
 *       hold_days, uses_cashback_credit, wallet_has_cashback_credit }
 *   product detail  .cashback → { type, value, max, channel, … } | null
 *   order detail    .cashback → { status, amount, currency, release_at,
 *       gift_card_id } | null
 *   gift cards      .source   → 'purchase' | 'admin' | 'cashback'
 */

export interface CartCashbackEstimate {
  /** Decimal string, e.g. "12.50". */
  estimated_amount: string;
  currency: string;
  eligible: boolean;
  /** Why nothing is earned (program_disabled, paid_with_cashback, …); null when eligible. */
  reason: string | null;
  hold_days: number | null;
  /** The selected payment (code or wallet draw) spends a cashback card. */
  uses_cashback_credit: boolean;
  /** The customer's wallet holds at least one spendable cashback card. */
  wallet_has_cashback_credit: boolean;
}

export interface CartCashbackQuery {
  promoCode?: string | null;
  giftCardCode?: string | null;
  useGiftWallet?: boolean;
}

export interface ProductCashbackBadge {
  type: 'percentage' | 'fixed';
  /** Decimal string ("5.00" = 5% or AED 5). */
  value: string;
  /** Per-order cap, decimal string, or null when uncapped. */
  max: string | null;
  channel: string;
}

export type OrderCashbackStatus = 'pending' | 'issued' | 'cancelled' | 'reversed';

export interface OrderCashback {
  status: OrderCashbackStatus;
  amount: string;
  currency: string;
  /** ISO date the gift card is expected to be issued; null until it is known. */
  release_at: string | null;
  gift_card_id: number | null;
}

export const GIFT_CARD_SOURCE_CASHBACK = 'cashback';

const ORDER_STATUSES: readonly OrderCashbackStatus[] = ['pending', 'issued', 'cancelled', 'reversed'];

function isRecord(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function decimalString(v: unknown): string | null {
  if (typeof v === 'number' && Number.isFinite(v)) return v.toFixed(2);
  if (typeof v === 'string' && v.trim() !== '' && Number.isFinite(Number(v))) return v.trim();
  return null;
}

/** GET /v3/cart/cashback data → typed estimate, or null when unusable. */
export function parseCartCashback(data: unknown): CartCashbackEstimate | null {
  if (!isRecord(data)) return null;
  const amount = decimalString(data['estimated_amount']);
  if (amount === null) return null;
  const hold = Number(data['hold_days']);
  return {
    estimated_amount: amount,
    currency: typeof data['currency'] === 'string' && data['currency'] !== '' ? data['currency'] : 'AED',
    eligible: data['eligible'] === true && Number(amount) > 0,
    reason: typeof data['reason'] === 'string' ? data['reason'] : null,
    hold_days: data['hold_days'] !== null && data['hold_days'] !== undefined && Number.isFinite(hold) ? hold : null,
    uses_cashback_credit: data['uses_cashback_credit'] === true,
    wallet_has_cashback_credit: data['wallet_has_cashback_credit'] === true,
  };
}

/**
 * Product detail `cashback` → badge, or null. A rule limited to the web
 * channel never pays on an app order, so the app hides it.
 */
export function parseProductCashback(raw: unknown): ProductCashbackBadge | null {
  if (!isRecord(raw)) return null;
  const type = raw['type'];
  if (type !== 'percentage' && type !== 'fixed') return null;
  const value = decimalString(raw['value']);
  if (value === null || Number(value) <= 0) return null;
  const channel = typeof raw['channel'] === 'string' ? raw['channel'] : 'all';
  if (channel === 'web') return null;
  const max = decimalString(raw['max']);
  return { type, value, max: max !== null && Number(max) > 0 ? max : null, channel };
}

/** Order detail `cashback` → typed summary, or null when the order earns none. */
export function parseOrderCashback(raw: unknown): OrderCashback | null {
  if (!isRecord(raw)) return null;
  const status = raw['status'];
  if (typeof status !== 'string' || !(ORDER_STATUSES as readonly string[]).includes(status)) return null;
  const amount = decimalString(raw['amount']);
  if (amount === null) return null;
  const releaseAt = typeof raw['release_at'] === 'string' && raw['release_at'] !== '' ? raw['release_at'] : null;
  const gc = Number(raw['gift_card_id']);
  return {
    status: status as OrderCashbackStatus,
    amount,
    currency: typeof raw['currency'] === 'string' && raw['currency'] !== '' ? raw['currency'] : 'AED',
    release_at: releaseAt,
    gift_card_id: raw['gift_card_id'] !== null && raw['gift_card_id'] !== undefined && Number.isInteger(gc) && gc > 0 ? gc : null,
  };
}

/** True for a gift card that was issued as cashback. */
export function isCashbackGiftCard(card: unknown): boolean {
  return isRecord(card) && card['source'] === GIFT_CARD_SOURCE_CASHBACK;
}

/** "5.00" → "5", "2.50" → "2.5": the reward value as shown in a badge. */
export function formatCashbackValue(value: string): string {
  const n = Number(value);
  if (!Number.isFinite(n)) return value;
  return String(Math.round(n * 100) / 100);
}

@Injectable({ providedIn: 'root' })
export class CashbackService {
  constructor(private adapter: MobileNetworkAdapter) {}

  /**
   * Estimated cashback for the signed-in customer's cart. Read-only and
   * best-effort: any failure resolves to null so the line simply stays
   * hidden. Pass what checkout will send (promo, gift card code or wallet)
   * so the estimate reflects the paid amount and the cashback-credit guard.
   */
  async estimateForCart(token: string, query: CartCashbackQuery = {}): Promise<CartCashbackEstimate | null> {
    if (!token) return null;
    const queryParams: Record<string, string> = { channel: 'mobile' };
    const promo = (query.promoCode ?? '').trim();
    if (promo !== '') queryParams['promo_code'] = promo;
    const code = (query.giftCardCode ?? '').trim();
    if (code !== '') {
      queryParams['gift_card_code'] = code;
    } else if (query.useGiftWallet) {
      queryParams['use_gift_wallet'] = 'true';
    }
    try {
      const res: any = await firstValueFrom(
        this.adapter.get_v3('GET /cart/cashback', { authToken: token, queryParams }),
      );
      if (res?.response_code === 200 && res?.status === 'success') {
        return parseCartCashback(res.data);
      }
      return null;
    } catch {
      return null;
    }
  }
}
