import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { MobileNetworkAdapter } from '../http/mobile-network-adapter';

/**
 * A saved address from the v3 address book.
 * Mirrors AddressSerializer::publicShape exactly.
 */
export interface SavedAddress {
  id: number;
  label: string | null;
  recipient_name: string | null;
  recipient_phone: string | null;
  emirate: string | null;
  area: string | null;
  street_address: string | null;
  building_details: string | null;
  postal_code: string | null;
  country: string | null;
  is_default: boolean;
  is_default_shipping: boolean;
  is_default_billing: boolean;
}

/** Payload to create a new saved address. */
export interface NewAddress {
  label?: string | null;
  recipient_name: string;
  recipient_phone: string;
  emirate: string;
  area: string;
  street_address: string;
  building_details?: string | null;
  postal_code?: string | null;
  country?: string;
  /**
   * When true, make this the user's default (shipping + billing) address.
   *
   * IMPORTANT: the v3 CreateAddressInput DTO accepts a SINGLE `is_default`
   * field, it has NO is_default_shipping/is_default_billing params, and
   * RequestValidator silently drops unknown keys. Sending the split flags
   * (the old shape) meant the create dropped them and the address was
   * never promoted. Keep this as the single `is_default` the API expects.
   */
  is_default?: boolean;
}

/**
 * AddressService, the customer's saved address book on v3.
 *
 * Wraps the /v3/me/addresses endpoints (routed by the adapter via
 * @3bayti/api-client route-keys). Used by the checkout saved-address
 * picker (Z.2) and the standalone addresses page.
 *
 * Endpoints:
 *   GET    /me/addresses              → list
 *   POST   /me/addresses              → create
 *   GET    /me/addresses/:id          → one
 *   PUT    /me/addresses/:id          → update
 *   DELETE /me/addresses/:id          → remove
 *   PATCH  /me/addresses/:id/default  → set default
 */
@Injectable({ providedIn: 'root' })
export class AddressService {
  constructor(private adapter: MobileNetworkAdapter) {}

  /** List the user's saved addresses. */
  async list(token: string): Promise<SavedAddress[]> {
    const res: any = await firstValueFrom(
      this.adapter.get_v3('GET /me/addresses', { authToken: token }),
    );
    if (res?.response_code === 200 && res?.status === 'success') {
      const data = res.data;
      // v3 list endpoints return either a bare array or { items: [] }.
      if (Array.isArray(data)) return data as SavedAddress[];
      if (Array.isArray(data?.items)) return data.items as SavedAddress[];
      if (Array.isArray(data?.addresses)) return data.addresses as SavedAddress[];
    }
    return [];
  }

  /** Create a new saved address; throws on failure (see error()). */
  async create(token: string, address: NewAddress): Promise<SavedAddress> {
    const res: any = await firstValueFrom(
      this.adapter.post_v3('POST /me/addresses', address, { authToken: token }),
    );
    if ((res?.response_code === 201 || res?.response_code === 200) && res?.status === 'success') {
      return (res.data?.address ?? res.data) as SavedAddress;
    }
    throw this.error(res);
  }

  /**
   * Update an existing saved address (PUT /me/addresses/:id). Partial
   * payloads are accepted; only the fields present are applied server-side.
   * Throws on failure (see error()).
   */
  async update(token: string, id: number, address: Partial<NewAddress>): Promise<SavedAddress> {
    const res: any = await firstValueFrom(
      this.adapter.put_v3('PUT /me/addresses/:id', address, {
        authToken: token,
        pathParams: { id: String(id) },
      }),
    );
    if (res?.response_code === 200 && res?.status === 'success') {
      return (res.data?.address ?? res.data) as SavedAddress;
    }
    throw this.error(res);
  }

  /**
   * Re-throw an API failure that the mobile adapter surfaced on its SUCCESS
   * channel (`{ response_code, status:'error', message, error_code,
   * error_details }`) as an HttpErrorResponse-shaped Error, so callers can show
   * the real field-level reason via apiErrorMessage() instead of returning a
   * silent null that collapsed to a generic "unable to save" toast.
   */
  private error(res: any): Error {
    const e = new Error(res?.message || 'Request failed') as Error & {
      status?: number;
      error?: unknown;
    };
    e.status = res?.response_code ?? 0;
    e.error = { error: { message: res?.message, code: res?.error_code, details: res?.error_details } };
    return e;
  }

  /** Delete a saved address (DELETE /me/addresses/:id). */
  async remove(token: string, id: number): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.delete_v3('DELETE /me/addresses/:id', {
        authToken: token,
        pathParams: { id: String(id) },
      }),
    );
    // v3 delete may return 200 (with envelope) or 204 (no content).
    return res?.response_code === 200 || res?.response_code === 204
      || res?.status === 'success';
  }

  /**
   * Mark an address as the default for BOTH shipping and billing.
   *
   * IMPORTANT: the v3 PATCH /me/addresses/:id/default endpoint REQUIRES a
   * body of `{ shipping?: bool, billing?: bool }` and has an Assert\Callback
   * that REJECTS an empty/all-null body with 422. Sending `{}` (the old
   * shape) made this call a guaranteed no-op/422, the standalone addresses
   * page could never change which address was the default, and a user whose
   * default address was incomplete had no way to promote a good one, so
   * checkout (incl. gift-card payment) kept reading the bad/no default and
   * failing. Send both role flags so the promotion actually takes effect.
   */
  async setDefault(token: string, id: number): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.patch_v3('PATCH /me/addresses/:id/default', { shipping: true, billing: true }, {
        authToken: token,
        pathParams: { id: String(id) },
      }),
    );
    return res?.response_code === 200 && res?.status === 'success';
  }
}
