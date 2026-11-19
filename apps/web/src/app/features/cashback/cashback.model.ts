/**
 * Cashback programme, storefront types + defensive parsers.
 *
 * Cashback is paid out as a gift card (source = 'cashback') after the order
 * is delivered plus a per-rule hold. An order that spends ANY cashback gift
 * card earns no cashback at all. The API shapes this mirrors:
 *
 *   GET /v3/products/:slug       → data.cashback: {type, value, max,
 *                                    min_eligible_amount, channel,
 *                                    first_order_only, allow_with_promo} | null
 *   GET /v3/cart/cashback        → data: {estimated_amount, currency, eligible,
 *                                    reason, hold_days, uses_cashback_credit,
 *                                    wallet_has_cashback_credit}
 *   GET /v3/orders/:id           → order.cashback: {status, amount, currency,
 *                                    release_at, gift_card_id} | null
 *   gift card payloads           → source: 'purchase' | 'admin' | 'cashback'
 *
 * Every parser takes `unknown` and returns null (or a safe default) for
 * anything malformed, so an older API without these fields, or a partial
 * payload, simply hides the cashback UI instead of breaking the page.
 */

export type CashbackRewardType = 'percentage' | 'fixed';
export type CashbackChannel = 'all' | 'web' | 'mobile';
export type CashbackAwardStatus = 'pending' | 'issued' | 'cancelled' | 'reversed';

/** PDP "Earn X cashback" badge (best live rule for the product). */
export interface ProductCashbackBadge {
  type: CashbackRewardType;
  /** Percent ("5.00") or fixed AED amount ("10.00"). */
  value: string;
  /** Per-order cap in AED, or null when uncapped. */
  max: string | null;
  min_eligible_amount: string | null;
  channel: CashbackChannel;
  first_order_only: boolean;
  allow_with_promo: boolean;
}

/** GET /v3/cart/cashback, the "You'll earn ≈ AED X cashback" estimate. */
export interface CartCashbackPreview {
  estimated_amount: string;
  currency: string;
  /** True only when the estimate is a positive amount. */
  eligible: boolean;
  /** Why nothing is earned (program_disabled, paid_with_cashback, ...). */
  reason: string | null;
  /** Days after delivery before the gift card is issued (eligible only). */
  hold_days: number | null;
  /** The payment selection (code / wallet) would spend a cashback card. */
  uses_cashback_credit: boolean;
  /** The customer's wallet holds at least one spendable cashback card. */
  wallet_has_cashback_credit: boolean;
}

/** order.cashback on the order detail payload. */
export interface OrderCashback {
  status: CashbackAwardStatus;
  amount: string;
  currency: string;
  /** Pending: expected release date (null until fulfilment settles). Issued: when it was released. */
  release_at: string | null;
  /** The issued cashback gift card (v3 id), when there is one. */
  gift_card_id: number | null;
}

/** Query options for GET /v3/cart/cashback (mirror what checkout will send). */
export interface CartCashbackQuery {
  promoCode?: string | null;
  giftCardCode?: string | null;
  useGiftWallet?: boolean;
}

export const CASHBACK_REASON_PAID_WITH_CASHBACK = 'paid_with_cashback';

const AWARD_STATUSES: readonly CashbackAwardStatus[] = ['pending', 'issued', 'cancelled', 'reversed'];
const CHANNELS: readonly CashbackChannel[] = ['all', 'web', 'mobile'];

function isRecord(raw: unknown): raw is Record<string, unknown> {
  return typeof raw === 'object' && raw !== null && !Array.isArray(raw);
}

/** Normalise a decimal (string or number) to a 2dp string, or null when not numeric. */
export function toMoneyString(raw: unknown): string | null {
  if (typeof raw !== 'string' && typeof raw !== 'number') return null;
  if (typeof raw === 'string' && raw.trim() === '') return null;
  const n = Number(raw);
  if (!Number.isFinite(n)) return null;
  return n.toFixed(2);
}

/** Drop trailing zeros for display: "5.00" → "5", "2.50" → "2.5", "10.25" → "10.25". */
export function formatCashbackNumber(value: string | number): string {
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  return Number(n.toFixed(2)).toString();
}

function toPositiveInt(raw: unknown): number | null {
  const n = typeof raw === 'number' ? raw : typeof raw === 'string' && raw.trim() !== '' ? Number(raw) : NaN;
  return Number.isInteger(n) && n >= 0 ? n : null;
}

function toDateString(raw: unknown): string | null {
  if (typeof raw !== 'string' || raw.trim() === '') return null;
  return Number.isNaN(Date.parse(raw)) ? null : raw;
}

/** Parse product detail `cashback`; null when absent, malformed or worth nothing. */
export function parseProductCashback(raw: unknown): ProductCashbackBadge | null {
  if (!isRecord(raw)) return null;
  const type = raw['type'];
  if (type !== 'percentage' && type !== 'fixed') return null;
  const value = toMoneyString(raw['value']);
  if (value === null || Number(value) <= 0) return null;
  const max = toMoneyString(raw['max']);
  const min = toMoneyString(raw['min_eligible_amount']);
  const channelRaw = typeof raw['channel'] === 'string' ? raw['channel'].toLowerCase() : 'all';
  const channel = (CHANNELS as readonly string[]).includes(channelRaw) ? (channelRaw as CashbackChannel) : 'all';
  return {
    type,
    value,
    max: max !== null && Number(max) > 0 ? max : null,
    min_eligible_amount: min !== null && Number(min) > 0 ? min : null,
    channel,
    first_order_only: raw['first_order_only'] === true,
    allow_with_promo: raw['allow_with_promo'] !== false,
  };
}

/** The PDP badge as the web storefront should show it (mobile-only rules hidden). */
export function webProductCashback(raw: unknown): ProductCashbackBadge | null {
  const badge = parseProductCashback(raw);
  return badge !== null && badge.channel !== 'mobile' ? badge : null;
}

/** Parse GET /v3/cart/cashback `data`; null when the payload is unusable. */
export function parseCartCashback(raw: unknown): CartCashbackPreview | null {
  if (!isRecord(raw)) return null;
  const amount = toMoneyString(raw['estimated_amount']);
  if (amount === null) return null;
  const positive = Number(amount) > 0;
  const currency = typeof raw['currency'] === 'string' && raw['currency'] !== '' ? raw['currency'] : 'AED';
  const usesCredit = raw['uses_cashback_credit'] === true;
  const eligible = raw['eligible'] === true && positive && !usesCredit;
  const reason = typeof raw['reason'] === 'string' && raw['reason'] !== ''
    ? raw['reason']
    : usesCredit ? CASHBACK_REASON_PAID_WITH_CASHBACK : null;
  return {
    estimated_amount: amount,
    currency,
    eligible,
    reason: eligible ? null : reason,
    hold_days: eligible ? toPositiveInt(raw['hold_days']) : null,
    uses_cashback_credit: usesCredit,
    wallet_has_cashback_credit: raw['wallet_has_cashback_credit'] === true,
  };
}

/** Parse order detail `cashback`; null when the order has no award. */
export function parseOrderCashback(raw: unknown): OrderCashback | null {
  if (!isRecord(raw)) return null;
  const status = raw['status'];
  if (typeof status !== 'string' || !(AWARD_STATUSES as readonly string[]).includes(status)) return null;
  const amount = toMoneyString(raw['amount']);
  if (amount === null) return null;
  const cardId = toPositiveInt(raw['gift_card_id']);
  return {
    status: status as CashbackAwardStatus,
    amount,
    currency: typeof raw['currency'] === 'string' && raw['currency'] !== '' ? raw['currency'] : 'AED',
    release_at: toDateString(raw['release_at']),
    gift_card_id: cardId !== null && cardId > 0 ? cardId : null,
  };
}

/** True for a gift card paid out by the cashback programme. */
export function isCashbackGiftCard(card: { source?: unknown } | null | undefined): boolean {
  return card?.source === 'cashback';
}
