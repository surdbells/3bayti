import { Injectable, Signal, signal, computed, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';
import type {
  Address,
  CreateAddressInput,
  UpdateAddressInput,
  SetDefaultInput,
} from './address.types';

/**
 * AddressService, auth-required CRUD against `/v3/me/addresses`.
 *
 * Public surface (signals):
 *   - addresses()         Signal<Address[]> , current list
 *   - defaultShipping()   computed Signal<Address|null>
 *   - defaultBilling()    computed Signal<Address|null>
 *   - isLoading()         Signal<boolean>
 *
 * Methods (all async):
 *   - list()       GET /v3/me/addresses                    → Address[]
 *   - create(input)  POST /v3/me/addresses                 → Address
 *   - update(id, input)  PUT /v3/me/addresses/:id          → Address
 *   - delete(id)   DELETE /v3/me/addresses/:id             → void
 *   - setDefault(id, input) PATCH .../default              → Address
 *
 * Local state policy
 * ------------------
 * After every mutation we refresh the `addresses()` signal with the
 * server response (single source of truth, the API authoritatively
 * decides defaults). We do NOT optimistically update before the
 * response lands; a 500 would leave the UI showing a phantom address.
 *
 * Bearer-bound calls
 * ------------------
 * /v3/me/addresses uses the access token in memory, attached by the
 * refresh.interceptor. Not a BFF cookie-bound route, so no extra
 * proxy. 401 retries are handled by the interceptor's single-flight
 * refresh.
 */

const V3_BASE = 'https://api-v3.3bayti.ae';

@Injectable({ providedIn: 'root' })
export class AddressService {
  private readonly _addresses = signal<Address[]>([]);
  private readonly _isLoading = signal<boolean>(false);

  readonly addresses: Signal<Address[]> = this._addresses.asReadonly();
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();

  readonly defaultShipping = computed<Address | null>(() => {
    return this._addresses().find(a => a.is_default_shipping) ?? null;
  });

  readonly defaultBilling = computed<Address | null>(() => {
    return this._addresses().find(a => a.is_default_billing) ?? null;
  });

  /** True if the user has at least one address. */
  readonly hasAny = computed(() => this._addresses().length > 0);

  private readonly http = inject(HttpClient);

  /**
   * Refresh the address list from the server. Called on first access
   * via list() and after every mutation.
   */
  async list(): Promise<Address[]> {
    return this.runWithLoading(async () => {
      const result = await firstValueFrom(
        this.http.get<{ addresses: Address[] }>(`${V3_BASE}/v3/me/addresses`),
      );
      /* The API returns { addresses: [...] } not a bare array, mirroring
         the list-response convention used elsewhere (orders, etc.). */
      const list = Array.isArray(result) ? (result as unknown as Address[]) : result.addresses;
      this._addresses.set(list);
      return list;
    });
  }

  async create(input: CreateAddressInput): Promise<Address> {
    return this.runWithLoading(async () => {
      const created = await firstValueFrom(
        this.http.post<Address>(`${V3_BASE}/v3/me/addresses`, input),
      );
      /* Re-list to pick up the canonical state (the create may have
         flipped another address's is_default_shipping when is_default=true). */
      await this.list();
      return created;
    });
  }

  async update(id: number, input: UpdateAddressInput): Promise<Address> {
    return this.runWithLoading(async () => {
      const updated = await firstValueFrom(
        this.http.put<Address>(`${V3_BASE}/v3/me/addresses/${id}`, input),
      );
      await this.list();
      return updated;
    });
  }

  async delete(id: number): Promise<void> {
    await this.runWithLoading(async () => {
      await firstValueFrom(
        this.http.delete<unknown>(`${V3_BASE}/v3/me/addresses/${id}`),
      );
      await this.list();
    });
  }

  async setDefault(id: number, input: SetDefaultInput): Promise<Address> {
    return this.runWithLoading(async () => {
      const updated = await firstValueFrom(
        this.http.patch<Address>(`${V3_BASE}/v3/me/addresses/${id}/default`, input),
      );
      await this.list();
      return updated;
    });
  }

  private async runWithLoading<T>(fn: () => Promise<T>): Promise<T> {
    this._isLoading.set(true);
    try {
      return await fn();
    } finally {
      this._isLoading.set(false);
    }
  }
}
