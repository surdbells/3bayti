/**
 * Gift card domain types for the web storefront (M3.5 / Phase E).
 *
 * These mirror the API's GiftCardSerializer contract exactly:
 *   - GET  /v3/gift-cards/themes            → Record<theme, GiftCardThemeMeta>
 *   - GET  /v3/gift-cards/balance?code=     → { data: GiftCard }
 *   - GET  /v3/gift-cards/mine              → { data: GiftCard[] }
 *   - POST /v3/gift-cards/purchase          → { data: GiftCard }  (pending_payment)
 *   - POST /v3/gift-cards/redeem            → { data: GiftCard }
 *   - POST /v3/cart/gift-card               → { data: GiftCardCartPreview }
 *
 * `GIFT_CARD_THEMES` below is a client-side mirror of the server's
 * THEME_META so the themed card can render in *preview* (before a card
 * exists) without waiting on the network. The /themes endpoint returns
 * the same palette plus the denomination presets.
 */

export type GiftCardTheme =
  | 'birthday'
  | 'wedding'
  | 'eid'
  | 'mother'
  | 'graduation'
  | 'luxury';

/** Decorative motif each theme renders behind the amount. */
export type GiftCardPattern =
  | 'sunburst'
  | 'rings'
  | 'star'
  | 'bloom'
  | 'seal'
  | 'medallion';

/**
 * Lifecycle status (server-authoritative).
 *   pending_payment, created, awaiting Noon settlement (not spendable)
 *   active         , paid + activated (full balance available)
 *   partially_used , some balance spent
 *   exhausted      , balance == 0
 *   expired        , past expires_at
 *   voided         , cancelled / refunded
 */
export type GiftCardStatus =
  | 'pending_payment'
  | 'active'
  | 'partially_used'
  | 'exhausted'
  | 'expired'
  | 'voided';

/** Design + label metadata for a theme (matches the API THEME_META). */
export interface GiftCardThemeMeta {
  label: string;
  arabic_label: string;
  primary_color: string;
  accent_color: string;
  text_color: string;
  border_color: string;
  pattern: GiftCardPattern;
  supports_photo: boolean;
}

/** A theme option from GET /v3/gift-cards/themes (meta + denomination rules). */
export interface GiftCardThemeOption extends GiftCardThemeMeta {
  /** The theme key (the map key, lifted onto the object for *ngFor ergonomics). */
  theme: GiftCardTheme;
  presets: string[];
  min_denomination: string;
  max_denomination: string;
}

/** A ledger entry on a gift card (debits/credits). */
export interface GiftCardTransaction {
  id: number;
  type: string;
  amount: string;
  balance_after: string;
  order_reference: string | null;
  created_at: string;
}

/** A gift card as returned by the API. */
export interface GiftCard {
  id: number;
  code: string;
  theme: GiftCardTheme;
  theme_meta: GiftCardThemeMeta;
  denomination: string;
  balance: string;
  currency: string;
  status: GiftCardStatus;
  is_spendable: boolean;
  recipient_name: string | null;
  recipient_message: string | null;
  recipient_photo_url: string | null;
  scheduled_delivery_at: string | null;
  activated_at: string | null;
  expires_at: string | null;
  created_at: string;
  is_buyer: boolean;
  transactions?: GiftCardTransaction[];
}

/** Body for POST /v3/gift-cards/purchase. */
export interface GiftCardPurchaseInput {
  denomination: string;
  theme: GiftCardTheme;
  recipient_name?: string | null;
  recipient_message?: string | null;
  recipient_photo_url?: string | null;
  scheduled_delivery_at?: string | null;
  /**
   * Optional auto-delivery targets. When either is present the API
   * delivers the card automatically (email via ZeptoMail, SMS via
   * MessageCentral); when both are blank, nothing is sent and the
   * buyer shares the code themselves. Send blank values as null.
   */
  recipient_email?: string | null;
  recipient_phone?: string | null;
}

/** Result of POST /v3/cart/gift-card, how much of a card applies to the cart. */
export interface GiftCardCartPreview {
  code: string;
  balance: string;
  applicable: string;
  cart_total: string;
  remaining_due: string;
  currency: string;
}

/**
 * Result of GET /v3/cart/gift-wallet, how much of the customer's WHOLE gift
 * wallet (aggregate balance across all their spendable cards) covers the cart.
 * `cards_used` is the draw plan, soonest-expiry first.
 */
export interface GiftWalletCartPreview {
  wallet_balance: string;
  applied: string;
  gateway_amount: string;
  cart_total: string;
  fully_covered: boolean;
  currency: string;
  cards_used: Array<{ code: string; theme: string; amount: string }>;
}

/** Denomination bounds (mirror GiftCard::MIN/MAX_DENOMINATION). */
export const GIFT_CARD_MIN_DENOMINATION = 100;
export const GIFT_CARD_MAX_DENOMINATION = 10000;
export const GIFT_CARD_PRESETS = [100, 200, 500, 1000, 2000, 5000, 10000] as const;

/** Ordered list of themes for selector UIs. */
export const GIFT_CARD_THEME_ORDER: readonly GiftCardTheme[] = [
  'birthday',
  'eid',
  'wedding',
  'mother',
  'graduation',
  'luxury',
];

/**
 * Client-side mirror of the server THEME_META palette. Source of truth
 * for *preview* rendering; the live /themes payload carries the same
 * values (plus presets) for the selector.
 */
export const GIFT_CARD_THEMES: Record<GiftCardTheme, GiftCardThemeMeta> = {
  birthday: {
    label: 'Birthday',
    arabic_label: 'عيد ميلاد',
    primary_color: '#3A1A00',
    accent_color: '#E8C040',
    text_color: '#F5E060',
    border_color: '#E8C040',
    pattern: 'sunburst',
    supports_photo: false,
  },
  wedding: {
    label: 'Anniversary',
    arabic_label: 'ذكرى سنوية',
    primary_color: '#260014',
    accent_color: '#D4AF37',
    text_color: '#F5E060',
    border_color: '#D4AF37',
    pattern: 'rings',
    supports_photo: false,
  },
  eid: {
    label: 'Eid Mubarak',
    arabic_label: 'عيد مبارك',
    primary_color: '#002A12',
    accent_color: '#D4AF37',
    text_color: '#F5E060',
    border_color: '#2A8A50',
    pattern: 'star',
    supports_photo: false,
  },
  mother: {
    label: "Mother's Day",
    arabic_label: 'عيد الأم',
    primary_color: '#22001A',
    accent_color: '#D4AF37',
    text_color: '#F5E060',
    border_color: '#D4AF37',
    pattern: 'bloom',
    supports_photo: false,
  },
  graduation: {
    label: 'Graduation',
    arabic_label: 'التخرج',
    primary_color: '#000C26',
    accent_color: '#D4AF37',
    text_color: '#F5E060',
    border_color: '#1A3070',
    pattern: 'seal',
    supports_photo: false,
  },
  luxury: {
    label: 'Luxury Gift',
    arabic_label: 'هدية فاخرة',
    primary_color: '#1A1200',
    accent_color: '#E8C040',
    text_color: '#F0D060',
    border_color: '#E8C040',
    pattern: 'medallion',
    supports_photo: true,
  },
};
