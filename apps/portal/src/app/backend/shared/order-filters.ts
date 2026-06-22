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
 * Async option provider for a "Store" (vendor) filter — fetches the admin
 * vendor list and maps it to {label,value}. Degrades to an empty list on
 * failure so the rest of the table stays usable.
 */
export function loadAdminVendorOptions(
  adapter: PortalCrudAdapter,
): Promise<readonly AxFilterOption[]> {
  return firstValueFrom(adapter.get_v3('GET /admin/vendors', { query: { limit: 200 } }))
    .then((res: any) => {
      const vendors: any[] = res?.vendors ?? res?.data ?? [];
      return vendors.map((v) => ({
        label: v.name ?? v.slug ?? `Vendor ${v.id}`,
        value: String(v.id),
      }));
    })
    .catch(() => []);
}

/** Humanise a raw status value: 'pending_payment' -> 'Pending Payment'. */
export function prettyOrderStatus(value: unknown): string {
  return String(value ?? '')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
}
