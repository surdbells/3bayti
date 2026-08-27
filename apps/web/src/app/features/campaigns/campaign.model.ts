import type { Product, Money } from '../catalog/product.model';

/**
 * Campaign types, match the v3 CampaignSerializer output
 * (GET /v3/campaigns/active and GET /v3/campaigns/:slug).
 *
 * Pricing is derived server-side at read time: campaign_price is the
 * product's live (display-currency-aware) price with the effective
 * discount applied, or null when the discount is 0. Stock fields are
 * present only for flash-sale scarcity bars.
 */
export interface CampaignItem {
  /** Same shape ProductCard renders (ProductSerializer listShape). */
  product: Product;
  /** Effective discount (item override ?? campaign default). */
  discount_percent: number;
  /** Discounted price, or null when discount is 0. */
  campaign_price: Money | null;
  /** Total stock allocated to the campaign (null = untracked). */
  stock_total: number | null;
  /** Stock remaining (drives flash-sale bars; null = untracked). */
  stock_remaining: number | null;
}

export type CampaignType = 'anniversary' | 'flash';

export interface Campaign {
  id: number;
  slug: string;
  type: CampaignType;
  title: string;
  subtitle: string | null;
  /** Campaign-wide default discount percent. */
  discount_percent: number;
  starts_at: string;
  /** ISO 8601, the countdown target. */
  ends_at: string;
  items: CampaignItem[];
}

/**
 * The active-campaigns payload. `server_now` is the server clock at
 * response time, countdowns compute their offset against it so a skewed
 * device clock doesn't misreport the time remaining. Either campaign may
 * be null (none live of that type).
 */
export interface ActiveCampaigns {
  server_now: string;
  anniversary: Campaign | null;
  flash: Campaign | null;
}
