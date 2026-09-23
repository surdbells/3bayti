import { Injectable, signal, Signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type {
  CustomizationRequest,
  CustomizationRequestsPage,
  SubmitCustomizationInput,
} from './customization.model';

/** Page size for the "my customization requests" list (load-more). */
export const CUSTOMIZATION_PAGE_SIZE = 20;

/**
 * CustomizationService — the customer's side of P5 bespoke customization.
 *
 * Auth-gated reads/writes routed through RoutedHttpClient (the refresh
 * interceptor attaches the Bearer to every request), consistent with the
 * account/following services. Paying an accepted quote is NOT here — that
 * goes through CheckoutService.initiate({ customization_request_id }).
 */
@Injectable({ providedIn: 'root' })
export class CustomizationService {
  private readonly http = inject(RoutedHttpClient);

  private readonly _isSubmitting = signal<boolean>(false);
  private readonly _isLoading = signal<boolean>(false);
  private readonly _isActing = signal<boolean>(false);
  readonly isSubmitting: Signal<boolean> = this._isSubmitting.asReadonly();
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();
  readonly isActing: Signal<boolean> = this._isActing.asReadonly();

  /** Open a new customization request on a product (by slug). */
  async submit(input: SubmitCustomizationInput): Promise<CustomizationRequest> {
    this._isSubmitting.set(true);
    try {
      const env = await firstValueFrom(
        this.http.post<CustomizationRequest>('POST /me/customization-requests', { body: input }),
      );
      return env.data;
    } finally {
      this._isSubmitting.set(false);
    }
  }

  /** The customer's own requests, newest first. */
  async list(params: { offset?: number; limit?: number; status?: string } = {}): Promise<CustomizationRequestsPage> {
    const limit = params.limit ?? CUSTOMIZATION_PAGE_SIZE;
    const offset = params.offset ?? 0;
    const query: Record<string, string | number> = { limit, offset };
    if (params.status) query['status'] = params.status;

    this._isLoading.set(true);
    try {
      const env = await firstValueFrom(
        this.http.get<CustomizationRequest[]>('GET /me/customization-requests', { query }),
      );
      return {
        items: Array.isArray(env.data) ? env.data : [],
        hasMore: env.meta?.has_more ?? false,
        total: env.meta?.total ?? 0,
      };
    } finally {
      this._isLoading.set(false);
    }
  }

  /** Fetch a single request. */
  async get(id: number): Promise<CustomizationRequest> {
    const env = await firstValueFrom(
      this.http.get<CustomizationRequest>('GET /me/customization-requests/:id', {
        params: { id: String(id) },
      }),
    );
    return env.data;
  }

  /** Accept the vendor's quote (→ 'accepted'; pay next via checkout). */
  async accept(id: number): Promise<CustomizationRequest> {
    return this.act(id, 'POST /me/customization-requests/:id/accept');
  }

  /** Reject the vendor's quote (terminal). */
  async reject(id: number): Promise<CustomizationRequest> {
    return this.act(id, 'POST /me/customization-requests/:id/reject');
  }

  /** Withdraw the request before paying (terminal). */
  async cancel(id: number): Promise<CustomizationRequest> {
    return this.act(id, 'POST /me/customization-requests/:id/cancel');
  }

  private async act(id: number, routeKey: string): Promise<CustomizationRequest> {
    this._isActing.set(true);
    try {
      const env = await firstValueFrom(
        this.http.post<CustomizationRequest>(routeKey, { params: { id: String(id) } }),
      );
      return env.data;
    } finally {
      this._isActing.set(false);
    }
  }
}
