import { firstValueFrom } from 'rxjs';
import type { AxFilterOption } from '../../shared/data/enterprise';
import type { PortalCrudAdapter } from '../../services/portal-crud-adapter';

/**
 * Canonical Order status values (mirror of Order::STATUS_* on the API) with
 * friendly labels, used to populate the Status filter on the admin order and
 * sales tables. Keeping a single source here avoids the two tables drifting
 * apart (the previous processing filter used non-existent statuses like
 * "accepted"/"preparing" that never matched).
 */
export const ORDER_STATUS_OPTIONS: readonly AxFilterOption[] = [
  { label: 'Pending payment', value: 'pending_payment' },
  { label: 'Paid', value: 'paid' },
  { label: 'Fulfilling', value: 'fulfilling' },
  { label: 'Shipped', value: 'shipped' },
  { label: 'Delivered', value: 'delivered' },
  { label: 'Cancelled', value: 'cancelled' },
  { label: 'Refunded', value: 'refunded' },
  { label: 'Failed', value: 'failed' },
];

/**
 * Fulfilment status set for the logistics / delivery board — the
 * SHIPPED->DELIVERED range (in-progress + completed shipments). The API
 * (Order::STATUS_*) has no `out_for_delivery` status, so the set is just
 * shipped + delivered. Pre-shipment (pending_payment/paid/fulfilling) and
 * terminal (cancelled/refunded/failed) orders are intentionally excluded so
 * they never appear on the delivery board.
 */
export const FULFILMENT_STATUSES: readonly string[] = ['shipped', 'delivered'];

/**
 * Status filter options restricted to the fulfilment set, for the logistics
 * Status dropdown. A subset of ORDER_STATUS_OPTIONS kept in lock-step via
 * FULFILMENT_STATUSES so the two never drift.
 */
export const LOGISTICS_STATUS_OPTIONS: readonly AxFilterOption[] =
  ORDER_STATUS_OPTIONS.filter((o) => FULFILMENT_STATUSES.includes(String(o.value)));

/**
 * Statuses that count as a SALE — everything EXCEPT pending_payment. An order
 * still awaiting payment isn't a completed sale, so it must never appear on the
 * admin/sales report (or its Store-sales drill-downs). Sent as the default
 * `status` filter so the sales tables exclude unpaid orders.
 */
export const SALES_STATUSES: readonly string[] = ORDER_STATUS_OPTIONS
  .map((o) => String(o.value))
  .filter((v) => v !== 'pending_payment');

/**
 * Status dropdown for the sales tables — ORDER_STATUS_OPTIONS minus
 * pending_payment, so an admin can't filter the sales report to unpaid orders.
 */
export const SALES_STATUS_OPTIONS: readonly AxFilterOption[] =
  ORDER_STATUS_OPTIONS.filter((o) => o.value !== 'pending_payment');

/**
 * Async option provider for a "Store" (vendor) filter — fetches the admin
 * vendor list and maps it to {label,value}. Degrades to an empty list on
 * failure so the rest of the table stays usable.
 *
 * `valueKey` picks which identifier the filter value carries, because the
 * two backends differ: the admin orders/sales endpoint (`GET /admin/orders`)
 * filters by the v3 `vendor_id`, while the public catalog endpoint
 * (`GET /products`, used by admin products) resolves a store by `vendor`
 * SLUG. Pass 'slug' for the products table, 'id' (default) elsewhere.
 */
export function loadAdminVendorOptions(
  adapter: PortalCrudAdapter,
  valueKey: 'id' | 'slug' = 'id',
): Promise<readonly AxFilterOption[]> {
  return firstValueFrom(adapter.get_v3('GET /admin/vendors', { query: { limit: 200 } }))
    .then((res: any) => {
      const vendors: any[] = res?.vendors ?? res?.data ?? [];
      return vendors
        .map((v) => ({
          label: v.name ?? v.slug ?? `Vendor ${v.id}`,
          value: valueKey === 'slug' ? String(v.slug ?? '') : String(v.id),
        }))
        .filter((o) => o.value !== '')
        .sort((a, b) => a.label.localeCompare(b.label));
    })
    .catch(() => []);
}

/**
 * Sentinel filter value for the "Gift Card" entry in the Product filter.
 * The merged Orders & Sales table maps it to the API `type=gift_card` filter
 * (gift-card sales carry no real product_id), while a numeric value maps to
 * `product_id`.
 */
export const GIFT_CARD_FILTER_VALUE = 'gift_card';

/**
 * Async option provider for the "Product" filter on the Orders & Sales table.
 * Fetches the admin product catalogue and maps it to {label,value:id}, with a
 * leading "Gift Card" pseudo-option so an admin can narrow to either a specific
 * product OR gift-card sales from one control. Degrades to just the Gift Card
 * option on failure so the filter stays usable.
 */
export function loadAdminProductOptions(
  adapter: PortalCrudAdapter,
): Promise<readonly AxFilterOption[]> {
  const giftCard: AxFilterOption = { label: 'Gift Card', value: GIFT_CARD_FILTER_VALUE };
  return firstValueFrom(adapter.get_v3('GET /admin/products', { query: { limit: 200 } }))
    .then((res: any) => {
      const products: any[] = res?.products ?? res?.data ?? res?.items ?? [];
      const options = products
        .map((p) => ({
          label: p.name ?? p.title ?? `Product ${p.id}`,
          value: String(p.id),
        }))
        .filter((o) => o.value !== '' && o.value !== 'undefined')
        .sort((a, b) => a.label.localeCompare(b.label));
      return [giftCard, ...options];
    })
    .catch(() => [giftCard]);
}

/** Humanise a raw status value: 'pending_payment' -> 'Pending Payment'. */
export function prettyOrderStatus(value: unknown): string {
  return String(value ?? '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}
