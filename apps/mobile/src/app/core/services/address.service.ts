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
  is_default_shipping?: boolean;
  is_default_billing?: boolean;
}

/**
 * AddressService — the customer's saved address book on v3.
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

  /** Create a new saved address; returns the created row (or null). */
  async create(token: string, address: NewAddress): Promise<SavedAddress | null> {
    const res: any = await firstValueFrom(
      this.adapter.post_v3('POST /me/addresses', address, { authToken: token }),
    );
    if ((res?.response_code === 201 || res?.response_code === 200) && res?.status === 'success') {
      return (res.data?.address ?? res.data) as SavedAddress;
    }
    return null;
  }

  /** Mark an address as the default (shipping + billing). */
  async setDefault(token: string, id: number): Promise<boolean> {
    const res: any = await firstValueFrom(
      this.adapter.patch_v3('PATCH /me/addresses/:id/default', {}, {
        authToken: token,
        pathParams: { id: String(id) },
      }),
    );
    return res?.response_code === 200 && res?.status === 'success';
  }
}
