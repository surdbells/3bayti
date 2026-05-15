import { Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { NetworkService } from '../../service/network.service';
import { GlobalComponent } from '../../global-component';
import { LocalCartService } from './local-cart.service';

/**
 * Merge result returned to callers of mergeIfAny.
 */
export type MergeResult = {
  /** True if any merge was attempted (false if local cart was empty). */
  attempted: boolean;

  /** True if the server accepted the merge (200 status). */
  success: boolean;

  /**
   * Items that the server couldn't merge (e.g. product no longer
   * available, or deleted by admin). Empty for full success.
   */
  skipped: Array<{ product_id?: number; reason?: string }>;

  /** Error object on failure; null on success. */
  error: unknown;
};

/**
 * Service to merge the device-local guest cart into the server-side
 * cart immediately after a successful sign-in.
 *
 * Flow
 * ====
 *   1. After login completes (token stored in Preferences), but
 *      BEFORE navigating away from /login, call mergeIfAny().
 *   2. mergeIfAny() reads the local cart. If empty: no-op, returns
 *      { attempted: false }.
 *   3. If non-empty, POSTs items[] to /v3/cart/merge (routed via
 *      the existing networkService → MobileNetworkAdapter →
 *      transformMergeCartRequest).
 *   4. On success: clear the local cart. Return skipped[] for the
 *      UI to surface.
 *   5. On failure: leave the local cart intact. The user can try
 *      again on next sign-in or by manually re-adding.
 *
 * Why don't we clear the cart on failure
 * =======================================
 * Trade-off between data loss and double-merging. Leaving the cart
 * means a retry sends the same items again; v3's variant-aware
 * merge handles this idempotently. Clearing on failure means the
 * user permanently loses their cart on a transient network blip —
 * the worse failure mode for a conversion-critical surface.
 *
 * Why a separate service from LocalCartService
 * =============================================
 * Separation of concerns. LocalCartService owns local storage; it
 * has no business knowing about networking or backend semantics.
 * CartMergeService orchestrates: read local → POST server → clear
 * local. Keeping them separate also makes testing easier — mock
 * either side independently.
 */
@Injectable({ providedIn: 'root' })
export class CartMergeService {
  constructor(
    private readonly networkService: NetworkService,
    private readonly localCart: LocalCartService,
  ) {}

  /**
   * Merge the local cart into the server cart. Safe to call when
   * the local cart is empty (returns { attempted: false }).
   *
   * Should be called AFTER the auth token has been stored in
   * Preferences, so the adapter's translateRequestBody can pick
   * up the token from the body (legacy convention) — caller must
   * pass the token in the body via { id, token } as legacy mobile
   * does throughout. Adapter strips and converts to Bearer header.
   *
   * Returns a MergeResult; never throws (errors are captured in
   * .error). Callers should check .success and surface .skipped[]
   * if non-empty.
   */
  async mergeIfAny(authBody: { id: number; token: string }): Promise<MergeResult> {
    let localItems: Awaited<ReturnType<LocalCartService['list']>>;
    try {
      localItems = await this.localCart.list();
    } catch (err) {
      // Storage error reading the local cart. Treat as 'no local
      // cart to merge' — don't fail the sign-in.
      console.warn('[CartMerge] failed to read local cart', err);
      return { attempted: false, success: false, skipped: [], error: err };
    }

    if (localItems.length === 0) {
      return { attempted: false, success: true, skipped: [], error: null };
    }

    // Build the legacy-shaped body the adapter expects. The transform
    // (transformMergeCartRequest) will strip non-v3 fields.
    const body = {
      id: authBody.id,
      token: authBody.token,
      items: localItems.map((it) => ({
        product_id: it.product_id,
        quantity: it.quantity,
        size: it.size,
        color: it.color,
        is_custom: it.is_custom,
        measurement: it.measurement,
        extra_measurement: it.extra_measurement,
        note: it.note,
      })),
    };

    const mergeUrl = `${GlobalComponent.baseURL}v3/cart/merge`;

    try {
      // post_request returns Observable<any>; firstValueFrom turns
      // it into a promise that resolves on the first emission.
      const response: any = await firstValueFrom(
        this.networkService.post_request(body, mergeUrl),
      );

      // Detect success across both shapes (defensive — Phase F flip
      // should make this always v3, but until then leave the legacy
      // detection in place).
      const success =
        response &&
        ((response.response_code === 200 && response.status === 'success') ||
          (response.data && response.data.success === true));

      if (!success) {
        return {
          attempted: true,
          success: false,
          skipped: [],
          error: new Error('Merge response indicated failure'),
        };
      }

      // Extract skipped[] from the transform's data shape.
      const skipped =
        response.data && Array.isArray(response.data.skipped)
          ? response.data.skipped
          : [];

      // Server accepted — clear the local cart so we don't re-merge
      // on the next sign-in. Clear failure here is non-fatal (the
      // server-side cart is already correct; local will just be a
      // ghost that next sign-in re-merges, which is idempotent
      // server-side).
      try {
        await this.localCart.clear();
      } catch (err) {
        console.warn('[CartMerge] local cart clear failed (non-fatal)', err);
      }

      return {
        attempted: true,
        success: true,
        skipped,
        error: null,
      };
    } catch (err) {
      // Network or HTTP error. Local cart is INTACT — user can retry
      // on next sign-in.
      return {
        attempted: true,
        success: false,
        skipped: [],
        error: err,
      };
    }
  }
}
